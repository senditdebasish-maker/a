<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/** Writes private portal notifications and a retryable admissions-email outbox. */
final class AdmissionNotificationService
{
    private const MILESTONES = [
        'submitted' => ['application_received', 'Application received', 'Your application has been received and assigned application number {number}.'],
        'under_review' => ['application_under_review', 'Application under review', 'Admissions has started reviewing application {number}.'],
        'correction_required' => ['correction_requested', 'Correction requested', 'Admissions needs a correction for application {number}. Open your application for the requested items and deadline.'],
        'approved' => ['application_verified', 'Application verified for merit', 'Application {number} passed the verification gate and can enter the next merit run.'],
        'verified' => ['application_verified', 'Application verified for merit', 'Application {number} passed the verification gate and can enter the next merit run.'],
        'waitlisted' => ['application_waitlisted', 'Waitlisted', 'Application {number} is on the published merit list and remains eligible for selection.'],
        'selected' => ['selection_offer', 'Selected — admission fee due', 'Application {number} has been selected. Pay the admission fee before {deadline} to retain the offered seat.'],
        'payment_pending' => ['payment_received', 'Payment received', 'Payment evidence for application {number} was received and is awaiting verification.'],
        'fee_verified' => ['payment_verified', 'Payment verified', 'The admission fee for application {number} has been verified.'],
        'offer_expired' => ['offer_expired', 'Selection offer expired', 'The payment deadline for application {number} passed. The offer has expired and its seat was released.'],
        'admitted' => ['admission_confirmed', 'Admission confirmed', 'Admission is confirmed for application {number}. Open your portal for the admission letter and enrollment details.'],
        'not_selected' => ['not_selected', 'Admission result', 'Application {number} was not selected in this published merit round.'],
    ];

    public function status(int $applicationId, string $status, array $context = [], bool $sendNow = true): void
    {
        if (!isset(self::MILESTONES[$status])) return;
        [$type, $title, $message] = self::MILESTONES[$status];
        $this->deliver($applicationId, $type, $title, $this->interpolate($message, $context), $sendNow);
    }

    public function meritPublished(int $applicationId, string $summary): void
    {
        $this->deliver(
            $applicationId,
            'merit_published',
            'Merit result published',
            'A merit result has been published for your application. '.$summary.' Open your private result for complete details.',
            false
        );
    }

    public function processQueued(int $limit = 100): array
    {
        $db = Database::get();
        $db->query("UPDATE admission_notification_outbox SET status='retry',available_at=NOW(),updated_at=NOW() WHERE status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE) AND attempts<5");
        $limit = max(1, min(500, $limit));
        $ids = array_map('intval', array_column($db->all(
            "SELECT id FROM admission_notification_outbox WHERE status IN ('queued','retry') AND available_at<=NOW() AND attempts<5 ORDER BY id LIMIT {$limit}"
        ), 'id'));
        $sent = 0; $failed = 0;
        foreach ($ids as $id) {
            $mail = $db->transaction(function (Database $db) use ($id): ?array {
                $row = $db->fetch("SELECT * FROM admission_notification_outbox WHERE id=:id AND status IN ('queued','retry') AND available_at<=NOW() FOR UPDATE", ['id'=>$id]);
                if (!$row) return null;
                $db->update('admission_notification_outbox', ['status'=>'processing','attempts'=>(int)$row['attempts']+1,'updated_at'=>date('Y-m-d H:i:s')], 'id=:id', ['id'=>$id]);
                return $row;
            });
            if (!$mail) continue;
            $ok = false;
            try { $ok = (new MailService())->send((string)$mail['recipient'], (string)$mail['subject'], (string)$mail['body_html'], (string)$mail['template_key']); }
            catch (Throwable) { $ok = false; }
            if ($ok) {
                $db->update('admission_notification_outbox', ['status'=>'sent','sent_at'=>date('Y-m-d H:i:s'),'last_error'=>null,'updated_at'=>date('Y-m-d H:i:s')], 'id=:id', ['id'=>$id]);
                $sent++;
            } else {
                $attempts = (int)$mail['attempts'] + 1;
                $db->update('admission_notification_outbox', [
                    'status'=>$attempts>=5?'failed':'retry',
                    'available_at'=>date('Y-m-d H:i:s', time()+min(3600, 60*(2 ** min(5,$attempts)))),
                    'last_error'=>'Delivery failed; review the corresponding mail log for transport diagnostics.',
                    'updated_at'=>date('Y-m-d H:i:s'),
                ], 'id=:id', ['id'=>$id]);
                $failed++;
            }
        }
        return ['processed'=>count($ids),'sent'=>$sent,'failed'=>$failed];
    }

    private function deliver(int $applicationId, string $type, string $title, string $message, bool $sendNow): void
    {
        try {
            $db = Database::get();
            $application = $db->fetch(
                'SELECT a.id,a.application_number,a.user_id,CONCAT(u.first_name,' ',u.last_name) AS name,u.email FROM applications a JOIN users u ON u.id=a.user_id WHERE a.id=:id',
                ['id' => $applicationId]
            );
            if (!$application) return;
            $message = str_replace('{number}', (string)($application['application_number'] ?: '#'.$applicationId), $message);
            $path = $type === 'merit_published' || $type === 'application_waitlisted' ? 'student/merit' : 'student/dashboard';
            $db->insert('notifications', [
                'user_id' => $application['user_id'], 'type' => $type, 'title' => $title, 'message' => $message,
                'action_url' => url($path), 'read_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $safeName = htmlspecialchars((string)$application['name'], ENT_QUOTES, 'UTF-8');
            $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            $link = htmlspecialchars(url($path), ENT_QUOTES, 'UTF-8');
            $outboxId = $db->insert('admission_notification_outbox', [
                'application_id'=>$applicationId,
                'recipient'=>(string)$application['email'],
                'subject'=>$title.' · '.($application['application_number'] ?: 'Admission application'),
                'body_html'=>"<p>Dear {$safeName},</p><h2>{$safeTitle}</h2><p>{$safeMessage}</p><p><a href=\"{$link}\">Open admissions portal</a></p><p>Netaji College of Pharmacy</p>",
                'template_key'=>$type,
                'status'=>'queued', 'attempts'=>0, 'available_at'=>date('Y-m-d H:i:s'), 'sent_at'=>null, 'last_error'=>null,
                'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s'),
            ]);
            if ($sendNow) $this->processSpecific($outboxId);
        } catch (Throwable $exception) {
            error_log('Admission notification persistence failed for application '.$applicationId.': '.get_class($exception));
        }
    }

    private function processSpecific(int $outboxId): void
    {
        $db = Database::get();
        $mail = $db->fetch("SELECT * FROM admission_notification_outbox WHERE id=:id AND status='queued'", ['id'=>$outboxId]);
        if (!$mail) return;
        $db->update('admission_notification_outbox', ['status'=>'processing','attempts'=>1,'updated_at'=>date('Y-m-d H:i:s')], 'id=:id', ['id'=>$outboxId]);
        try { $ok = (new MailService())->send((string)$mail['recipient'], (string)$mail['subject'], (string)$mail['body_html'], (string)$mail['template_key']); }
        catch (Throwable) { $ok = false; }
        $db->update('admission_notification_outbox', [
            'status'=>$ok?'sent':'retry', 'sent_at'=>$ok?date('Y-m-d H:i:s'):null,
            'available_at'=>$ok?$mail['available_at']:date('Y-m-d H:i:s', time()+120),
            'last_error'=>$ok?null:'Delivery failed; review the corresponding mail log for transport diagnostics.',
            'updated_at'=>date('Y-m-d H:i:s'),
        ], 'id=:id', ['id'=>$outboxId]);
    }

    private function interpolate(string $message, array $context): string
    {
        $deadline = isset($context['deadline']) && (string)$context['deadline'] !== ''
            ? date('d M Y, h:i A', strtotime((string)$context['deadline']))
            : 'the deadline shown in your portal';
        return str_replace('{deadline}', $deadline, $message);
    }
}
