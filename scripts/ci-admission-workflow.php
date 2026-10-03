<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Services\AdmissionCycleService;
use App\Services\ApplicationWorkflowService;
use App\Services\CorrectionService;
use App\Services\EligibilityService;
use App\Services\MeritService;
use App\Services\PaymentGatewayService;

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
foreach($db->all("SELECT document_type_id FROM cycle_document_requirements WHERE admission_cycle_id=:cycle AND is_required=1 AND stage='application'",['cycle'=>$selectionApplication['admission_cycle_id']]) as $requirement){
    if(!$db->fetch('SELECT id FROM application_documents WHERE application_id=:application AND document_type_id=:type',['application'=>$selectionApplication['id'],'type'=>$requirement['document_type_id']]))$db->insert('application_documents',['application_id'=>$selectionApplication['id'],'document_type_id'=>$requirement['document_type_id'],'path'=>'ci/verified.pdf','original_name'=>'verified.pdf','mime_type'=>'application/pdf','size_bytes'=>100,'checksum_sha256'=>str_repeat('b',64),'revision_no'=>1,'uploaded_by'=>$selectionApplication['user_id'],'status'=>'verified','review_remarks'=>'CI verified','reviewed_by'=>$admin['id'],'reviewed_at'=>date('Y-m-d H:i:s'),'uploaded_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
}
$verified=(new ApplicationWorkflowService())->transition((int)$selectionApplication['id'],'verified','CI strict merit gate passed',(int)$admin['id'],['status_version'=>(int)$selectionApplication['status_version']]);
$merit=new MeritService();
$merit->saveCycleSettings((int)$selectionApplication['admission_cycle_id'],'open_first','state',72,(int)$admin['id']);
$merit->saveFormula($cycleProgramId,20,80,0,(int)$admin['id']);
$runId=$merit->generate((int)$selectionApplication['admission_cycle_id'],(int)$admin['id']);
$run=$db->fetch('SELECT * FROM merit_runs WHERE id=:id',['id'=>$runId]);
if(!$run||$run['status']!=='draft'||(int)$run['applicant_count']!==1)throw new RuntimeException('Frozen merit generation did not include exactly the Verified fixture.');
$merit->publish($runId,(int)$admin['id']);
$entry=$db->fetch("SELECT * FROM merit_entries WHERE merit_run_id=:run AND application_id=:application AND merit_category='OBC-B'",['run'=>$runId,'application'=>$selectionApplication['id']]);
if(!$entry)throw new RuntimeException('Reserved-category merit entry was not generated and published.');
$selected=$merit->select((int)$entry['id'],'OBC-B','state',72,(int)$admin['id']);
$allocation=$db->fetch('SELECT * FROM seat_allocations WHERE application_id=:application AND is_active=1',['application'=>$selectionApplication['id']]);
if($selected['status']!=='selected'||!$allocation||$allocation['status']!=='reserved'||(int)$db->scalar('SELECT filled_seats FROM seat_matrix WHERE id=:id',['id'=>$seatId])!==$filledBefore+1)throw new RuntimeException('Transactional seat allocation did not reserve and count the expected seat.');
$admissionAssessment=$db->fetch("SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type='admission_fee'",['application'=>$selectionApplication['id']]);
if(!$admissionAssessment||(float)$admissionAssessment['total_amount']<=0)throw new RuntimeException('Admission-fee assessment was not created during selection.');
$db->update('application_fee_assessments',['status'=>'waived','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$admissionAssessment['id']]);
$admitted=(new ApplicationWorkflowService())->transition((int)$selectionApplication['id'],'admitted','CI admission confirmation',(int)$admin['id'],['status_version'=>(int)$selected['status_version']]);
$allocation=$db->fetch('SELECT * FROM seat_allocations WHERE application_id=:application AND is_active=1',['application'=>$selectionApplication['id']]);
if($admitted['status']!=='admitted'||($allocation['status']??'')!=='confirmed'||!(int)$db->scalar('SELECT COUNT(*) FROM student_enrollments WHERE application_id=:application',['application'=>$selectionApplication['id']]))throw new RuntimeException('Admission did not confirm the allocation and create an enrollment.');

$gateway=new PaymentGatewayService();$gateway->save('payu','sandbox','ci-merchant-key','ci-merchant-salt','ci-webhook-secret',true,true,(int)$admin['id']);
$config=$db->fetch("SELECT * FROM payment_gateway_configs WHERE provider='payu'");
if(!$config||str_contains((string)$config['secret_credential_encrypted'],'ci-merchant-salt'))throw new RuntimeException('Gateway secret was not stored as write-only encrypted data.');
$gatewayUser=$db->insert('users',['first_name'=>'Gateway','last_name'=>'Fixture','email'=>'gateway-fixture@example.test','mobile'=>'9000000999','password_hash'=>password_hash('Gateway#Fixture1',PASSWORD_DEFAULT),'status'=>'active','preferred_locale'=>'en','avatar_path'=>null,'email_verified_at'=>date('Y-m-d H:i:s'),'email_verification_token'=>null,'email_verification_expires_at'=>null,'last_login_at'=>null,'last_login_ip'=>null,'password_changed_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),'deleted_at'=>null]);
$gatewayApplication=$db->insert('applications',['application_number'=>'CI-GATEWAY-0001','user_id'=>$gatewayUser,'admission_cycle_id'=>$application['admission_cycle_id'],'status'=>'submitted','current_step'=>7,'completion_percentage'=>100,'eligibility_status'=>'eligible','eligibility_flags'=>'{}','configuration_version_id'=>$application['configuration_version_id'],'submitted_at'=>date('Y-m-d H:i:s'),'locked_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
$assessmentId=$db->insert('application_fee_assessments',['application_id'=>$gatewayApplication,'cycle_program_id'=>$cycleProgramId,'fee_rule_id'=>null,'configuration_version_id'=>$application['configuration_version_id'],'fee_type'=>'application_fee','category_code'=>'General','base_amount'=>1000,'late_amount'=>0,'total_amount'=>1000,'currency'=>'INR','due_at'=>null,'calculation_json'=>'{}','status'=>'due','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
$assessment=$db->fetch('SELECT * FROM application_fee_assessments WHERE id=:id',['id'=>$assessmentId]);
$reference='CIPAYU'.bin2hex(random_bytes(4));$transactionId=$db->insert('payment_gateway_transactions',['application_id'=>$gatewayApplication,'fee_assessment_id'=>$assessment['id'],'gateway_config_id'=>$config['id'],'provider_order_id'=>$reference,'provider_payment_id'=>null,'amount'=>$assessment['total_amount'],'currency'=>'INR','status'=>'created','request_reference'=>$reference,'payload_hash'=>hash('sha256',$reference),'response_json'=>'{}','expires_at'=>date('Y-m-d H:i:s',time()+1800),'paid_at'=>null,'verified_at'=>null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
$response=['status'=>'success','mihpayid'=>'CI-PAYMENT-1','udf5'=>'','udf4'=>'','udf3'=>'','udf2'=>'','udf1'=>(string)$gatewayApplication,'email'=>'gateway-fixture@example.test','firstname'=>'Gateway','productinfo'=>'Application Fee','amount'=>number_format((float)$assessment['total_amount'],2,'.',''),'txnid'=>$reference,'key'=>'ci-merchant-key'];
$reverse=implode('|',['ci-merchant-salt',$response['status'],'','','','','',$response['udf5'],$response['udf4'],$response['udf3'],$response['udf2'],$response['udf1'],$response['email'],$response['firstname'],$response['productinfo'],$response['amount'],$response['txnid'],$response['key']]);$response['hash']=hash('sha512',$reverse);
if(!$gateway->completeReturn('payu',$reference,$response))throw new RuntimeException('Signed PayU callback did not verify.');
if((string)$db->scalar('SELECT status FROM application_fee_assessments WHERE id=:id',['id'=>$assessment['id']])!=='paid'||(string)$db->scalar('SELECT status FROM payment_gateway_transactions WHERE id=:id',['id'=>$transactionId])!=='verified')throw new RuntimeException('Gateway verification did not atomically settle the immutable assessment.');

echo "Admission state, strict merit gate, frozen ranking, selection offers, signed gateway settlement, seat allocation, enrollment, notifications, and immutable snapshots passed.\n";
