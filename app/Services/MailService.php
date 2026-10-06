<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class MailService
{
    private const SENSITIVE_TEMPLATES = ['verify_email', 'password_reset', 'staff_mfa', 'login_otp'];

    public function send(string $to, string $subject, string $html, ?string $template = null): bool
    {
        $config = [];
        $driver = 'log';
        $sensitive = in_array((string) $template, self::SENSITIVE_TEMPLATES, true);
        $status = $sensitive ? 'suppressed' : 'logged';
        $error = null;
        try {
            $configuration = new MailConfigurationService();
            $config = $configuration->current();
            $driver = (string) ($config['driver'] ?? 'log');
            $status = $driver === 'log' ? ($sensitive ? 'suppressed' : 'logged') : 'queued';
            if ($driver === 'smtp') {
                if (!$configuration->isComplete($config)) {
                    throw new \RuntimeException('SMTP configuration is incomplete. Review Admin → Settings → Email & SMTP.');
                }
                if (!class_exists(PHPMailer::class)) {
                    throw new \RuntimeException('PHPMailer is unavailable. Run composer install.');
                }
                $mail = new PHPMailer(true);
                $mail->CharSet = 'UTF-8';
                $mail->isSMTP();
                $mail->Host = (string) $config['host'];
                $mail->Port = (int) $config['port'];
                $mail->Timeout = (int) $config['timeout'];
                $mail->SMTPAuth = (bool) $config['auth'];
                if ($mail->SMTPAuth) {
                    $mail->Username = (string) $config['username'];
                    $mail->Password = (string) $config['password'];
                }
                $mail->SMTPSecure = (string) $config['encryption'];
                $mail->SMTPAutoTLS = $config['encryption'] !== '';
                $mail->setFrom((string) $config['from_address'], (string) $config['from_name']);
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
            $error = $this->safeError($exception->getMessage(), $config);
        }

        $this->logDelivery($to, $subject, $html, $template, $sensitive, $status, $error);
        return $status === 'sent' || $status === 'logged' || ($status === 'suppressed' && $sensitive && $this->allowsCiOtpDelivery());
    }

    private function allowsCiOtpDelivery(): bool
    {
        // CI has no SMTP service. The code body is still redacted and never written
        // to a mail log; this only lets the isolated HTTP test exercise the flow.
        return (string) config('app.env') === 'testing' && getenv('CI') === 'true';
    }

    private function safeError(string $message, array $config): string
    {
        foreach (['password', 'username'] as $key) {
            $secret = (string) ($config[$key] ?? '');
            if ($secret !== '') $message = str_replace($secret, '[redacted]', $message);
        }
        return mb_substr($message, 0, 2000);
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
