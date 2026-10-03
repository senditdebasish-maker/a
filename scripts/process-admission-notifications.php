<?php

declare(strict_types=1);

/**
 * XAMPP-compatible admissions mail worker. Run every 2–5 minutes:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\netaji\scripts\process-admission-notifications.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
$composer=BASE_PATH.'/vendor/autoload.php';
if(is_file($composer))require $composer;
else{require BASE_PATH.'/app/Support/helpers.php';spl_autoload_register(static function(string $class):void{if(!str_starts_with($class,'App\\'))return;$file=BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require $file;});}

use App\Core\Env;
use App\Services\AdmissionNotificationService;

Env::load(BASE_PATH.'/.env');
try{$result=(new AdmissionNotificationService())->processQueued(500);fwrite(STDOUT,"Admissions email outbox: {$result['processed']} processed, {$result['sent']} sent/logged, {$result['failed']} queued for retry or failed.\n");exit(0);}catch(Throwable $exception){fwrite(STDERR,"Admissions notification worker failed: ".get_class($exception)."\n");exit(1);}
