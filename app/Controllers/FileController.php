<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class FileController extends Controller
{
    public function document(string $id): never
    {
        $doc = Database::get()->fetch('SELECT ad.*, a.user_id FROM application_documents ad JOIN applications a ON a.id = ad.application_id WHERE ad.id = :id', ['id' => (int) $id]);
        if (!$doc || (!Auth::can('documents.view') && (int) $doc['user_id'] !== Auth::id())) {
            http_response_code(403); exit('Access denied.');
        }
        $this->stream((string) $doc['path'], (string) $doc['original_name'], (string) $doc['mime_type']);
    }

    public function payment(string $id): never
    {
        $payment = Database::get()->fetch('SELECT p.*, a.user_id AS applicant_user_id FROM payments p JOIN applications a ON a.id = p.application_id WHERE p.id = :id', ['id' => (int) $id]);
        if (!$payment || (!Auth::can('payments.view') && (int) $payment['applicant_user_id'] !== Auth::id())) {
            http_response_code(403); exit('Access denied.');
        }
        $this->stream((string) $payment['proof_path'], (string) $payment['proof_original_name'], 'application/octet-stream');
    }

    private function stream(string $relative, string $name, string $mime): never
    {
        $base = realpath(BASE_PATH . '/storage/private');
        $path = realpath(BASE_PATH . '/storage/private/' . ltrim($relative, '/'));
        if (!$base || !$path || !str_starts_with($path, $base) || !is_file($path)) {
            http_response_code(404); exit('File not found.');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', basename($name)) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
