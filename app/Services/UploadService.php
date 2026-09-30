<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class UploadService
{
    public function store(array $file, string $directory): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The file could not be uploaded.');
        }
        $limit = (int) config('app.upload_max_mb', 5) * 1024 * 1024;
        if (($file['size'] ?? 0) < 1 || $file['size'] > $limit) {
            throw new RuntimeException('The file must be smaller than ' . config('app.upload_max_mb', 5) . ' MB.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = (array) config('security.allowed_upload_mimes', []);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Only PDF, JPG, and PNG files are accepted.');
        }
        $extension = $allowed[$mime];
        $relative = trim($directory, '/') . '/' . date('Y/m') . '/' . bin2hex(random_bytes(20)) . '.' . $extension;
        $destination = BASE_PATH . '/storage/private/' . $relative;
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0770, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('The upload directory could not be created.');
        }
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('The file could not be stored.');
        }
        chmod($destination, 0640);
        return [
            'path' => $relative,
            'original_name' => mb_substr(basename((string) $file['name']), 0, 255),
            'mime_type' => $mime,
            'size_bytes' => (int) $file['size'],
            'checksum_sha256' => hash_file('sha256', $destination),
        ];
    }
}
