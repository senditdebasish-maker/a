<?php

declare(strict_types=1);

/**
 * XAMPP-compatible scheduled task. Run every 10–15 minutes:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\netaji\scripts\expire-admission-offers.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
$composer=BASE_PATH.'/vendor/autoload.php';
if(is_file($composer))require $composer;
else{require BASE_PATH.'/app/Support/helpers.php';spl_autoload_register(static function(string $class):void{if(!str_starts_with($class,'App\\'))return;$file=BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php';if(is_file($file))require $file;});}

use App\Core\Database;
use App\Core\Env;
use App\Services\MeritService;

Env::load(BASE_PATH.'/.env');
$db=Database::get();
$actorId=(int)$db->scalar("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='super-admin' AND u.status='active' ORDER BY u.id LIMIT 1");
if($actorId<1){fwrite(STDERR,"No active super-admin service actor is available; no offer was changed.\n");exit(1);}
try{$count=(new MeritService())->expireDueOffers($actorId,1000);fwrite(STDOUT,"Expired {$count} unpaid admission offer(s). Seats were released; waitlist promotion remains manual.\n");exit(0);}catch(Throwable $exception){fwrite(STDERR,"Offer expiry failed: ".$exception->getMessage()."\n");exit(1);}
