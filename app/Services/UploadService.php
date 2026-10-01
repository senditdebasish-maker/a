<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class UploadService
{
    public function store(array $file, string $directory, ?array $allowedMimes = null, ?int $maxSizeMb = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) {
            throw new RuntimeException('The file could not be uploaded.');
        }
        $maxSizeMb ??= (int) config('app.upload_max_mb', 5);
        $maxSizeMb=max(1,min(25,$maxSizeMb));
        $limit=$maxSizeMb*1024*1024;
        if (($file['size']??0)<1||$file['size']>$limit) throw new RuntimeException("The file must be no larger than {$maxSizeMb} MB.");
        $finfo=new \finfo(FILEINFO_MIME_TYPE);
        $mime=(string)$finfo->file($file['tmp_name']);
        $global=(array)config('security.allowed_upload_mimes',[]);
        $allowedMimes=$allowedMimes?:array_keys($global);
        $allowedMimes=array_values(array_intersect($allowedMimes,array_keys($global)));
        if (!in_array($mime,$allowedMimes,true)||!isset($global[$mime])) throw new RuntimeException('The file type is not allowed for this document.');
        if (str_starts_with($mime,'image/')&&@getimagesize($file['tmp_name'])===false) throw new RuntimeException('The uploaded image is invalid.');
        if ($mime==='application/pdf') {
            $handle=fopen($file['tmp_name'],'rb'); $magic=$handle?fread($handle,5):''; if ($handle) fclose($handle);
            if ($magic!=='%PDF-') throw new RuntimeException('The uploaded PDF is invalid.');
        }
        $directory=trim(str_replace('\\','/',$directory),'/');
        if ($directory===''||!preg_match('#^[a-zA-Z0-9/_-]+$#',$directory)||str_contains($directory,'..')) throw new RuntimeException('The upload destination is invalid.');
        $relative=$directory.'/'.date('Y/m').'/'.bin2hex(random_bytes(20)).'.'.$global[$mime];
        $destination=BASE_PATH.'/storage/private/'.$relative;
        if (!is_dir(dirname($destination))&&!mkdir(dirname($destination),0770,true)&&!is_dir(dirname($destination))) throw new RuntimeException('The upload directory could not be created.');
        if (!move_uploaded_file($file['tmp_name'],$destination)) throw new RuntimeException('The file could not be stored.');
        chmod($destination,0640);
        return [
            'path'=>$relative,'original_name'=>mb_substr(basename((string)$file['name']),0,255),'mime_type'=>$mime,
            'size_bytes'=>(int)$file['size'],'checksum_sha256'=>hash_file('sha256',$destination),
        ];
    }
}
