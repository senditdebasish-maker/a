<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

final class CmsMediaService
{
    public function store(array $file, string $altText = ''): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The image could not be uploaded.');
        $limit = 6 * 1024 * 1024;
        if (($file['size'] ?? 0) < 1 || $file['size'] > $limit) throw new RuntimeException('CMS images must be smaller than 6 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG, and WebP images are accepted.');
        $relative = 'uploads/cms/' . date('Y/m') . '/' . bin2hex(random_bytes(18)) . '.' . $allowed[$mime];
        $destination = PUBLIC_PATH . '/' . $relative;
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) throw new RuntimeException('The CMS media directory could not be created.');
        if (!move_uploaded_file($file['tmp_name'], $destination)) throw new RuntimeException('The image could not be stored.');
        chmod($destination, 0644);
        Database::get()->insert('media', [
            'uploaded_by' => Auth::id(), 'disk' => 'public', 'path' => $relative,
            'original_name' => mb_substr(basename((string) $file['name']), 0, 255), 'mime_type' => $mime,
            'size_bytes' => (int) $file['size'], 'alt_text' => mb_substr($altText, 0, 255),
            'checksum_sha256' => hash_file('sha256', $destination), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $relative;
    }
}
