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
foreach ([
    '/admin/dashboard' => 'Admissions overview', '/admin/applications' => 'Applications',
    '/admin/applications/1' => 'Candidate profile', '/admin/cms' => 'Content management',
    '/admin/cms/notices' => 'Notices records', '/admin/cms/faculty' => 'Faculty directory records',
    '/admin/cms/facilities' => 'Facilities records', '/admin/cms/faqs' => 'Frequently asked questions records',
    '/admin/cms/gallery' => 'Gallery records', '/admin/support' => 'Applicant support',
    '/admin/enquiries' => 'Contact inbox', '/admin/settings' => 'College & admission settings',
    '/admin/users' => 'Users & roles', '/admin/roles' => 'Roles & permissions',
    '/admin/audit' => 'Audit trail', '/admin/backups' => 'Backup & recovery',
] as $path => $needle) $admin->get($path, $needle);

echo "Public, applicant, staff, CMS and PDF HTTP smoke tests passed.\n";
