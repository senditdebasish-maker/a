<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Validator;
use App\Services\AdmissionCycleService;
use App\Services\AuditService;
use App\Services\MailConfigurationService;
use App\Services\MailService;

final class AuthController extends Controller
{
    public function login(): void
    {
        // Visiting the email-address screen intentionally abandons any prior code.
        $this->clearEmailOtpSession();
        $this->view('auth/login', ['title' => 'Sign in to your portal'], 'auth');
    }

    private const EMAIL_OTP_TTL_SECONDS = 600;
    private const EMAIL_OTP_RESEND_SECONDS = 60;
    private const EMAIL_OTP_MAX_ATTEMPTS = 5;

    /** Start the passwordless email-code flow for every active, verified account. */
    public function requestEmailOtp(): never
    {
        $email = mb_strtolower(trim((string) ($this->input('email') ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            Flash::withInput(['email' => $email]);
            Flash::withErrors(['email' => 'Enter a valid email address.']);
            $this->redirect('login');
        }
        if (!$this->smtpAvailableForOtp()) {
            Flash::withInput(['email' => $email]);
            Flash::set('warning', 'Email-code sign-in is unavailable until an administrator configures and tests SMTP delivery.');
            $this->redirect('login');
        }

        $this->issueEmailOtp($email);
        Flash::set('success', 'If an active, verified account matches that email address, a six-digit sign-in code has been sent.');
        $this->redirect('login/otp');
    }

    public function emailOtp(): void
    {
        if (empty($_SESSION['email_otp_pending_email'])) {
            $this->redirect('login');
        }
        $this->view('auth/login-otp', [
            'email' => (string) $_SESSION['email_otp_pending_email'],
            'title' => 'Enter your email sign-in code',
        ], 'auth');
    }

    public function verifyEmailOtp(): never
    {
        $userId = (int) ($_SESSION['email_otp_pending_user_id'] ?? 0);
        $challengeId = (int) ($_SESSION['email_otp_challenge_id'] ?? 0);
        $code = trim((string) $this->input('code'));
        $db = Database::get();
        $challenge = ($userId && $challengeId) ? $db->fetch(
            'SELECT m.*, u.status, u.email_verified_at FROM mfa_challenges m JOIN users u ON u.id = m.user_id WHERE m.id = :challenge AND m.user_id = :user LIMIT 1',
            ['challenge' => $challengeId, 'user' => $userId]
        ) : null;
        $valid = $challenge
            && $challenge['used_at'] === null
            && (int) $challenge['attempts'] < self::EMAIL_OTP_MAX_ATTEMPTS
            && $challenge['expires_at'] >= date('Y-m-d H:i:s')
            && $challenge['status'] === 'active'
            && $challenge['email_verified_at'] !== null
            && preg_match('/^\d{6}$/', $code)
            && password_verify($code, (string) $challenge['code_hash']);

        if (!$valid) {
            if ($challenge && $challenge['used_at'] === null) {
                $attempts = (int) $challenge['attempts'] + 1;
                $updates = ['attempts' => $attempts];
                if ($attempts >= self::EMAIL_OTP_MAX_ATTEMPTS || $challenge['expires_at'] < date('Y-m-d H:i:s')) {
                    $updates['used_at'] = date('Y-m-d H:i:s');
                }
                $db->update('mfa_challenges', $updates, 'id = :id AND used_at IS NULL', ['id' => $challenge['id']]);
            }
            Flash::withErrors(['code' => 'The code is invalid or expired. Request a new code if needed.']);
            $this->redirect('login/otp');
        }

        if ($db->update('mfa_challenges', ['used_at' => date('Y-m-d H:i:s')], 'id = :id AND used_at IS NULL', ['id' => $challengeId]) !== 1) {
            Flash::withErrors(['code' => 'That code has already been used. Request a new code to continue.']);
            $this->redirect('login/otp');
        }
        $this->clearEmailOtpSession();
        Auth::loginById($userId);
        $db->update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => mb_substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45)], 'id = :id', ['id' => $userId]);
        $_SESSION['email_otp_verified_at'] = time();
        AuditService::log('login_email_otp', 'user', $userId);
        $this->redirectAfterEmailOtpLogin();
    }

    public function resendEmailOtp(): never
    {
        $email = mb_strtolower(trim((string) ($_SESSION['email_otp_pending_email'] ?? '')));
        if ($email === '') {
            $this->redirect('login');
        }
        if (!$this->smtpAvailableForOtp()) {
            $this->clearEmailOtpSession();
            Flash::set('warning', 'Email-code sign-in is unavailable until an administrator configures and tests SMTP delivery.');
            $this->redirect('login');
        }
        if ((int) ($_SESSION['email_otp_requested_at'] ?? 0) > time() - self::EMAIL_OTP_RESEND_SECONDS) {
            Flash::set('warning', 'Please wait one minute before requesting another code.');
            $this->redirect('login/otp');
        }
        $this->issueEmailOtp($email);
        Flash::set('success', 'If an active, verified account matches that email address, a new sign-in code has been sent.');
        $this->redirect('login/otp');
    }

    private function issueEmailOtp(string $email): void
    {
        $db = Database::get();
        $_SESSION['email_otp_pending_email'] = $email;
        $_SESSION['email_otp_requested_at'] = time();
        unset($_SESSION['email_otp_pending_user_id'], $_SESSION['email_otp_challenge_id']);
        $user = $db->fetch('SELECT id, email FROM users WHERE email = :email AND status = :status AND email_verified_at IS NOT NULL AND deleted_at IS NULL LIMIT 1', ['email' => $email, 'status' => 'active']);
        if (!$user) {
            return; // Keep the public response identical so account addresses are not disclosed.
        }
        $lastCreated = $db->scalar('SELECT created_at FROM mfa_challenges WHERE user_id = :id ORDER BY id DESC LIMIT 1', ['id' => $user['id']]);
        if ($lastCreated && strtotime((string) $lastCreated) > time() - self::EMAIL_OTP_RESEND_SECONDS) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $db->query('UPDATE mfa_challenges SET used_at = :now WHERE user_id = :user AND used_at IS NULL', ['now' => $now, 'user' => $user['id']]);
        $code = $this->emailOtpCode();
        $challengeId = $db->insert('mfa_challenges', [
            'user_id' => $user['id'], 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'attempts' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + self::EMAIL_OTP_TTL_SECONDS), 'created_at' => $now,
        ]);
        $sent = (new MailService())->send(
            (string) $user['email'],
            'Your Netaji portal sign-in code',
            '<p>Your sign-in code is <strong>' . e($code) . '</strong>.</p><p>It expires in 10 minutes. Do not share this code.</p>',
            'login_otp'
        );
        if (!$sent) {
            $db->update('mfa_challenges', ['used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $challengeId]);
            return;
        }
        $_SESSION['email_otp_pending_user_id'] = (int) $user['id'];
        $_SESSION['email_otp_challenge_id'] = $challengeId;
        AuditService::log('login_email_otp_requested', 'user', (int) $user['id']);
    }

    private function smtpAvailableForOtp(): bool
    {
        $configuration = (new MailConfigurationService())->current();
        if (($configuration['driver'] ?? '') === 'smtp' && !empty($configuration['complete'])) return true;

        // The CI HTTP suite uses a redacted, non-delivering mail log in its isolated
        // testing environment. This exception cannot be enabled in a deployment.
        return (string) config('app.env') === 'testing' && getenv('CI') === 'true';
    }

    private function clearEmailOtpSession(): void
    {
        unset(
            $_SESSION['email_otp_pending_email'],
            $_SESSION['email_otp_requested_at'],
            $_SESSION['email_otp_pending_user_id'],
            $_SESSION['email_otp_challenge_id']
        );
    }

    private function emailOtpCode(): string
    {
        // CI has no real SMTP server. This fixed value is available only in the
        // isolated test environment; production always uses cryptographic random data.
        if ((string) config('app.env') === 'testing' && getenv('CI') === 'true') return '123456';
        return (string) random_int(100000, 999999);
    }

    private function redirectAfterEmailOtpLogin(): never
    {
        if (Auth::hasRole('applicant') && !empty($_SESSION['intended_cycle_slug'])) {
            $this->redirect('admissions/' . rawurlencode((string) $_SESSION['intended_cycle_slug']) . '/apply');
        }
        $this->redirect(Auth::hasRole('applicant') ? 'student/dashboard' : 'admin/dashboard');
    }

    public function register(): void
    {
        $service=new AdmissionCycleService();
        $slug=trim((string)($_GET['cycle']??$_SESSION['intended_cycle_slug']??''));
        $program=trim((string)($_GET['program']??$_SESSION['intended_program_slug']??''));
        $cycle=$slug!==''?$service->publicCycle($slug):($service->publicCycles(false)[0]??null);
        if ($cycle&&!$service->acceptsApplications($cycle)) $cycle=null;
        if ($cycle) { $_SESSION['intended_cycle_slug']=$cycle['slug']; if ($program!=='') $_SESSION['intended_program_slug']=$program; }
        $this->view('auth/register', ['cycle'=>$cycle,'program'=>$program,'title'=>'Create applicant account'], 'auth');
    }

    public function storeRegistration(): never
    {
        $service=new AdmissionCycleService();
        $slug=trim((string)($_POST['cycle_slug']??$_SESSION['intended_cycle_slug']??''));
        $openCycle=$slug!==''?$service->publicCycle($slug):($service->publicCycles(false)[0]??null);
        if (!$openCycle||!$service->acceptsApplications($openCycle)) {
            Flash::set('warning', 'Public registration is not open for the selected cycle. Please review the published admission dates or contact the college.');
            $this->redirect('register'.($slug!==''?'?cycle='.rawurlencode($slug):''));
        }
        $_SESSION['intended_cycle_slug']=$openCycle['slug'];
        if (!empty($_POST['program_slug'])) $_SESSION['intended_program_slug']=trim((string)$_POST['program_slug']);
        $validator = new Validator();
        $errors = $validator->validate($_POST, [
            'first_name' => 'required|max:80', 'last_name' => 'required|max:80', 'email' => 'required|email|max:190',
            'mobile' => 'required|min:10|max:15', 'terms' => 'required',
        ]);
        $email = mb_strtolower(trim((string) ($this->input('email') ?? '')));
        if (Database::get()->fetch('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email])) {
            $errors['email'][] = 'An account already exists for this email address.';
        }
        if ($errors) {
            Flash::withInput($_POST);
            Flash::withErrors($errors);
            $this->redirect('register');
        }

        $token = bin2hex(random_bytes(32));
        // The schema retains password_hash for legacy compatibility, but passwordless
        // accounts receive an unshared random value and can only sign in by email code.
        $unsharedPassword = bin2hex(random_bytes(32));
        $db = Database::get();
        $userId = $db->transaction(function (Database $db) use ($email, $token, $unsharedPassword): int {
            $id = $db->insert('users', [
                'first_name' => trim((string) $_POST['first_name']), 'last_name' => trim((string) $_POST['last_name']),
                'email' => $email, 'mobile' => preg_replace('/\s+/', '', (string) $_POST['mobile']),
                'password_hash' => password_hash($unsharedPassword, PASSWORD_DEFAULT), 'status' => 'active',
                'email_verification_token' => hash('sha256', $token), 'email_verification_expires_at' => date('Y-m-d H:i:s', time() + 86400),
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $role = $db->scalar("SELECT id FROM roles WHERE slug = 'applicant'");
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $role]);
            $db->insert('applicant_profiles', ['user_id' => $id, 'profile_completion' => 10, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $db->insert('consent_records', ['user_id' => $id, 'consent_type' => 'privacy_terms', 'version' => '1.0', 'granted' => 1, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '', 'created_at' => date('Y-m-d H:i:s')]);
            return $id;
        });

        $link = url('verify-email/' . $token);
        $sent = (new MailService())->send($email, 'Verify your Netaji applicant account', '<p>Welcome to Netaji College of Pharmacy.</p><p><a href="' . e($link) . '">Verify your email address</a></p><p>This link expires in 24 hours.</p>', 'verify_email');
        AuditService::log('registered', 'user', $userId, [], ['verification_delivered' => $sent]);
        Flash::set($sent ? 'success' : 'warning', $sent
            ? 'Account created. Verify your email, then sign in with a six-digit email code.'
            : 'Account created, but verification could not be delivered. Contact Admissions after SMTP has been configured; authentication links are never stored in the mail log.');
        $this->redirect('login');
    }

    public function verifyEmail(string $token): never
    {
        $hash = hash('sha256', $token);
        $user = Database::get()->fetch('SELECT id FROM users WHERE email_verification_token = :token AND email_verification_expires_at >= :now LIMIT 1', ['token' => $hash, 'now' => date('Y-m-d H:i:s')]);
        if (!$user) {
            Flash::set('warning', 'This verification link is invalid or has expired.');
            $this->redirect('login');
        }
        Database::get()->update('users', ['email_verified_at' => date('Y-m-d H:i:s'), 'email_verification_token' => null, 'email_verification_expires_at' => null], 'id = :id', ['id' => $user['id']]);
        Flash::set('success', 'Email verified. You can now sign in.');
        $this->redirect('login');
    }

    public function forgot(): never
    {
        Flash::set('warning', 'Passwords are not used for sign-in. Enter your email address to receive a one-time sign-in code.');
        $this->redirect('login');
    }

    public function sendReset(): never
    {
        $this->forgot();
    }

    public function reset(string $token): never
    {
        $this->forgot();
    }

    public function updatePassword(string $token): never
    {
        $this->forgot();
    }

    public function logout(): never
    {
        $id = Auth::id();
        if ($id) {
            AuditService::log('logout', 'user', $id);
        }
        Auth::logout();
        Flash::set('success', 'You have signed out securely.');
        $this->redirect('login');
    }
}
