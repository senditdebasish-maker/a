<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Services\MeritService;

if(PHP_SAPI!=='cli'||getenv('CI')!=='true'){fwrite(STDERR,"This merit scale test runs only in CI.\n");exit(2);}
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/vendor/autoload.php';Env::load(BASE_PATH.'/.env');date_default_timezone_set((string)Config::get('app.timezone','Asia/Kolkata'));
$db=Database::get();$pdo=$db->pdo();
$admin=(int)$db->scalar("SELECT id FROM users WHERE email='ci-admin@example.test'");
$cycleProgram=$db->fetch('SELECT cp.id,cp.admission_cycle_id FROM cycle_programs cp ORDER BY cp.id LIMIT 1');
$configId=(int)$db->scalar('SELECT id FROM admission_configuration_versions WHERE admission_cycle_id=:cycle ORDER BY version_no DESC LIMIT 1',['cycle'=>$cycleProgram['admission_cycle_id']]);
$password=(string)$db->scalar("SELECT password_hash FROM users WHERE email='ishita@demo.test'");
if(!$admin||!$cycleProgram||!$configId||$password==='')throw new RuntimeException('Merit scale fixture dependencies are missing.');
$count=2005;$now=date('Y-m-d H:i:s');$categories=['General','SC','ST','OBC-A','OBC-B','EWS'];
$userSql=$pdo->prepare('INSERT INTO users (first_name,last_name,email,mobile,password_hash,status,preferred_locale,email_verified_at,password_changed_at,created_at,updated_at) VALUES (?,?,?,?,?,\'active\',\'en\',?,?,?,?)');
$appSql=$pdo->prepare("INSERT INTO applications (application_number,user_id,admission_cycle_id,status,current_step,completion_percentage,eligibility_status,eligibility_flags,configuration_version_id,submitted_at,locked_at,created_at,updated_at) VALUES (?,?,?,?,7,100,'eligible',?,?,?,?,?,?)");
$profileSql=$pdo->prepare('INSERT INTO applicant_profiles (user_id,date_of_birth,gender,category,nationality,profile_completion,created_at,updated_at) VALUES (?,\'2007-01-01\',\'female\',?,\'Indian\',100,?,?)');
$educationSql=$pdo->prepare('INSERT INTO education_records (application_id,level,board,institution,passing_year,total_marks,obtained_marks,percentage,result_status,created_at,updated_at) VALUES (?,?,\'CI Board\',\'CI Scale School\',2026,500,?,? ,\'passed\',?,?)');
$preferenceSql=$pdo->prepare('INSERT INTO application_preferences (application_id,cycle_program_id,preference_order,allocation_status,created_at) VALUES (?,?,1,\'pending\',?)');
$pdo->beginTransaction();
try{
    for($i=1;$i<=$count+1;$i++){
        $email='merit-scale-'.str_pad((string)$i,4,'0',STR_PAD_LEFT).'@example.test';$userSql->execute(['Scale','Applicant '.$i,$email,null,$password,$now,$now,$now,$now]);$userId=(int)$pdo->lastInsertId();
        $category=$categories[$i%count($categories)];$submitted=date('Y-m-d H:i:s',strtotime('2026-09-01 00:00:00')+$i);$number='CI-MERIT-'.str_pad((string)$i,5,'0',STR_PAD_LEFT);$flags=json_encode(['programs'=>[['cycle_program_id'=>(int)$cycleProgram['id'],'status'=>'eligible','flags'=>[]]]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $appSql->execute([$number,$userId,$cycleProgram['admission_cycle_id'],$i===$count+1?'approved':'verified',$flags,$configId,$submitted,$submitted,$now,$now]);$applicationId=(int)$pdo->lastInsertId();
        $profileSql->execute([$userId,$category,$now,$now]);$class12=60+($i%31);$class10=55+(($i*7)%36);
        $educationSql->execute([$applicationId,'class_10',$class10*5,$class10,$now,$now]);$educationSql->execute([$applicationId,'class_12',$class12*5,$class12,$now,$now]);$preferenceSql->execute([$applicationId,$cycleProgram['id'],$now]);
    }
    $pdo->commit();
}catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();throw $exception;}
$started=microtime(true);$runId=(new MeritService())->generate((int)$cycleProgram['admission_cycle_id'],$admin);$elapsed=microtime(true)-$started;
$run=$db->fetch('SELECT * FROM merit_runs WHERE id=:id',['id'=>$runId]);
if(!$run||(int)$run['applicant_count']!==$count)throw new RuntimeException('2,005-record merit run did not preserve the expected distinct Verified candidate count.');
if((int)$db->scalar("SELECT COUNT(*) FROM merit_entries WHERE merit_run_id=:run AND application_number=:number",['run'=>$runId,'number'=>'CI-MERIT-'.str_pad((string)($count+1),5,'0',STR_PAD_LEFT)])!==0)throw new RuntimeException('A legacy approved application bypassed the strict Verified-only merit source gate.');
if((int)$run['entry_count']<$count)throw new RuntimeException('2,005-record merit run lost an open-list entry.');
if($elapsed>60)throw new RuntimeException('2,005-record merit generation exceeded the 60-second CI acceptance budget: '.number_format($elapsed,3).'s.');
$invalid=(int)$db->scalar("SELECT COUNT(*) FROM (SELECT merit_category,COUNT(*) AS total,MAX(category_rank) AS max_rank,COUNT(DISTINCT category_rank) AS distinct_ranks FROM merit_entries WHERE merit_run_id=:run GROUP BY merit_category HAVING total<>max_rank OR total<>distinct_ranks) ranks",['run'=>$runId]);
if($invalid!==0)throw new RuntimeException('A generated merit category contains a rank gap or duplicate rank.');
$publicLeakColumns=array_intersect(['applicant_name','email','mobile','date_of_birth'],array_keys($db->fetch('SELECT application_number,overall_rank,category_rank,merit_category,quota,result_status FROM merit_entries WHERE merit_run_id=:run LIMIT 1',['run'=>$runId])?:[]));
if($publicLeakColumns)throw new RuntimeException('Privacy-safe merit projection unexpectedly contains applicant identity fields.');
echo 'Merit scale passed: '.number_format($count).' verified applications, '.number_format((int)$run['entry_count']).' list entries, deterministic contiguous ranks in '.number_format($elapsed,3)."s.\n";
