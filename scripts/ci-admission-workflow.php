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
if($result['status']!=='eligible'||count($result['programs'])!==1)throw new RuntimeException('The complete seeded eligibility rule set did not produce the expected eligible result.');
$revisions=(int)$db->scalar('SELECT COUNT(*) FROM application_submission_snapshot_revisions WHERE application_id=:application',['application'=>$application['id']]);
if($revisions!==2)throw new RuntimeException("Expected two immutable submission revisions, got {$revisions}.");
$snapshot=$db->fetch('SELECT * FROM application_submission_snapshots WHERE application_id=:application',['application'=>$application['id']]);
if(!$snapshot||!hash_equals((string)$snapshot['snapshot_hash'],hash('sha256',(string)$snapshot['snapshot_json']))||(int)$snapshot['configuration_version_id']!==(int)$application['configuration_version_id'])throw new RuntimeException('Current submission snapshot hash or configuration version is invalid.');
$correction=$db->fetch('SELECT status FROM application_corrections WHERE id=:id',['id'=>$correctionId]);
if(($correction['status']??'')!=='submitted')throw new RuntimeException('Correction request was not submitted.');

$selectionApplication=$db->fetch("SELECT a.* FROM applications a JOIN users u ON u.id=a.user_id WHERE u.email='ayan@demo.test'");
if(!$selectionApplication||$selectionApplication['status']!=='under_review')throw new RuntimeException('Seat-allocation fixture is unavailable.');
$selectionEligibility=(new EligibilityService())->evaluate((int)$selectionApplication['id']);
if($selectionEligibility['status']!=='eligible')throw new RuntimeException('Seat-allocation fixture did not pass eligibility.');
$cycleProgramId=(int)$db->scalar('SELECT cycle_program_id FROM application_preferences WHERE application_id=:application ORDER BY preference_order LIMIT 1',['application'=>$selectionApplication['id']]);
$seatId=(int)$db->scalar("SELECT id FROM seat_matrix WHERE cycle_program_id=:program AND category='OBC-B' AND quota='state'",['program'=>$cycleProgramId]);
$filledBefore=(int)$db->scalar('SELECT filled_seats FROM seat_matrix WHERE id=:id',['id'=>$seatId]);
$db->update('application_fee_assessments',['status'=>'waived','updated_at'=>date('Y-m-d H:i:s')],"application_id=:application AND fee_type='application_fee'",['application'=>$selectionApplication['id']]);
$selected=(new ApplicationWorkflowService())->transition((int)$selectionApplication['id'],'selected','CI seat allocation',(int)$admin['id'],['status_version'=>(int)$selectionApplication['status_version'],'cycle_program_id'=>$cycleProgramId,'category'=>'OBC-B','quota'=>'state']);
$allocation=$db->fetch('SELECT * FROM seat_allocations WHERE application_id=:application AND is_active=1',['application'=>$selectionApplication['id']]);
if($selected['status']!=='selected'||!$allocation||$allocation['status']!=='reserved'||(int)$db->scalar('SELECT filled_seats FROM seat_matrix WHERE id=:id',['id'=>$seatId])!==$filledBefore+1)throw new RuntimeException('Transactional seat allocation did not reserve and count the expected seat.');
$admissionAssessment=$db->fetch("SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type='admission_fee'",['application'=>$selectionApplication['id']]);
if(!$admissionAssessment||(float)$admissionAssessment['total_amount']<=0)throw new RuntimeException('Admission-fee assessment was not created during selection.');
$db->update('application_fee_assessments',['status'=>'waived','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$admissionAssessment['id']]);
$admitted=(new ApplicationWorkflowService())->transition((int)$selectionApplication['id'],'admitted','CI admission confirmation',(int)$admin['id'],['status_version'=>(int)$selected['status_version']]);
$allocation=$db->fetch('SELECT * FROM seat_allocations WHERE application_id=:application AND is_active=1',['application'=>$selectionApplication['id']]);
if($admitted['status']!=='admitted'||($allocation['status']??'')!=='confirmed'||!(int)$db->scalar('SELECT COUNT(*) FROM student_enrollments WHERE application_id=:application',['application'=>$selectionApplication['id']]))throw new RuntimeException('Admission did not confirm the allocation and create an enrollment.');

echo "Admission state, corrections, exact eligibility, fee guards, seat allocation, enrollment, and immutable snapshots passed.\n";
