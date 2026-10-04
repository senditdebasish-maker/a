<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;
use Throwable;

final class MailConfigurationService
{
    private const SETTING_MAP = [
        'mail_driver' => 'driver',
        'mail_host' => 'host',
        'mail_port' => 'port',
        'mail_auth' => 'auth',
        'mail_username' => 'username',
        'mail_encryption' => 'encryption',
        'mail_from_address' => 'from_address',
        'mail_from_name' => 'from_name',
        'mail_timeout' => 'timeout',
    ];

    public function current(): array
    {
        $base = (array) config('mail', []);
        $config = [
            'driver' => strtolower((string) ($base['driver'] ?? 'log')),
            'host' => (string) ($base['host'] ?? ''),
            'port' => (int) ($base['port'] ?? 587),
            'auth' => $this->boolValue($base['auth'] ?? true),
            'username' => (string) ($base['username'] ?? ''),
            'password' => (string) ($base['password'] ?? ''),
            'encryption' => $this->normalizeEncryption((string) ($base['encryption'] ?? 'tls')),
            'from_address' => (string) ($base['from_address'] ?? 'admissions@example.edu.in'),
            'from_name' => (string) ($base['from_name'] ?? 'Netaji College of Pharmacy'),
            'timeout' => (int) ($base['timeout'] ?? 20),
            'source' => 'environment',
            'override_active' => false,
            'credential_error' => null,
        ];

        try {
            $rows = Database::get()->all("SELECT key_name, value FROM settings WHERE group_name = 'mail'");
            $stored = [];
            foreach ($rows as $row) $stored[(string) $row['key_name']] = (string) ($row['value'] ?? '');
            if ($stored !== []) {
                foreach (self::SETTING_MAP as $setting => $key) {
                    if (!array_key_exists($setting, $stored)) continue;
                    $config[$key] = $stored[$setting];
                }
                if (array_key_exists('mail_password_encrypted', $stored)) {
                    try {
                        $config['password'] = (string) (Encryption::decrypt($stored['mail_password_encrypted']) ?? '');
                    } catch (Throwable) {
                        $config['password'] = '';
                        $config['credential_error'] = 'The saved SMTP password cannot be decrypted with the current APP_KEY. Enter and save it again.';
                    }
                }
                $config['source'] = 'admin_settings';
                $config['override_active'] = true;
            }
        } catch (Throwable) {
            // Installation and migration checks may run before the settings table
            // exists. Environment configuration remains the safe fallback.
        }

        $config['driver'] = in_array(strtolower((string) $config['driver']), ['log', 'smtp'], true) ? strtolower((string) $config['driver']) : 'log';
        $config['host'] = trim((string) $config['host']);
        $config['port'] = max(1, min(65535, (int) $config['port']));
        $config['auth'] = $this->boolValue($config['auth']);
        $config['username'] = trim((string) $config['username']);
        $config['encryption'] = $this->normalizeEncryption((string) $config['encryption']);
        $config['from_address'] = trim((string) $config['from_address']);
        $config['from_name'] = trim((string) $config['from_name']);
        $config['timeout'] = max(5, min(60, (int) $config['timeout']));
        $config['password_configured'] = $config['password'] !== '';
        $config['complete'] = $this->isComplete($config);

        return $config;
    }

    public function forDisplay(): array
    {
        $config = $this->current();
        unset($config['password']);
        $config['encryption_option'] = $config['encryption'] === '' ? 'none' : $config['encryption'];
        return $config;
    }

    /**
     * @return array{config: array, errors: array<string,array<int,string>>, new_password: ?string, clear_password: bool}
     */
    public function validate(array $input): array
    {
        $current = $this->current();
        $driver = strtolower(trim((string) ($input['mail_driver'] ?? $current['driver'])));
        $host = trim((string) ($input['mail_host'] ?? $current['host']));
        $port = filter_var($input['mail_port'] ?? $current['port'], FILTER_VALIDATE_INT);
        $auth = isset($input['mail_auth']);
        $username = trim((string) ($input['mail_username'] ?? $current['username']));
        $encryptionInput = strtolower(trim((string) ($input['mail_encryption'] ?? ($current['encryption'] ?: 'none'))));
        $fromAddress = mb_strtolower(trim((string) ($input['mail_from_address'] ?? $current['from_address'])));
        $fromName = trim((string) ($input['mail_from_name'] ?? $current['from_name']));
        $timeout = filter_var($input['mail_timeout'] ?? $current['timeout'], FILTER_VALIDATE_INT);
        $passwordInput = (string) ($input['mail_password'] ?? '');
        $newPassword = $passwordInput !== '' ? $passwordInput : null;
        $clearPassword = isset($input['mail_clear_password']);
        $effectivePassword = $clearPassword ? '' : ($newPassword ?? (string) ($current['password'] ?? ''));
        $errors = [];
        $addError = static function (string $field, string $message) use (&$errors): void { $errors[$field][] = $message; };

        if (!in_array($driver, ['log', 'smtp'], true)) $addError('mail_driver', 'Choose local log or authenticated SMTP delivery.');
        if ($host !== '' && (mb_strlen($host) > 255 || preg_match('/[\s\/?#]/u', $host) || str_contains($host, '://'))) $addError('mail_host', 'Enter only the SMTP host name or IP address, without a protocol, path or spaces.');
        if ($port === false || $port < 1 || $port > 65535) $addError('mail_port', 'SMTP port must be between 1 and 65,535.');
        if (!in_array($encryptionInput, ['none', 'tls', 'ssl'], true)) $addError('mail_encryption', 'Choose no encryption, STARTTLS or implicit TLS.');
        if (mb_strlen($username) > 255 || preg_match('/[\r\n\0]/', $username)) $addError('mail_username', 'SMTP username must be 255 characters or fewer and cannot contain control characters.');
        if ($newPassword !== null && (strlen($newPassword) > 1024 || preg_match('/[\r\n\0]/', $newPassword))) $addError('mail_password', 'SMTP password must be 1,024 characters or fewer and cannot contain line breaks.');
        if ($newPassword !== null && $clearPassword) $addError('mail_password', 'Enter a replacement password or choose removal, not both.');
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || mb_strlen($fromAddress) > 190 || preg_match('/[\r\n]/', $fromAddress)) $addError('mail_from_address', 'Enter a valid From email address.');
        if ($fromName === '' || mb_strlen($fromName) > 190 || preg_match('/[\r\n]/', $fromName)) $addError('mail_from_name', 'From name is required, must be 190 characters or fewer and cannot contain line breaks.');
        if ($timeout === false || $timeout < 5 || $timeout > 60) $addError('mail_timeout', 'Connection timeout must be between 5 and 60 seconds.');
        if ($driver === 'smtp') {
            if ($host === '') $addError('mail_host', 'SMTP host is required when SMTP delivery is enabled.');
            if ($auth && $username === '') $addError('mail_username', 'SMTP username is required when authentication is enabled.');
            if ($auth && $effectivePassword === '') $addError('mail_password', 'Enter an SMTP password before enabling authenticated delivery.');
        }

        return [
            'config' => [
                'driver' => $driver,
                'host' => $host,
                'port' => $port === false ? 0 : (int) $port,
                'auth' => $auth,
                'username' => $username,
                'password' => $effectivePassword,
                'encryption' => $this->normalizeEncryption($encryptionInput),
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'timeout' => $timeout === false ? 0 : (int) $timeout,
            ],
            'errors' => $errors,
            'new_password' => $newPassword,
            'clear_password' => $clearPassword,
        ];
    }

    public function save(array $config, ?string $newPassword, bool $clearPassword): void
    {
        $values = [
            'mail_driver' => (string) $config['driver'],
            'mail_host' => (string) $config['host'],
            'mail_port' => (string) (int) $config['port'],
            'mail_auth' => !empty($config['auth']) ? '1' : '0',
            'mail_username' => (string) $config['username'],
            'mail_encryption' => (string) ($config['encryption'] === '' ? 'none' : $config['encryption']),
            'mail_from_address' => (string) $config['from_address'],
            'mail_from_name' => (string) $config['from_name'],
            'mail_timeout' => (string) (int) $config['timeout'],
        ];
        if ($newPassword !== null) $values['mail_password_encrypted'] = (string) Encryption::encrypt($newPassword);
        elseif ($clearPassword) $values['mail_password_encrypted'] = '';

        Database::get()->transaction(function (Database $db) use ($values): void {
            foreach ($values as $key => $value) {
                $existing = $db->fetch('SELECT id FROM settings WHERE key_name = :key', ['key' => $key]);
                $data = [
                    'group_name' => 'mail',
                    'value' => $value,
                    'value_type' => $key === 'mail_password_encrypted' ? 'encrypted' : 'string',
                    'is_public' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if ($existing) {
                    $db->update('settings', $data, 'id = :id', ['id' => $existing['id']]);
                } else {
                    $db->insert('settings', $data + ['key_name' => $key, 'created_at' => date('Y-m-d H:i:s')]);
                }
            }
        });
    }

    public function auditSummary(array $config): array
    {
        return [
            'driver' => (string) ($config['driver'] ?? 'log'),
            'host' => (string) ($config['host'] ?? ''),
            'port' => (int) ($config['port'] ?? 0),
            'authenticated' => !empty($config['auth']),
            'encryption' => (string) (($config['encryption'] ?? '') ?: 'none'),
            'from_address' => (string) ($config['from_address'] ?? ''),
            'timeout' => (int) ($config['timeout'] ?? 0),
        ];
    }

    public function isComplete(array $config): bool
    {
        if (($config['driver'] ?? '') !== 'smtp') return false;
        if (trim((string) ($config['host'] ?? '')) === '' || (int) ($config['port'] ?? 0) < 1) return false;
        if (!filter_var((string) ($config['from_address'] ?? ''), FILTER_VALIDATE_EMAIL)) return false;
        if (!empty($config['auth']) && (trim((string) ($config['username'] ?? '')) === '' || (string) ($config['password'] ?? '') === '')) return false;
        return true;
    }

    private function normalizeEncryption(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'ssl', 'smtps' => 'ssl',
            'tls', 'starttls' => 'tls',
            default => '',
        };
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
