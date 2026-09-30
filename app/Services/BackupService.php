<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;
use ZipArchive;

final class BackupService
{
    public function create(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP extension is required for backups.');
        }
        if ((string) config('database.driver') !== 'mysql') {
            throw new RuntimeException('The production backup tool currently supports MySQL/MariaDB installations.');
        }
        $dir = BASE_PATH . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Backup directory is not writable.');
        }
        $stamp = date('Ymd-His');
        $base = 'ncp-backup-' . $stamp;
        $sqlPath = $dir . '/' . $base . '.sql';
        $zipPath = $dir . '/' . $base . '.zip';
        $encryptedPath = $zipPath . '.enc';
        $db = Database::get();
        $logId = $db->insert('backup_logs', [
            'filename' => basename($encryptedPath), 'size_bytes' => null, 'checksum_sha256' => null,
            'encrypted' => 1, 'status' => 'running', 'initiated_by' => Auth::id(), 'error_message' => null,
            'created_at' => date('Y-m-d H:i:s'), 'completed_at' => null,
        ]);

        try {
            $this->dumpDatabase($sqlPath);
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create backup archive.');
            }
            $zip->addFile($sqlPath, 'database.sql');
            $manifest = [
                'product' => 'Netaji Admission Hub', 'version' => '1.0.0', 'created_at' => date(DATE_ATOM),
                'database_driver' => 'mysql', 'files_included' => true,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $private = BASE_PATH . '/storage/private';
            if (is_dir($private)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($private, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (!$file->isFile() || $file->getFilename() === '.htaccess') continue;
                    $relative = substr($file->getPathname(), strlen($private) + 1);
                    $zip->addFile($file->getPathname(), 'private/' . str_replace('\\', '/', $relative));
                }
            }
            $zip->close();
            $this->encryptFile($zipPath, $encryptedPath);
            @unlink($sqlPath); @unlink($zipPath);
            $result = [
                'id' => $logId, 'filename' => basename($encryptedPath), 'path' => $encryptedPath,
                'size_bytes' => filesize($encryptedPath), 'checksum_sha256' => hash_file('sha256', $encryptedPath),
            ];
            $db->update('backup_logs', ['size_bytes' => $result['size_bytes'], 'checksum_sha256' => $result['checksum_sha256'], 'status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $logId]);
            AuditService::log('encrypted_backup_created', 'backup', $logId, [], ['filename' => $result['filename'], 'checksum' => $result['checksum_sha256']]);
            return $result;
        } catch (\Throwable $exception) {
            @unlink($sqlPath); @unlink($zipPath); @unlink($encryptedPath);
            $db->update('backup_logs', ['status' => 'failed', 'error_message' => mb_substr($exception->getMessage(), 0, 1000), 'completed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $logId]);
            throw $exception;
        }
    }

    private function dumpDatabase(string $path): void
    {
        $pdo = Database::get()->pdo();
        $handle = fopen($path, 'wb');
        if (!$handle) throw new RuntimeException('Could not create database dump file.');
        fwrite($handle, "-- Netaji Admission Hub backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);
        foreach ($tables as $tableRow) {
            $table = (string) $tableRow[0];
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(\PDO::FETCH_NUM);
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n");
            $statement = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
            while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                $columns = '`' . implode('`,`', array_map(static fn ($column) => str_replace('`', '``', $column), array_keys($row))) . '`';
                $values = implode(',', array_map(fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row)));
                fwrite($handle, "INSERT INTO `{$table}` ({$columns}) VALUES ({$values});\n");
            }
            fwrite($handle, "\n");
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    private function encryptFile(string $input, string $output): void
    {
        $plain = file_get_contents($input);
        if ($plain === false) throw new RuntimeException('Could not read temporary backup archive.');
        $key = hash('sha256', (string) config('app.key'), true);
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Backup encryption failed.');
        if (file_put_contents($output, 'NCPBACKUP1' . $iv . $tag . $cipher, LOCK_EX) === false) {
            throw new RuntimeException('Could not write encrypted backup.');
        }
        chmod($output, 0640);
    }
}
