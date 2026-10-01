<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Services\AdmissionCycleService;
use App\Services\ApplicationWorkflowService;
use App\Services\CorrectionService;
use App\Services\EligibilityService;

if(PHP_SAPI!=='cli'||getenv('CI')!=='true'){fwrite(STDERR,"This workflow test runs only in CI.\n");exit(2);}
define('BASE_PATH',dirname(__DIR__));
require BASE_PATH.'/vendor/autoload.php';
Env::load(BASE_PATH.'/.env');date_default_timezone_set((string)Config::get('app.timezone','Asia/Kolkata'));

$db=Database::get();
$application=$db->fetch("SELECT a.* FROM applications a JOIN users u ON u.id=a.user_id WHERE u.email='ishita@demo.test'");
$admin=$db->fetch("SELECT id FROM users WHERE email='ci-admin@example.test'");
if(!$application||!$admin)throw new RuntimeException('Workflow fixture records are missing.');
$readiness=(new AdmissionCycleService())->readiness((int)$application['admission_cycle_id']);
if(!$readiness['ready'])throw new RuntimeException('Seeded cycle is not publish-ready: '.implode(' ',$readiness['errors']));
(new ApplicationWorkflowService())->transition((int)$application['id'],'under_review','CI review started',(int)$admin['id'],['status_version'=>(int)$application['status_version']]);
$correctionId=(new CorrectionService())->request((int)$application['id'],'Confirm the personal section for the workflow test.',date('Y-m-d H:i:s',time()+3600),[['target_type'=>'section','target_key'=>'personal','instructions'=>'Review and confirm the personal details.']],(int)$admin['id']);
(new CorrectionService())->markResponded((int)$application['id'],(int)$application['user_id'],'section','personal');
(new CorrectionService())->submit((int)$application['id'],(int)$application['user_id']);
$result=(new EligibilityService())->evaluate((int)$application['id']);
if(!in_array($result['status'],['eligible','needs_review','ineligible'],true))throw new RuntimeException('Eligibility result is invalid.');
$revisions=(int)$db->scalar('SELECT COUNT(*) FROM application_submission_snapshot_revisions WHERE application_id=:application',['application'=>$application['id']]);
if($revisions!==2)throw new RuntimeException("Expected two immutable submission revisions, got {$revisions}.");
$correction=$db->fetch('SELECT status FROM application_corrections WHERE id=:id',['id'=>$correctionId]);
if(($correction['status']??'')!=='submitted')throw new RuntimeException('Correction request was not submitted.');
echo "Admission state, correction, eligibility, and immutable snapshot workflow passed.\n";
