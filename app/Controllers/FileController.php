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
        $doc=Database::get()->fetch('SELECT ad.*,a.user_id,a.assigned_to FROM application_documents ad JOIN applications a ON a.id=ad.application_id WHERE ad.id=:id',['id'=>(int)$id]);
        if(!$doc||!$this->mayAccess($doc,'documents.view')){$this->deny();}
        $this->stream((string)$doc['path'],(string)$doc['original_name'],(string)$doc['mime_type']);
    }

    public function documentVersion(string $id): never
    {
        $doc=Database::get()->fetch('SELECT adv.*,a.user_id,a.assigned_to FROM application_document_versions adv JOIN application_documents ad ON ad.id=adv.application_document_id JOIN applications a ON a.id=ad.application_id WHERE adv.id=:id',['id'=>(int)$id]);
        if(!$doc||!$this->mayAccess($doc,'documents.view')){$this->deny();}
        $this->stream((string)$doc['path'],(string)$doc['original_name'],(string)$doc['mime_type']);
    }

    public function payment(string $id): never
    {
        $payment=Database::get()->fetch('SELECT p.*,a.user_id,a.assigned_to FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.id=:id',['id'=>(int)$id]);
        if(!$payment||!$this->mayAccess($payment,'payments.view')){$this->deny();}
        $this->stream((string)$payment['proof_path'],(string)$payment['proof_original_name'],'application/octet-stream');
    }

    private function mayAccess(array $record,string $permission): bool
    {
        if((int)$record['user_id']===Auth::id())return true;
        if(!Auth::can($permission))return false;
        if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal']))return (int)$record['assigned_to']===Auth::id();
        return true;
    }

    private function deny(): never
    {
        http_response_code(403);exit('Access denied.');
    }

    private function stream(string $relative,string $name,string $mime): never
    {
        $base=realpath(BASE_PATH.'/storage/private');$path=realpath(BASE_PATH.'/storage/private/'.ltrim($relative,'/'));
        if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path)){http_response_code(404);exit('File not found.');}
        header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="'.str_replace(['"',"\r","\n"],'',basename($name)).'"');header('X-Content-Type-Options: nosniff');readfile($path);exit;
    }
}
