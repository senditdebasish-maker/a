<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Services\AuditService;
use Dompdf\Dompdf;
use Dompdf\Options;

final class GeneratedDocumentController extends Controller
{
    private const LABELS = [
        'application' => 'Application Summary',
        'cover-sheet' => 'Application Cover Sheet',
        'acknowledgement' => 'Submission Acknowledgement',
        'correction-memo' => 'Correction Memo',
        'offer-letter' => 'Provisional Offer Letter',
        'admission-letter' => 'Admission Confirmation',
    ];

    public function student(string $kind): void
    {
        $application = Database::get()->fetch('SELECT id FROM applications WHERE user_id = :user ORDER BY created_at DESC LIMIT 1', ['user' => Auth::id()]);
        if (!$application) {
            http_response_code(404); echo 'Application not found.'; return;
        }
        $this->renderDocument((int) $application['id'], $kind, false);
    }

    public function admin(string $id, string $kind): void
    {
        $this->renderDocument((int) $id, $kind, true);
    }

    public function receipt(string $id): void
    {
        $db = Database::get();
        $payment = $db->fetch("SELECT p.*, a.user_id AS applicant_user_id, a.application_number, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name
            FROM payments p JOIN applications a ON a.id = p.application_id JOIN users u ON u.id = a.user_id WHERE p.id = :id", ['id' => (int) $id]);
        if (!$payment || ($payment['status'] !== 'verified') || (!Auth::can('payments.view') && (int) $payment['applicant_user_id'] !== Auth::id())) {
            http_response_code(403); echo 'Receipt is not available.'; return;
        }
        $document = [
            'kind' => 'receipt', 'label' => 'Payment Receipt', 'number' => $payment['receipt_number'],
            'title' => 'Payment received and verified', 'message' => 'This receipt confirms verification of the payment record shown below.',
        ];
        $this->output($document, ['payment' => $payment, 'application' => ['application_number' => $payment['application_number']], 'profile' => ['full_name' => $payment['applicant_name']], 'program' => null, 'statusEvent' => null]);
    }

    private function renderDocument(int $applicationId, string $kind, bool $admin): void
    {
        if (!isset(self::LABELS[$kind])) {
            http_response_code(404); echo 'Unknown document type.'; return;
        }
        $db = Database::get();
        $application = $db->fetch("SELECT a.*, ac.name AS cycle_name, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email, u.mobile
            FROM applications a JOIN admission_cycles ac ON ac.id = a.admission_cycle_id JOIN users u ON u.id = a.user_id WHERE a.id = :id", ['id' => $applicationId]);
        if (!$application || (!$admin && (int) $application['user_id'] !== Auth::id())) {
            http_response_code(403); echo 'Access denied.'; return;
        }
        if (!$admin && !$this->isAvailable($application, $kind)) {
            http_response_code(403); echo 'This document is not available at the current application stage.'; return;
        }
        $profile = $db->fetch('SELECT * FROM applicant_profiles WHERE user_id = :user', ['user' => $application['user_id']]) ?: [];
        $profile['full_name'] = $application['applicant_name'];
        $program = $db->fetch('SELECT p.name, p.code FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id JOIN programs p ON p.id = cp.program_id WHERE pref.application_id = :id ORDER BY pref.preference_order LIMIT 1', ['id' => $applicationId]);
        $statusEvent = $db->fetch('SELECT * FROM application_status_history WHERE application_id = :id AND to_status = :status ORDER BY created_at DESC LIMIT 1', [
            'id' => $applicationId,
            'status' => $kind === 'correction-memo' ? 'correction_required' : ($kind === 'offer-letter' ? 'selected' : ($kind === 'admission-letter' ? 'admitted' : 'submitted')),
        ]);
        $number = match ($kind) {
            'cover-sheet' => 'COV-' . ($application['application_number'] ?: 'DRAFT-' . $application['id']),
            'acknowledgement' => 'ACK-' . $application['application_number'],
            'correction-memo' => 'COR-' . $application['application_number'],
            'offer-letter' => 'OFF-' . $application['application_number'],
            'admission-letter' => 'ADM-' . $application['application_number'],
            default => $application['application_number'] ?: 'DRAFT',
        };
        $document = [
            'kind' => $kind, 'label' => self::LABELS[$kind], 'number' => $number,
            'title' => match ($kind) {
                'application' => 'Applicant record summary',
                'cover-sheet' => 'Application document cover sheet',
                'acknowledgement' => 'Your application has been received',
                'correction-memo' => 'Action is required on your application',
                'offer-letter' => 'Provisional admission offer',
                'admission-letter' => 'Admission confirmed',
            },
            'message' => match ($kind) {
                'application' => 'This summary presents key information from the online application and remains subject to document verification.',
                'cover-sheet' => 'Place this cover sheet first when the Admissions Office asks for a printed or original-document set.',
                'acknowledgement' => 'The Admissions Office acknowledges receipt of your online application for the admission cycle shown below.',
                'correction-memo' => $statusEvent['remarks'] ?? 'Please review the correction request in your applicant dashboard.',
                'offer-letter' => 'You have been provisionally selected, subject to fee payment, original-document verification and the institution’s final rules.',
                'admission-letter' => 'Your admission has been confirmed in the programme shown below, subject to all continuing institutional and university requirements.',
            },
        ];
        AuditService::log('generated_document_viewed', 'application', $applicationId, [], ['kind' => $kind]);
        $this->output($document, compact('application', 'profile', 'program', 'statusEvent') + ['payment' => null]);
    }

    private function output(array $document, array $data): void
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require BASE_PATH . '/resources/views/documents/generated.php';
        $html = (string) ob_get_clean();

        if (($_GET['format'] ?? '') === 'pdf' && class_exists(Dompdf::class)) {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $pdf = new Dompdf($options);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();
            $pdf->stream(strtolower(str_replace(' ', '-', $document['label'])) . '-' . $document['number'] . '.pdf', ['Attachment' => true]);
            return;
        }
        echo $html;
    }

    private function isAvailable(array $application, string $kind): bool
    {
        return match ($kind) {
            'application', 'cover-sheet' => true,
            'acknowledgement' => $application['submitted_at'] !== null,
            'correction-memo' => $application['status'] === 'correction_required',
            'offer-letter' => in_array($application['status'], ['selected','fee_verified','admitted'], true),
            'admission-letter' => $application['status'] === 'admitted',
            default => false,
        };
    }
}
