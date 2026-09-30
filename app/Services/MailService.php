<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

final class MailService
{
    public function send(string $to, string $subject, string $html, ?string $template = null): bool
    {
        $config = config('mail');
        $status = 'logged';
        $error = null;
        try {
            if (($config['driver'] ?? 'log') === 'smtp') {
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
            $error = $exception->getMessage();
        }

        Database::get()->insert('mail_logs', [
            'recipient' => $to,
            'subject' => $subject,
            'template_key' => $template,
            'body_html' => $html,
            'status' => $status,
            'error_message' => $error,
            'created_at' => date('Y-m-d H:i:s'),
            'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ]);
        return $status !== 'failed';
    }
}
