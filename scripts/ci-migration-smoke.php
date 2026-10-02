<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true') {
    fwrite(STDERR, "This migration fixture runs only in CI.\n");
    exit(2);
}
$phase = $argv[1] ?? '';
if (!in_array($phase, ['seed','verify'], true)) throw new RuntimeException('Use seed or verify.');
$database = getenv('DB_DATABASE') ?: 'ncp_upgrade';
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database . ';charset=utf8mb4',
    getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$now = '2026-10-01 12:00:00';
$insert = static function (PDO $pdo, string $table, array $data): int {
    $columns=array_keys($data);
    $statement=$pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES (:'.implode(',:',$columns).')');
    $statement->execute($data);
    return (int)$pdo->lastInsertId();
};
if ($phase === 'seed') {
    $role=$insert($pdo,'roles',['name'=>'Admission Officer','slug'=>'admission-officer','description'=>null,'is_system'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $permission=$insert($pdo,'permissions',['name'=>'View applications','slug'=>'applications.view','module'=>'admissions','description'=>null,'created_at'=>$now]);
    $insert($pdo,'role_permissions',['role_id'=>$role,'permission_id'=>$permission]);
    $staff=$insert($pdo,'users',['first_name'=>'Legacy','last_name'=>'Officer','email'=>'legacy-officer@example.test','mobile'=>null,'password_hash'=>password_hash('Legacy#Password1',PASSWORD_DEFAULT),'status'=>'active','preferred_locale'=>'en','avatar_path'=>null,'email_verified_at'=>$now,'email_verification_token'=>null,'email_verification_expires_at'=>null,'last_login_at'=>null,'last_login_ip'=>null,'password_changed_at'=>$now,'created_at'=>$now,'updated_at'=>$now,'deleted_at'=>null]);
    $user=$insert($pdo,'users',['first_name'=>'Legacy','last_name'=>'Applicant','email'=>'legacy-applicant@example.test','mobile'=>'9000000000','password_hash'=>password_hash('Legacy#Password1',PASSWORD_DEFAULT),'status'=>'active','preferred_locale'=>'en','avatar_path'=>null,'email_verified_at'=>$now,'email_verification_token'=>null,'email_verification_expires_at'=>null,'last_login_at'=>null,'last_login_ip'=>null,'password_changed_at'=>$now,'created_at'=>$now,'updated_at'=>$now,'deleted_at'=>null]);
    $insert($pdo,'pages',['title'=>'Legacy About Title','slug'=>'about','eyebrow'=>'Legacy eyebrow','excerpt'=>'Legacy excerpt that must remain unchanged.','body'=>'Legacy body that must be preserved in the additive builder migration.','template'=>'standard','hero_image'=>null,'meta_title'=>'Legacy meta title','meta_description'=>'Legacy meta description','status'=>'published','published_at'=>$now,'created_by'=>$staff,'updated_by'=>$staff,'created_at'=>$now,'updated_at'=>$now]);
    $session=$insert($pdo,'academic_sessions',['name'=>'2026-27','starts_on'=>'2026-08-01','ends_on'=>'2027-07-31','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $program=$insert($pdo,'programs',['department_id'=>null,'name'=>'Bachelor of Pharmacy','code'=>'BPH','slug'=>'bph','award_type'=>'Degree','duration_years'=>4,'total_semesters'=>8,'summary'=>null,'description'=>null,'eligibility_summary'=>null,'career_summary'=>null,'image_path'=>null,'status'=>'active','sort_order'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $cycle=$insert($pdo,'admission_cycles',['academic_session_id'=>$session,'name'=>'Legacy Open Cycle','code'=>'LEGACY-26','starts_at'=>'2026-01-01 00:00:00','ends_at'=>'2026-12-31 23:59:59','correction_deadline'=>null,'status'=>'open','instructions'=>'Legacy instructions','declaration_text'=>'Legacy declaration','application_number_prefix'=>'NCP-26','created_at'=>$now,'updated_at'=>$now]);
    $cp=$insert($pdo,'cycle_programs',['admission_cycle_id'=>$cycle,'program_id'=>$program,'seat_capacity'=>10,'application_fee'=>500,'admission_fee'=>5000,'minimum_marks_general'=>45,'minimum_marks_reserved'=>40,'min_age'=>17,'max_age'=>null,'accepted_entrance_exams'=>null,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $insert($pdo,'eligibility_rules',['cycle_program_id'=>$cp,'rule_type'=>'marks','field_name'=>'class_12_percentage','operator'=>'>=','comparison_value'=>'45','message'=>'Minimum marks','is_blocking'=>1,'sort_order'=>1,'created_at'=>$now]);
    $insert($pdo,'seat_matrix',['cycle_program_id'=>$cp,'category'=>'General','quota'=>'state','seats'=>10,'filled_seats'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $docType=$insert($pdo,'document_types',['name'=>'Class 12 marksheet','code'=>'class-12','description'=>null,'allowed_mimes'=>'application/pdf,image/jpeg,image/png','max_size_mb'=>5,'sort_order'=>1,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $insert($pdo,'cycle_document_requirements',['admission_cycle_id'=>$cycle,'document_type_id'=>$docType,'program_id'=>null,'category'=>null,'is_required'=>1,'stage'=>'application','sort_order'=>1,'created_at'=>$now]);
    $insert($pdo,'applicant_profiles',['user_id'=>$user,'date_of_birth'=>'2007-01-01','gender'=>'female','category'=>'General','nationality'=>'Indian','blood_group'=>null,'religion'=>null,'mother_tongue'=>null,'disability_status'=>null,'disability_percentage'=>null,'government_id_type'=>null,'government_id_encrypted'=>null,'government_id_last4'=>null,'photo_path'=>null,'signature_path'=>null,'profile_completion'=>80,'created_at'=>$now,'updated_at'=>$now]);
    $application=$insert($pdo,'applications',['application_number'=>'NCP-26-BPH-0001','user_id'=>$user,'admission_cycle_id'=>$cycle,'status'=>'submitted','current_step'=>7,'completion_percentage'=>100,'eligibility_status'=>'not_evaluated','eligibility_flags'=>null,'assigned_to'=>$staff,'assigned_at'=>$now,'submitted_at'=>$now,'locked_at'=>$now,'admitted_at'=>null,'withdrawal_reason'=>null,'created_at'=>$now,'updated_at'=>$now,'deleted_at'=>null]);
    $insert($pdo,'application_preferences',['application_id'=>$application,'cycle_program_id'=>$cp,'preference_order'=>1,'allocation_status'=>'pending','created_at'=>$now]);
    $insert($pdo,'application_documents',['application_id'=>$application,'document_type_id'=>$docType,'path'=>'applications/1/legacy.pdf','original_name'=>'legacy.pdf','mime_type'=>'application/pdf','size_bytes'=>100,'checksum_sha256'=>str_repeat('a',64),'status'=>'pending','review_remarks'=>null,'reviewed_by'=>null,'reviewed_at'=>null,'uploaded_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    $insert($pdo,'mail_logs',['recipient'=>'legacy-applicant@example.test','subject'=>'Reset password','template_key'=>'password_reset','body_html'=>'<a href="https://example.test/reset/SECRET">Reset</a>','status'=>'sent','error_message'=>null,'created_at'=>$now,'sent_at'=>$now]);
    echo "Legacy fixture seeded.\n";
    exit;
}

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$tableCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
$assert($tableCount===65,"Expected 65 tables after upgrade, got {$tableCount}");
$cycle=$pdo->query("SELECT status, slug, configuration_version FROM admission_cycles WHERE code='LEGACY-26'")->fetch();
$assert($cycle['status']==='published' && $cycle['slug']==='legacy-26' && (int)$cycle['configuration_version']===1,'Legacy cycle was not normalized/versioned.');
$app=$pdo->query("SELECT id,configuration_version_id FROM applications WHERE application_number='NCP-26-BPH-0001'")->fetch();
$assert($app&&(int)$app['configuration_version_id']>0,'Legacy application configuration version missing.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn()===1,'Legacy application record count changed during migration.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM application_preferences WHERE application_id='.(int)$app['id'])->fetchColumn()===1,'Legacy programme preference was not preserved.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM application_documents WHERE application_id='.(int)$app['id'])->fetchColumn()===1,'Legacy document record was not preserved.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM application_submission_snapshots')->fetchColumn()===1,'Legacy submission snapshot missing.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM application_submission_snapshot_revisions')->fetchColumn()===1,'Legacy immutable snapshot revision missing.');
$mail=$pdo->query("SELECT body_html, body_checksum_sha256, sensitive_redacted FROM mail_logs WHERE template_key='password_reset'")->fetch();
$assert($mail['body_html']===null && strlen((string)$mail['body_checksum_sha256'])===64 && (int)$mail['sensitive_redacted']===1,'Sensitive historical mail was not redacted.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM application_document_versions')->fetchColumn()===1,'Document revision was not backfilled.');
$assert((int)$pdo->query('SELECT COUNT(*) FROM admission_fee_rules')->fetchColumn()===2,'Fee rules were not backfilled.');
$ledger=$pdo->query("SELECT checksum_sha256 FROM schema_migrations WHERE version='004_cms_page_builder'")->fetchColumn();
$assert(is_string($ledger)&&strlen($ledger)===64,'CMS builder migration checksum was not recorded.');
$assert((int)$pdo->query("SELECT COUNT(*) FROM pages WHERE slug IN ('home','about','programs','admissions','facilities','faculty','notices','gallery','faq','contact','privacy','terms')")->fetchColumn()===12,'Public CMS page shells were not backfilled.');
$legacyPage=$pdo->query("SELECT id,title,body FROM pages WHERE slug='about'")->fetch();
$assert(($legacyPage['title']??'')==='Legacy About Title'&&($legacyPage['body']??'')==='Legacy body that must be preserved in the additive builder migration.','Existing CMS page content changed during migration.');
$preservedBody=$pdo->query('SELECT body FROM page_sections WHERE page_id='.(int)$legacyPage['id']." AND section_key='overview'")->fetchColumn();
$assert($preservedBody===$legacyPage['body'],'Existing CMS page body was not preserved as a builder section.');
$assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='page_sections'")->fetchColumn()===1,'Page section builder table is missing.');
$assert((int)$pdo->query("SELECT COUNT(*) FROM page_sections ps JOIN pages p ON p.id=ps.page_id WHERE p.slug='home' AND ps.status='published'")->fetchColumn()>=6,'Editable starter home composition was not backfilled.');
echo "Existing-install admission and CMS migration verified.\n";
