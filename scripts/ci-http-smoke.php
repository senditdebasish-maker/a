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
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
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
$admin->postWithCsrf('/admin/admissions/create','/admin/admissions',['academic_session_id'=>1,'name'=>'CI Editable Cycle','code'=>'CI-EDIT-28','slug'=>'ci-editable-cycle','starts_at'=>'2028-01-01T10:00','ends_at'=>'2028-06-30T23:59','correction_deadline'=>'2028-07-07T23:59','application_number_prefix'=>'CI-APP-28','max_program_preferences'=>3,'closing_soon_hours'=>72,'summary'=>'Editable cycle smoke test','instructions'=>'Complete all configured requirements.','declaration_text'=>'I confirm the submitted information is correct.'],'Draft admission cycle created');
$ciDb=new PDO('mysql:host='.(getenv('DB_HOST')?:'127.0.0.1').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'ncp_test').';charset=utf8mb4',getenv('DB_USERNAME')?:'root',getenv('DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
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
$seatId=(int)$ciDb->query('SELECT id FROM seat_matrix WHERE cycle_program_id='.$cycleProgramId.' ORDER BY id LIMIT 1')->fetchColumn();
$admin->postWithCsrf($cyclePath,$programPath.'/seats',['seat_capacity'=>12,'seats'=>[$seatId=>12]],'Seat matrix saved');
$feeId=(int)$ciDb->query("SELECT id FROM admission_fee_rules WHERE cycle_program_id={$cycleProgramId} AND fee_type='application_fee' ORDER BY id LIMIT 1")->fetchColumn();
$admin->postWithCsrf($cyclePath,$programPath.'/fees',['fee_rule_id'=>$feeId,'fee_type'=>'application_fee','category_code'=>'','label'=>'Updated application fee','amount'=>600,'late_fee_amount'=>50,'refund_policy'=>'Non-refundable after submission.','status'=>'active'],'Fee rule saved');
$admin->postWithCsrf($cyclePath,$cyclePath.'/documents',['document_type_id'=>1,'program_id'=>'','category'=>'','stage'=>'application','sort_order'=>10,'is_required'=>1],'Document requirement saved');
$admin->get($cyclePath,'Updated CI choice');
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

$reviewer=new BrowserSession($base);
$reviewer->login('reviewer@demo.test','DemoReviewer#2027','Administration');
$reviewer->get('/admin/applications/2','Candidate profile');
$reviewer->expectStatus('/admin/applications/1',403);
$reviewer->expectStatus('/admin/applications/1/generated/application',403);

echo "Public, applicant, staff, reviewer IDOR, CMS and PDF HTTP smoke tests passed.\n";
