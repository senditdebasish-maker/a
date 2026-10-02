<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true') {
    fwrite(STDERR, "This smoke test runs only in CI.\n");
    exit(2);
}
if (!extension_loaded('curl')) throw new RuntimeException('The cURL extension is required.');

$base = rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8088', '/');

final class BrowserSession
{
    private string $cookies;

    public function __construct(private readonly string $base)
    {
        $this->cookies = tempnam(sys_get_temp_dir(), 'ncp-http-') ?: throw new RuntimeException('Could not create cookie jar.');
    }

    public function __destruct()
    {
        @unlink($this->cookies);
    }

    public function request(string $method, string $path, array $data = [], bool $follow = true): array
    {
        $curl = curl_init($this->base . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5, CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['User-Agent: NCP-CI-Smoke/1.0'],
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            $multipart=count(array_filter($data,static fn(mixed $value): bool=>$value instanceof CURLFile))>0;
            curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart?$data:http_build_query($data));
        }
        $response = curl_exec($curl);
        if ($response === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $result = [
            'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'content_type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
            'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize),
        ];
        curl_close($curl);
        return $result;
    }

    public function get(string $path, string $needle): void
    {
        $response = $this->request('GET', $path);
        if ($response['status'] !== 200 || !str_contains($response['body'], $needle)) {
            $detail=$response['status']>=500?substr(trim(html_entity_decode(strip_tags($response['body']))),0,1200):'';
            throw new RuntimeException("GET {$path} failed ({$response['status']}); expected text: {$needle}".($detail!==''?"; response: {$detail}":''));
        }
        echo "PASS GET {$path}\n";
    }

    public function expectStatus(string $path,int $expected): void
    {
        $response=$this->request('GET',$path);
        if($response['status']!==$expected)throw new RuntimeException("GET {$path} returned {$response['status']}; expected {$expected}");
        echo "PASS GET {$path} status {$expected}\n";
    }

    public function postWithCsrf(string $tokenPage,string $action,array $data,string $needle): void
    {
        $page=$this->request('GET',$tokenPage);if($page['status']!==200||!preg_match('/name="_token" value="([^"]+)"/',$page['body'],$match))throw new RuntimeException("CSRF token not found on {$tokenPage}.");
        $response=$this->request('POST',$action,['_token'=>html_entity_decode($match[1])]+$data);
        if($response['status']!==200||!str_contains($response['body'],$needle)){
            preg_match('/<div class="alert[^"]*">(.*?)<button/is',$response['body'],$alert);
            $detail=trim(html_entity_decode(strip_tags($alert[1]??'')));
            throw new RuntimeException("POST {$action} failed ({$response['status']}); expected text: {$needle}".($detail!==''?"; response alert: {$detail}":''));
        }
        echo "PASS POST {$action}\n";
    }

    public function postFileWithCsrf(string $tokenPage,string $action,array $data,string $field,string $path,string $mime,string $name,string $needle): void
    {
        $page=$this->request('GET',$tokenPage);if($page['status']!==200||!preg_match('/name="_token" value="([^"]+)"/',$page['body'],$match))throw new RuntimeException("CSRF token not found on {$tokenPage}.");
        $payload=['_token'=>html_entity_decode($match[1])]+$data;
        $payload[$field]=new CURLFile($path,$mime,$name);
        $response=$this->request('POST',$action,$payload);
        if($response['status']!==200||!str_contains($response['body'],$needle))throw new RuntimeException("Multipart POST {$action} failed ({$response['status']}); expected text: {$needle}");
        echo "PASS multipart POST {$action}\n";
    }

    public function postExpectStatus(string $tokenPage,string $action,array $data,int $expected): void
    {
        $page=$this->request('GET',$tokenPage);if($page['status']!==200||!preg_match('/name="_token" value="([^"]+)"/',$page['body'],$match))throw new RuntimeException("CSRF token not found on {$tokenPage}.");
        $response=$this->request('POST',$action,['_token'=>html_entity_decode($match[1])]+$data,false);
        if($response['status']!==$expected)throw new RuntimeException("POST {$action} returned {$response['status']}; expected {$expected}");
        echo "PASS POST {$action} status {$expected}\n";
    }

    public function login(string $email, string $password, string $landingNeedle): void
    {
        $page = $this->request('GET', '/login');
        if (!preg_match('/name="_token" value="([^"]+)"/', $page['body'], $match)) throw new RuntimeException('Login CSRF token not found.');
        $response = $this->request('POST', '/login', ['_token' => html_entity_decode($match[1]), 'email' => $email, 'password' => $password]);
        if ($response['status'] !== 200 || !str_contains($response['body'], $landingNeedle)) {
            throw new RuntimeException("Login failed for {$email}; final URL {$response['url']}, status {$response['status']}");
        }
        echo "PASS login {$email}\n";
    }
}

$public = new BrowserSession($base);
foreach ([
    '/' => 'Learn the science', '/programs' => 'Pharmacy programmes', '/admissions' => 'Your next step',
    '/admissions/undergraduate-admissions-2027-28' => 'Choose and rank your preferences',
    '/facilities' => 'Spaces that invite', '/faculty' => 'Guidance shaped', '/notices' => 'Notices &',
    '/notices/admissions-cycle-2027' => 'Applications for 2027', '/gallery' => 'Learning, belonging',
    '/faq' => 'Frequently asked', '/contact' => 'Talk to us', '/login' => 'Sign in to your portal',
    '/register' => 'Create applicant account', '/privacy' => 'Privacy notice', '/terms' => 'Terms of use',
] as $path => $needle) $public->get($path, $needle);

$applicant = new BrowserSession($base);
$applicant->login('ishita@demo.test', 'StudentDemo#2027', 'Applicant dashboard');
foreach ([
    '/student/dashboard' => 'Applicant dashboard', '/student/application' => 'Personal details',
    '/student/payments' => 'Payments & receipts', '/student/messages' => 'Messages & notifications',
    '/student/support' => 'Help & support', '/student/support/1' => 'Continue conversation',
    '/student/documents/application' => 'Application Summary', '/student/documents/cover-sheet' => 'Application Cover Sheet',
] as $path => $needle) $applicant->get($path, $needle);
$pdf = $applicant->request('GET', '/student/documents/application?format=pdf');
if ($pdf['status'] !== 200 || !str_contains($pdf['content_type'], 'application/pdf') || !str_starts_with($pdf['body'], '%PDF')) {
    throw new RuntimeException('Dompdf application download smoke test failed.');
}
echo "PASS Dompdf application download\n";

$admin = new BrowserSession($base);
$admin->login('ci-admin@example.test', 'CI-Temporary#2027', 'Administration');
$liveStart=date('Y-m-d\TH:i',strtotime('-1 day'));
$liveEnd=date('Y-m-d\TH:i',strtotime('+30 days'));
$correctionEnd=date('Y-m-d\TH:i',strtotime('+37 days'));
$admin->postWithCsrf('/admin/admissions/create','/admin/admissions',['academic_session_id'=>1,'name'=>'CI Editable Cycle','code'=>'CI-EDIT-28','slug'=>'ci-editable-cycle','starts_at'=>$liveStart,'ends_at'=>$liveEnd,'correction_deadline'=>$correctionEnd,'application_number_prefix'=>'CI-APP-28','max_program_preferences'=>3,'closing_soon_hours'=>72,'summary'=>'Editable cycle smoke test','instructions'=>'Complete all configured requirements.','declaration_text'=>'I confirm the submitted information is correct.'],'Draft admission cycle created');
$ciDb=new PDO('mysql:host='.(getenv('DB_HOST')?:'127.0.0.1').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'ncp_test').';charset=utf8mb4',getenv('DB_USERNAME')?:'root',getenv('DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$cycleId=(int)$ciDb->query("SELECT id FROM admission_cycles WHERE code='CI-EDIT-28'")->fetchColumn();
$cyclePath='/admin/admissions/'.$cycleId;
$admin->postWithCsrf($cyclePath,$cyclePath.'/programs',['program_id'=>1,'seat_capacity'=>10,'application_fee'=>500,'admission_fee'=>5000,'minimum_marks_general'=>45,'minimum_marks_reserved'=>40,'min_age'=>17,'max_age'=>30,'accepted_entrance_exams'=>'WBJEE'],'Programme added');
$cycleProgramId=(int)$ciDb->query('SELECT id FROM cycle_programs WHERE admission_cycle_id='.$cycleId.' AND program_id=1')->fetchColumn();
$programPath=$cyclePath.'/programs/'.$cycleProgramId;
$admin->postWithCsrf($cyclePath,$programPath.'/eligibility',['rule_type'=>'marks','field_name'=>'class_12_percentage','operator'=>'gte','comparison_value'=>'45','message'=>'Minimum marks required','is_blocking'=>1,'sort_order'=>10],'Eligibility rule saved');
$ruleId=(int)$ciDb->query('SELECT id FROM eligibility_rules WHERE cycle_program_id='.$cycleProgramId.' ORDER BY id DESC LIMIT 1')->fetchColumn();
$admin->postWithCsrf($cyclePath,$programPath.'/eligibility',['rule_id'=>$ruleId,'rule_type'=>'marks','field_name'=>'class_12_percentage','operator'=>'gte','comparison_value'=>'50','message'=>'Updated minimum marks required','is_blocking'=>1,'sort_order'=>20],'Eligibility rule saved');
$sectionId=(int)$ciDb->query("SELECT id FROM admission_form_sections WHERE admission_cycle_id={$cycleId} AND section_key='personal'")->fetchColumn();
$admin->postWithCsrf($cyclePath,$cyclePath.'/form-fields',['section_id'=>$sectionId,'field_key'=>'ci_choice','label'=>'CI choice','field_type'=>'select','options'=>"yes|Yes\nno|No",'help_text'=>'Editable option test','is_required'=>1,'sort_order'=>10],'Form field saved');
$fieldId=(int)$ciDb->query("SELECT id FROM admission_form_fields WHERE admission_cycle_id={$cycleId} AND field_key='ci_choice'")->fetchColumn();
$admin->postWithCsrf($cyclePath,$cyclePath.'/form-fields',['field_id'=>$fieldId,'section_id'=>$sectionId,'field_key'=>'ci_choice','label'=>'Updated CI choice','field_type'=>'radio','options'=>"yes|Yes please\nno|No thanks",'help_text'=>'Updated option test','conditional_rules'=>'{"field":"category","operator":"eq","value":"General"}','is_required'=>1,'sort_order'=>20,'status'=>'active'],'Form field saved');
$widgetDefinitions=[
    'text'=>['CI text',''], 'textarea'=>['CI textarea',''], 'email'=>['CI email',''], 'tel'=>['CI telephone',''],
    'number'=>['CI number',''], 'date'=>['CI date',''], 'select'=>['CI select',"alpha|Alpha\nbeta|Beta"],
    'checkbox'=>['CI checkbox',"consent|Consent\nupdates|Updates"], 'multiselect'=>['CI multiselect',"red|Red\nblue|Blue\ngreen|Green"],
];
$widgetFieldIds=[];$widgetSort=30;
foreach($widgetDefinitions as $type=>[$label,$options]){
    $key='ci_'.$type;
    $admin->postWithCsrf($cyclePath,$cyclePath.'/form-fields',['section_id'=>$sectionId,'field_key'=>$key,'label'=>$label,'field_type'=>$type,'options'=>$options,'help_text'=>'CI '.$type.' widget','is_required'=>1,'sort_order'=>$widgetSort,'status'=>'active'],'Form field saved');
    $widgetFieldIds[$type]=(int)$ciDb->query("SELECT id FROM admission_form_fields WHERE admission_cycle_id={$cycleId} AND field_key=".$ciDb->quote($key))->fetchColumn();
    if($widgetFieldIds[$type]<1)throw new RuntimeException("{$type} widget was not configured.");
    $widgetSort+=10;
}
$admin->postWithCsrf($cyclePath,$cyclePath.'/form-fields',['section_id'=>$sectionId,'field_key'=>'ci_file','label'=>'CI protected file','field_type'=>'file','canonical_binding'=>'document:photo','help_text'=>'Protected file widget','sort_order'=>$widgetSort,'status'=>'active'],'Form field saved');
$fileFieldId=(int)$ciDb->query("SELECT id FROM admission_form_fields WHERE admission_cycle_id={$cycleId} AND field_key='ci_file' AND field_type='file' AND canonical_binding='document:photo'")->fetchColumn();
if($fileFieldId<1)throw new RuntimeException('Protected file widget was not configured.');
$seatId=(int)$ciDb->query('SELECT id FROM seat_matrix WHERE cycle_program_id='.$cycleProgramId.' ORDER BY id LIMIT 1')->fetchColumn();
$admin->postWithCsrf($cyclePath,$programPath.'/seats',['seat_capacity'=>12,'seats'=>[$seatId=>12]],'Seat matrix saved');
$feeId=(int)$ciDb->query("SELECT id FROM admission_fee_rules WHERE cycle_program_id={$cycleProgramId} AND fee_type='application_fee' ORDER BY id LIMIT 1")->fetchColumn();
$admin->postWithCsrf($cyclePath,$programPath.'/fees',['fee_rule_id'=>$feeId,'fee_type'=>'application_fee','category_code'=>'','label'=>'Updated application fee','amount'=>600,'late_fee_amount'=>50,'refund_policy'=>'Non-refundable after submission.','status'=>'active'],'Fee rule saved');
$admin->postWithCsrf($cyclePath,$cyclePath.'/documents',['document_type_id'=>1,'program_id'=>'','category'=>'','stage'=>'application','sort_order'=>10,'is_required'=>1],'Document requirement saved');
$admin->postWithCsrf($cyclePath,$cyclePath,['academic_session_id'=>1,'name'=>'CI Editable Cycle Updated','code'=>'CI-EDIT-28','slug'=>'ci-editable-cycle','starts_at'=>$liveStart,'ends_at'=>$liveEnd,'correction_deadline'=>$correctionEnd,'application_number_prefix'=>'CI-APP-28','max_program_preferences'=>3,'closing_soon_hours'=>72,'summary'=>'Updated cycle used by the full HTTP workflow.','instructions'=>'Complete all configured requirements.','declaration_text'=>'I confirm the submitted information is correct.'],'Admission cycle settings saved');
$admin->postWithCsrf($cyclePath,$cyclePath.'/publish',[],'Cycle published with immutable configuration version 1');
$published=$ciDb->query('SELECT status,configuration_version FROM admission_cycles WHERE id='.$cycleId)->fetch();
$version=$ciDb->query('SELECT * FROM admission_configuration_versions WHERE admission_cycle_id='.$cycleId.' ORDER BY version_no DESC LIMIT 1')->fetch();
if(($published['status']??'')!=='published'||(int)($published['configuration_version']??0)!==1||!$version||!hash_equals((string)$version['snapshot_hash'],hash('sha256',(string)$version['snapshot_json'])))throw new RuntimeException('Published cycle configuration snapshot is invalid.');

$public->get('/','CI Editable Cycle Updated');
$public->get('/admissions','CI Editable Cycle Updated');
$public->get('/admissions/ci-editable-cycle','register?cycle=ci-editable-cycle');
$public->get('/programs/bachelor-of-pharmacy','register?cycle=ci-editable-cycle&program=bachelor-of-pharmacy');
$applicant->get('/admissions/ci-editable-cycle/apply','Updated CI choice');
$newApplicationId=(int)$ciDb->query('SELECT a.id FROM applications a JOIN users u ON u.id=a.user_id WHERE u.email="ishita@demo.test" AND a.admission_cycle_id='.$cycleId)->fetchColumn();
if($newApplicationId<1)throw new RuntimeException('Cycle-specific Apply Now did not create the expected application.');
$widgetPage=$applicant->request('GET','/student/application');
foreach(['CI text','CI textarea','CI email','CI telephone','CI number','CI date','CI select','CI checkbox','CI multiselect','CI protected file'] as $label)if($widgetPage['status']!==200||!str_contains($widgetPage['body'],$label))throw new RuntimeException("Configured widget {$label} was not rendered.");
foreach(['email','tel','number','date'] as $htmlType)if(!str_contains($widgetPage['body'],'type="'.$htmlType.'"'))throw new RuntimeException("Configured {$htmlType} input type was not rendered.");
if(!str_contains($widgetPage['body'],'type="radio" name="custom['.$fieldId.']"')||!str_contains($widgetPage['body'],'type="checkbox" name="custom['.$widgetFieldIds['checkbox'].'][]"')||!str_contains($widgetPage['body'],'<select name="custom['.$widgetFieldIds['multiselect'].'][]" multiple'))throw new RuntimeException('Radio, checkbox, or multiselect controls were not rendered with the configured widget semantics.');
if(!str_contains($widgetPage['body'],'<textarea')||!str_contains($widgetPage['body'],'Use the protected document section below'))throw new RuntimeException('Textarea or protected file guidance was not rendered.');
$widgetValues=['text'=>'Widget text','textarea'=>"Widget textarea\nsecond line",'email'=>'widget@example.test','tel'=>'+91 9876543210','number'=>'42.5','date'=>'2027-01-15','select'=>'beta','checkbox'=>['consent','updates'],'multiselect'=>['red','green']];
$customPayload=[$fieldId=>'yes'];foreach($widgetValues as $type=>$value)$customPayload[$widgetFieldIds[$type]]=$value;
$applicant->postWithCsrf('/student/application','/student/application/save',['section'=>'custom','custom'=>$customPayload],'Custom details saved');
$storedResponses=$ciDb->query('SELECT form_field_id,value_text,value_json FROM application_field_responses WHERE application_id='.$newApplicationId)->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
if(($storedResponses[$fieldId]['value_text']??null)!=='yes')throw new RuntimeException('Dynamic conditional radio response was not persisted.');
foreach($widgetValues as $type=>$expected){
    $stored=$storedResponses[$widgetFieldIds[$type]]??null;if(!$stored)throw new RuntimeException("{$type} widget response was not persisted.");
    $actual=is_array($expected)?json_decode((string)$stored['value_json'],true):$stored['value_text'];
    if($actual!==$expected)throw new RuntimeException("{$type} widget response changed during persistence.");
}
if(isset($storedResponses[$fileFieldId]))throw new RuntimeException('Protected file widget was incorrectly persisted as an ordinary custom response.');
echo "PASS every configured admission form widget\n";

$uploadPath=tempnam(sys_get_temp_dir(),'ncp-upload-');
if(!$uploadPath||file_put_contents($uploadPath,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))===false)throw new RuntimeException('Could not create upload fixture.');
$applicant->postFileWithCsrf('/student/application','/student/application/document',['document_type_id'=>1],'document',$uploadPath,'image/png','ci-photo.png','Document uploaded securely');
$document=$ciDb->query('SELECT * FROM application_documents WHERE application_id='.$newApplicationId.' AND document_type_id=1')->fetch();
if(!$document||(int)$document['revision_no']!==1||(int)$ciDb->query('SELECT COUNT(*) FROM application_document_versions WHERE application_document_id='.(int)$document['id'])->fetchColumn()!==1)throw new RuntimeException('Protected document upload and immutable revision were not recorded.');
$invalidUpload=tempnam(sys_get_temp_dir(),'ncp-invalid-');
if(!$invalidUpload||file_put_contents($invalidUpload,'not an image or PDF')===false)throw new RuntimeException('Could not create invalid upload fixture.');
$applicant->postFileWithCsrf('/student/application','/student/application/document',['document_type_id'=>1],'document',$invalidUpload,'image/png','forged-photo.png','file type is not allowed');
@unlink($invalidUpload);
if((int)$ciDb->query('SELECT revision_no FROM application_documents WHERE id='.(int)$document['id'])->fetchColumn()!==1)throw new RuntimeException('Rejected upload unexpectedly changed the stored document revision.');
$ciDb->exec('UPDATE document_types SET max_size_mb=1 WHERE id=1');
$oversizedUpload=tempnam(sys_get_temp_dir(),'ncp-oversized-');
$oversizedHandle=$oversizedUpload?fopen($oversizedUpload,'wb'):false;
if(!$oversizedHandle||fwrite($oversizedHandle,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))===false||!ftruncate($oversizedHandle,1024*1024+1)){if($oversizedHandle)fclose($oversizedHandle);throw new RuntimeException('Could not create oversized upload fixture.');}
fclose($oversizedHandle);
$applicant->postFileWithCsrf('/student/application','/student/application/document',['document_type_id'=>1],'document',$oversizedUpload,'image/png','oversized-photo.png','no larger than 1 MB');
@unlink($oversizedUpload);$ciDb->exec('UPDATE document_types SET max_size_mb=5 WHERE id=1');
if((int)$ciDb->query('SELECT revision_no FROM application_documents WHERE id='.(int)$document['id'])->fetchColumn()!==1)throw new RuntimeException('Oversized upload unexpectedly changed the stored document revision.');
echo "PASS upload maximum-size boundary\n";

$applicant->get('/student/application?application_id=1','Personal details');
$applicant->postFileWithCsrf('/student/payments','/student/payments',['amount'=>1000,'reference_number'=>'CI-PAYMENT-REF-1','paid_at'=>date('Y-m-d'),'method'=>'upi'],'proof',$uploadPath,'image/png','ci-payment.png','Payment proof submitted');
@unlink($uploadPath);
$payment=$ciDb->query("SELECT * FROM payments WHERE application_id=1 AND reference_number='CI-PAYMENT-REF-1'")->fetch();
if(!$payment||$payment['status']!=='pending'||(float)$payment['amount']!==1000.0)throw new RuntimeException('Server-assessed payment proof was not stored as pending.');
$admin->postWithCsrf('/admin/applications/1','/admin/applications/1/payments/'.(int)$payment['id'],['status'=>'verified','remarks'=>'CI verified payment'],'Payment verification saved');
$verified=$ciDb->query('SELECT p.status,p.receipt_number,afa.status AS assessment_status FROM payments p JOIN application_fee_assessments afa ON afa.id=p.fee_assessment_id WHERE p.id='.(int)$payment['id'])->fetch();
if(($verified['status']??'')!=='verified'||($verified['assessment_status']??'')!=='paid'||empty($verified['receipt_number']))throw new RuntimeException('Payment verification did not settle the fee assessment and issue a receipt.');

$admin->postWithCsrf($cyclePath,$cyclePath.'/form-fields',['field_id'=>$fieldId,'section_id'=>$sectionId,'field_key'=>'ci_choice','label'=>'Forbidden published edit','field_type'=>'radio','options'=>"yes|Yes\nno|No",'is_required'=>1,'sort_order'=>20,'status'=>'active'],'configurations are immutable');
$admin->postWithCsrf($cyclePath,$cyclePath.'/close',[],'Cycle closed to new applications');
$admin->postWithCsrf($cyclePath,$cyclePath.'/archive',[],'Closed cycle archived');
$copyStart=date('Y-m-d\TH:i',strtotime('+60 days'));
$copyEnd=date('Y-m-d\TH:i',strtotime('+120 days'));
$admin->postWithCsrf($cyclePath,$cyclePath.'/duplicate',['academic_session_id'=>1,'name'=>'CI Duplicated Cycle','code'=>'CI-COPY-29','slug'=>'ci-duplicated-cycle','starts_at'=>$copyStart,'ends_at'=>$copyEnd,'correction_deadline'=>date('Y-m-d\TH:i',strtotime('+127 days'))],'Cycle duplicated as a draft without applications or allocations');
$copyId=(int)$ciDb->query("SELECT id FROM admission_cycles WHERE code='CI-COPY-29'")->fetchColumn();
$copy=$ciDb->query('SELECT status,configuration_version FROM admission_cycles WHERE id='.$copyId)->fetch();
if(($copy['status']??'')!=='draft'||(int)$copy['configuration_version']!==0||(int)$ciDb->query('SELECT COUNT(*) FROM applications WHERE admission_cycle_id='.$copyId)->fetchColumn()!==0||(int)$ciDb->query('SELECT COUNT(*) FROM seat_allocations sa JOIN cycle_programs cp ON cp.id=sa.cycle_program_id WHERE cp.admission_cycle_id='.$copyId)->fetchColumn()!==0)throw new RuntimeException('Cycle duplication copied operational records or failed to reset lifecycle state.');
$sourceFieldCount=(int)$ciDb->query('SELECT COUNT(*) FROM admission_form_fields WHERE admission_cycle_id='.$cycleId)->fetchColumn();
$copyFieldCount=(int)$ciDb->query('SELECT COUNT(*) FROM admission_form_fields WHERE admission_cycle_id='.$copyId)->fetchColumn();
if($sourceFieldCount!==$copyFieldCount)throw new RuntimeException('Cycle duplication did not preserve form configuration.');
$copyPath='/admin/admissions/'.$copyId;
$copyProgramId=(int)$ciDb->query('SELECT id FROM cycle_programs WHERE admission_cycle_id='.$copyId.' AND program_id=1')->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/form-fields/'.$fieldId.'/delete',[],'Form field not found');
$admin->postWithCsrf($copyPath,$copyPath.'/form-sections',['section_key'=>'ci_disposable','title'=>'CI disposable section','description'=>'Deletion endpoint test','sort_order'=>900,'status'=>'active'],'Form section saved');
$disposableSectionId=(int)$ciDb->query("SELECT id FROM admission_form_sections WHERE admission_cycle_id={$copyId} AND section_key='ci_disposable'")->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/form-fields',['section_id'=>$disposableSectionId,'field_key'=>'ci_disposable_field','label'=>'CI disposable field','field_type'=>'text','sort_order'=>1,'status'=>'active'],'Form field saved');
$disposableFieldId=(int)$ciDb->query("SELECT id FROM admission_form_fields WHERE admission_cycle_id={$copyId} AND field_key='ci_disposable_field'")->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/form-fields/'.$disposableFieldId.'/delete',[],'Draft form field removed');
$admin->postWithCsrf($copyPath,$copyPath.'/form-sections/'.$disposableSectionId.'/delete',[],'Empty form section removed');
$copyRuleId=(int)$ciDb->query('SELECT id FROM eligibility_rules WHERE cycle_program_id='.$copyProgramId.' ORDER BY id LIMIT 1')->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/programs/'.$copyProgramId.'/eligibility/'.$copyRuleId.'/delete',[],'Eligibility rule removed');
$admin->postWithCsrf($copyPath,$copyPath.'/documents',['document_type_id'=>2,'program_id'=>'','category'=>'','stage'=>'application','sort_order'=>910],'Document requirement saved');
$copyRequirementId=(int)$ciDb->query('SELECT id FROM cycle_document_requirements WHERE admission_cycle_id='.$copyId.' AND document_type_id=2')->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/documents/'.$copyRequirementId.'/delete',[],'Document requirement removed');
$admin->postWithCsrf($copyPath,$copyPath.'/programs/'.$copyProgramId.'/fees',['fee_type'=>'application_fee','category_code'=>'OBC-A','label'=>'CI disposable fee','amount'=>1,'late_fee_amount'=>0,'status'=>'active'],'Fee rule saved');
$copyFeeId=(int)$ciDb->query("SELECT id FROM admission_fee_rules WHERE cycle_program_id={$copyProgramId} AND category_code='OBC-A' ORDER BY id DESC LIMIT 1")->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/programs/'.$copyProgramId.'/fees/'.$copyFeeId.'/delete',[],'Draft fee rule removed');
$copySeatId=(int)$ciDb->query('SELECT id FROM seat_matrix WHERE cycle_program_id='.$copyProgramId.' ORDER BY id LIMIT 1')->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/programs/'.$copyProgramId.'/seats',['seat_capacity'=>12,'seats'=>[$copySeatId=>10],'new_category'=>'OBC-A','new_quota'=>'state','new_seats'=>2],'Seat matrix saved');
$copyDisposableSeatId=(int)$ciDb->query("SELECT id FROM seat_matrix WHERE cycle_program_id={$copyProgramId} AND category='OBC-A' AND quota='state'")->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/programs/'.$copyProgramId.'/seats/'.$copyDisposableSeatId.'/delete',[],'Seat row removed and programme capacity recalculated');
$admin->postWithCsrf('/admin/admissions','/admin/admissions/programs',['name'=>'CI Disposable Programme','code'=>'CIDISP','award_type'=>'Certificate','duration_years'=>1,'total_semesters'=>2,'summary'=>'Delete endpoint fixture'],'Programme added to the catalogue');
$disposableProgramId=(int)$ciDb->query("SELECT id FROM programs WHERE code='CIDISP'")->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/programs',['program_id'=>$disposableProgramId,'seat_capacity'=>1,'application_fee'=>0,'admission_fee'=>0,'minimum_marks_general'=>0,'minimum_marks_reserved'=>0,'min_age'=>0,'max_age'=>99,'accepted_entrance_exams'=>''],'Programme added');
$disposableCycleProgramId=(int)$ciDb->query('SELECT id FROM cycle_programs WHERE admission_cycle_id='.$copyId.' AND program_id='.$disposableProgramId)->fetchColumn();
$admin->postWithCsrf($copyPath,$copyPath.'/programs/'.$disposableCycleProgramId.'/delete',[],'Programme and its draft rules, seats and fees were removed');
if((int)$ciDb->query('SELECT COUNT(*) FROM admission_form_sections WHERE id='.$disposableSectionId)->fetchColumn()!==0||(int)$ciDb->query('SELECT COUNT(*) FROM cycle_document_requirements WHERE id='.$copyRequirementId)->fetchColumn()!==0||(int)$ciDb->query('SELECT COUNT(*) FROM cycle_programs WHERE id='.$disposableCycleProgramId)->fetchColumn()!==0)throw new RuntimeException('One or more guarded draft delete endpoints did not remove the intended record.');
echo "PASS guarded draft configuration delete matrix\n";
$public->expectStatus('/admissions/ci-editable-cycle',404);
$public->expectStatus('/admissions/ci-duplicated-cycle',404);
$admin->get('/admin/reports?cycle='.$copyId,'No records for this filter.');

foreach ([
    '/admin/dashboard' => 'Admissions overview', '/admin/admissions' => 'Admission management',
    '/admin/admissions/1' => 'Publication readiness', '/admin/admissions/1/preview' => 'Programme choices',
    '/admin/applications' => 'Applications', '/admin/reports' => 'Admissions reports',
    '/admin/applications/1' => 'Candidate profile', '/admin/cms' => 'Content management',
    '/admin/cms/notices' => 'Notices records', '/admin/cms/faculty' => 'Faculty directory records',
    '/admin/cms/facilities' => 'Facilities records', '/admin/cms/faqs' => 'Frequently asked questions records',
    '/admin/cms/gallery' => 'Gallery records', '/admin/support' => 'Applicant support',
    '/admin/enquiries' => 'Contact inbox', '/admin/settings' => 'College & admission settings',
    '/admin/users' => 'Users & roles', '/admin/roles' => 'Roles & permissions',
    '/admin/audit' => 'Audit trail', '/admin/backups' => 'Backup & recovery',
] as $path => $needle) $admin->get($path, $needle);
$formulaUserId=(int)$ciDb->query('SELECT user_id FROM applications WHERE admission_cycle_id=1 ORDER BY id LIMIT 1')->fetchColumn();
$originalFirstName=(string)$ciDb->query('SELECT first_name FROM users WHERE id='.$formulaUserId)->fetchColumn();
$setFormulaName=$ciDb->prepare('UPDATE users SET first_name=? WHERE id=?');$setFormulaName->execute(['=2+2',$formulaUserId]);
$export=$admin->request('GET','/admin/applications/export?cycle=1');
$formulaEscaped=str_contains($export['body'],"'=2+2");
$setFormulaName->execute([$originalFirstName,$formulaUserId]);
if($export['status']!==200||!str_contains($export['content_type'],'text/csv')||!str_contains($export['body'],'Application No.')||!str_contains($export['body'],'NCP-APP-2027'))throw new RuntimeException('Filtered application CSV export failed.');
if(!$formulaEscaped)throw new RuntimeException('Spreadsheet formula injection was not escaped in the application CSV.');
echo "PASS filtered application CSV export and formula escaping\n";

$reviewer=new BrowserSession($base);
$reviewer->login('reviewer@demo.test','DemoReviewer#2027','Administration');
$reviewer->get('/admin/applications/2','Candidate profile');
$reviewer->expectStatus('/admin/applications/1',403);
$reviewer->expectStatus('/admin/applications/1/generated/application',403);
$reviewer->expectStatus('/admin/admissions/create',403);
$reviewer->postExpectStatus('/admin/admissions/1','/admin/admissions/1/publish',[],403);
$reviewer->postExpectStatus('/admin/admissions/1','/admin/admissions/'.$copyId.'/form-fields',['section_id'=>1,'field_key'=>'forbidden','label'=>'Forbidden','field_type'=>'text'],403);

$accounts=new BrowserSession($base);
$accounts->login('accounts@demo.test','DemoAccounts#2027','Administration');
$accounts->get('/admin/admissions','Admission management');
$accounts->get('/admin/reports','Admissions reports');
$accounts->expectStatus('/admin/admissions/create',403);
$accounts->postExpectStatus('/admin/admissions/1','/admin/admissions/1/close',[],403);

echo "Public-cycle selection, dynamic form, protected upload, payment, lifecycle, reports, admin RBAC, reviewer IDOR, CMS and PDF HTTP tests passed.\n";
