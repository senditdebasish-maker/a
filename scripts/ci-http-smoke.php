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
            throw new RuntimeException("GET {$path} failed ({$response['status']}); expected text: {$needle}");
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
        if($response['status']!==200||!str_contains($response['body'],$needle))throw new RuntimeException("POST {$action} failed ({$response['status']}); expected text: {$needle}");
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
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/programs',['program_id'=>1,'seat_capacity'=>10,'application_fee'=>500,'admission_fee'=>5000,'minimum_marks_general'=>45,'minimum_marks_reserved'=>40,'min_age'=>17,'max_age'=>30,'accepted_entrance_exams'=>'WBJEE'],'Programme added');
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/programs/2/eligibility',['rule_type'=>'marks','field_name'=>'class_12_percentage','operator'=>'gte','comparison_value'=>'45','message'=>'Minimum marks required','is_blocking'=>1,'sort_order'=>10],'Eligibility rule saved');
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/programs/2/eligibility',['rule_id'=>4,'rule_type'=>'marks','field_name'=>'class_12_percentage','operator'=>'gte','comparison_value'=>'50','message'=>'Updated minimum marks required','is_blocking'=>1,'sort_order'=>20],'Eligibility rule saved');
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/form-fields',['section_id'=>6,'field_key'=>'ci_choice','label'=>'CI choice','field_type'=>'select','options'=>"yes|Yes\nno|No",'help_text'=>'Editable option test','is_required'=>1,'sort_order'=>10],'Form field saved');
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/form-fields',['field_id'=>26,'section_id'=>6,'field_key'=>'ci_choice','label'=>'Updated CI choice','field_type'=>'radio','options'=>"yes|Yes please\nno|No thanks",'help_text'=>'Updated option test','conditional_rules'=>'{"field":"category","operator":"eq","value":"General"}','is_required'=>1,'sort_order'=>20,'status'=>'active'],'Form field saved');
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/programs/2/seats',['seat_capacity'=>12,'seats'=>[7=>12]],'Seat matrix saved');
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/programs/2/fees',['fee_rule_id'=>3,'fee_type'=>'application_fee','category_code'=>'','label'=>'Updated application fee','amount'=>600,'late_fee_amount'=>50,'refund_policy'=>'Non-refundable after submission.','status'=>'active'],'Fee rule saved');
$admin->postWithCsrf('/admin/admissions/2','/admin/admissions/2/documents',['document_type_id'=>1,'program_id'=>'','category'=>'','stage'=>'application','sort_order'=>10,'is_required'=>1],'Document requirement saved');
$admin->get('/admin/admissions/2','Updated CI choice');
foreach ([
    '/admin/dashboard' => 'Admissions overview', '/admin/admissions' => 'Admission management',
    '/admin/admissions/1' => 'Readiness validation', '/admin/admissions/1/preview' => 'Programme choices',
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
