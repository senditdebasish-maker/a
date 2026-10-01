<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class MailService
{
    private const SENSITIVE_TEMPLATES = ['verify_email', 'password_reset', 'staff_mfa'];

    public function send(string $to, string $subject, string $html, ?string $template = null): bool
    {
        $config = config('mail');
        $driver = (string) ($config['driver'] ?? 'log');
        $sensitive = in_array((string) $template, self::SENSITIVE_TEMPLATES, true);
        $status = $driver === 'log' ? ($sensitive ? 'suppressed' : 'logged') : 'queued';
        $error = null;
        try {
            if ($driver === 'smtp') {
                if (!class_exists(PHPMailer::class)) {
                    throw new \RuntimeException('PHPMailer is unavailable. Run composer install.');
                }
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $config['host'];
                $mail->Port = $config['port'];
                $mail->SMTPAuth = true;
                $mail->Username = $config['username'];
                $mail->Password = $config['password'];
                $mail->SMTPSecure = $config['encryption'];
                $mail->setFrom($config['from_address'], $config['from_name']);
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $html;
                $mail->AltBody = trim(strip_tags($html));
                $mail->send();
                $status = 'sent';
            }
        } catch (Throwable $exception) {
            $status = 'failed';
            $error = mb_substr($exception->getMessage(), 0, 2000);
        }

        $this->logDelivery($to, $subject, $html, $template, $sensitive, $status, $error);
        return $status === 'sent' || $status === 'logged';
    }

    private function logDelivery(string $to, string $subject, string $html, ?string $template, bool $sensitive, string $status, ?string $error): void
    {
        $db = Database::get();
        $safeSchema = $this->safeLogSchemaAvailable($db);
        if ($sensitive && !$safeSchema) {
            // Upgrades may briefly run new code before the migration. Never fall
            // back to the legacy NOT NULL body column for an authentication secret.
            return;
        }
        $base = [
            'recipient' => $to,
            'subject' => $subject,
            'template_key' => $template,
            'body_html' => $sensitive ? null : $html,
            'status' => $status,
            'error_message' => $error,
            'created_at' => date('Y-m-d H:i:s'),
            'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ];
        if ($safeSchema) {
            $base += [
                'body_checksum_sha256' => hash('sha256', $html),
                'sensitive_redacted' => $sensitive ? 1 : 0,
                'correlation_id' => bin2hex(random_bytes(16)),
                'metadata_json' => json_encode([
                    'content_retained' => !$sensitive,
                    'content_bytes' => strlen($html),
                    'redacted_reason' => $sensitive ? 'authentication_secret' : null,
                ], JSON_UNESCAPED_SLASHES),
            ];
        }
        $db->insert('mail_logs', $base);
    }

    private function safeLogSchemaAvailable(Database $db): bool
    {
        try {
            return (int) $db->scalar(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mail_logs' AND column_name = 'sensitive_redacted'"
            ) === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
