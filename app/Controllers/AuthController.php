<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\MailService;

final class AuthController extends Controller
{
    public function login(): void
    {
        $this->view('auth/login', ['title' => 'Sign in to your portal'], 'auth');
    }

    public function authenticate(): never
    {
        $email = mb_strtolower(trim((string) ($this->input('email') ?? '')));
        $password = (string) ($this->input('password') ?? '');
        if (Auth::throttled($email)) {
            Flash::withInput(['email' => $email]);
            Flash::withErrors(['email' => 'Too many unsuccessful attempts. Please wait 15 minutes.']);
            $this->redirect('login');
        }
        if (!Auth::attempt($email, $password)) {
            Flash::withInput(['email' => $email]);
            Flash::withErrors(['email' => 'The email or password is incorrect.']);
            $this->redirect('login');
        }
        $user = Auth::user();
        if (!$user['email_verified_at']) {
            Auth::logout();
            Flash::set('warning', 'Verify your email address before signing in.');
            $this->redirect('login');
        }

        if (!Auth::hasRole('applicant') && (bool) config('security.require_staff_mfa', true)) {
            $userId = (int) $user['id'];
            Auth::logout();
            $_SESSION['mfa_pending_user_id'] = $userId;
            if (!$this->sendStaffMfaCode($userId, $email)) {
                unset($_SESSION['mfa_pending_user_id']);
                Flash::set('warning', 'The staff OTP could not be delivered. Configure working SMTP in .env, or temporarily set REQUIRE_STAFF_MFA=false during local setup.');
                $this->redirect('login');
            }
            Flash::set('success', 'A six-digit sign-in code was sent to your staff email address.');
            $this->redirect('mfa');
        }

        AuditService::log('login', 'user', $user['id']);
        $this->redirect(Auth::hasRole('applicant') ? 'student/dashboard' : 'admin/dashboard');
    }

    public function mfa(): void
    {
        if (empty($_SESSION['mfa_pending_user_id'])) {
            $this->redirect('login');
        }
        $this->view('auth/mfa', ['title' => 'Confirm it’s you'], 'auth');
    }

    public function resendMfa(): never
    {
        $userId = (int) ($_SESSION['mfa_pending_user_id'] ?? 0);
        $db = Database::get();
        $user = $userId ? $db->fetch('SELECT id, email, status FROM users WHERE id = :id', ['id' => $userId]) : null;
        if (!$user || $user['status'] !== 'active') {
            unset($_SESSION['mfa_pending_user_id']);
            Flash::set('warning', 'The sign-in session expired. Please sign in again.');
            $this->redirect('login');
        }
        $lastCreated = $db->scalar('SELECT created_at FROM mfa_challenges WHERE user_id = :id ORDER BY id DESC LIMIT 1', ['id' => $userId]);
        if ($lastCreated && strtotime((string) $lastCreated) > time() - 60) {
            Flash::set('warning', 'Please wait one minute before requesting another code.');
            $this->redirect('mfa');
        }
        if (!$this->sendStaffMfaCode($userId, (string) $user['email'])) {
            Flash::set('warning', 'The code could not be delivered. Check the SMTP settings and Admin email-delivery logs.');
            $this->redirect('mfa');
        }
        Flash::set('success', 'A new six-digit code was sent. The previous code is no longer valid.');
        $this->redirect('mfa');
    }

    public function verifyMfa(): never
    {
        $userId = (int) ($_SESSION['mfa_pending_user_id'] ?? 0);
        $challenge = Database::get()->fetch('SELECT * FROM mfa_challenges WHERE user_id = :id AND used_at IS NULL ORDER BY id DESC LIMIT 1', ['id' => $userId]);
        $code = trim((string) $this->input('code'));
        if (!$challenge || (int) $challenge['attempts'] >= 5 || $challenge['expires_at'] < date('Y-m-d H:i:s') || !password_verify($code, (string) $challenge['code_hash'])) {
            if ($challenge) {
                $attempts = (int) $challenge['attempts'] + 1;
                $updates = ['attempts' => $attempts];
                if ($attempts >= 5 || $challenge['expires_at'] < date('Y-m-d H:i:s')) $updates['used_at'] = date('Y-m-d H:i:s');
                Database::get()->update('mfa_challenges', $updates, 'id = :id', ['id' => $challenge['id']]);
            }
            Flash::withErrors(['code' => 'The code is invalid or expired. Request a new code if needed.']);
            $this->redirect('mfa');
        }
        Database::get()->update('mfa_challenges', ['used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $challenge['id']]);
        unset($_SESSION['mfa_pending_user_id']);
        Auth::loginById($userId);
        $_SESSION['mfa_verified_at'] = time();
        AuditService::log('mfa_verified', 'user', $userId);
        $this->redirect('admin/dashboard');
    }

    public function register(): void
    {
        $cycle = Database::get()->fetch("SELECT * FROM admission_cycles WHERE status = 'open' ORDER BY starts_at DESC LIMIT 1");
        $this->view('auth/register', ['cycle' => $cycle, 'title' => 'Create applicant account'], 'auth');
    }

    public function storeRegistration(): never
    {
        $now = date('Y-m-d H:i:s');
        $openCycle = Database::get()->fetch("SELECT id FROM admission_cycles WHERE status = 'open' AND starts_at <= :starts_now AND ends_at >= :ends_now ORDER BY starts_at DESC LIMIT 1", ['starts_now' => $now, 'ends_now' => $now]);
        if (!$openCycle) {
            Flash::set('warning', 'Public registration is not open. Please review the published admission dates or contact the college.');
            $this->redirect('register');
        }
        $validator = new Validator();
        $errors = $validator->validate($_POST, [
            'first_name' => 'required|max:80', 'last_name' => 'required|max:80', 'email' => 'required|email|max:190',
            'mobile' => 'required|min:10|max:15', 'password' => 'required|min:10|confirmed', 'terms' => 'required',
        ]);
        $email = mb_strtolower(trim((string) ($this->input('email') ?? '')));
        $candidatePassword = (string) ($this->input('password') ?? '');
        if (!preg_match('/[A-Z]/', $candidatePassword) || !preg_match('/[a-z]/', $candidatePassword) || !preg_match('/\d/', $candidatePassword) || !preg_match('/[^A-Za-z0-9]/', $candidatePassword)) {
            $errors['password'][] = 'Use upper-case, lower-case, a number, and a symbol.';
        }
        if (Database::get()->fetch('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email])) {
            $errors['email'][] = 'An account already exists for this email address.';
        }
        if ($errors) {
            Flash::withInput($_POST);
            Flash::withErrors($errors);
            $this->redirect('register');
        }

        $token = bin2hex(random_bytes(32));
        $db = Database::get();
        $userId = $db->transaction(function (Database $db) use ($email, $token): int {
            $id = $db->insert('users', [
                'first_name' => trim((string) $_POST['first_name']), 'last_name' => trim((string) $_POST['last_name']),
                'email' => $email, 'mobile' => preg_replace('/\s+/', '', (string) $_POST['mobile']),
                'password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT), 'status' => 'active',
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
            ? 'Account created. Open the verification email sent to your inbox.'
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

    public function forgot(): void
    {
        $this->view('auth/forgot', ['title' => 'Reset your password'], 'auth');
    }

    public function sendReset(): never
    {
        $email = mb_strtolower(trim((string) $this->input('email')));
        $user = Database::get()->fetch('SELECT id FROM users WHERE email = :email AND status = :status LIMIT 1', ['email' => $email, 'status' => 'active']);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            Database::get()->insert('password_resets', ['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'created_at' => date('Y-m-d H:i:s')]);
            (new MailService())->send($email, 'Reset your Netaji portal password', '<p><a href="' . e(url('reset-password/' . $token)) . '">Reset your password</a></p><p>This link expires in one hour.</p>', 'password_reset');
        }
        Flash::set('success', 'If that address is registered, a reset link has been sent.');
        $this->redirect('forgot-password');
    }

    public function reset(string $token): void
    {
        $this->view('auth/reset', ['token' => $token, 'title' => 'Choose a new password'], 'auth');
    }

    public function updatePassword(string $token): never
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['password' => 'required|min:10|confirmed']);
        $candidatePassword = (string) ($this->input('password') ?? '');
        if (!preg_match('/[A-Z]/', $candidatePassword) || !preg_match('/[a-z]/', $candidatePassword) || !preg_match('/\d/', $candidatePassword) || !preg_match('/[^A-Za-z0-9]/', $candidatePassword)) {
            $errors['password'][] = 'Use upper-case, lower-case, a number, and a symbol.';
        }
        $reset = Database::get()->fetch('SELECT * FROM password_resets WHERE token_hash = :token AND used_at IS NULL AND expires_at >= :now ORDER BY id DESC LIMIT 1', ['token' => hash('sha256', $token), 'now' => date('Y-m-d H:i:s')]);
        if (!$reset) {
            $errors['password'][] = 'The reset link is invalid or has expired.';
        }
        if ($errors) {
            Flash::withErrors($errors);
            $this->redirect('reset-password/' . $token);
        }
        Database::get()->update('users', ['password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reset['user_id']]);
        Database::get()->update('password_resets', ['used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reset['id']]);
        Flash::set('success', 'Password updated. Sign in with your new password.');
        $this->redirect('login');
    }

    private function sendStaffMfaCode(int $userId, string $email): bool
    {
        if ((string) config('mail.driver', 'log') !== 'smtp') return false;
        $db = Database::get();
        $db->query('UPDATE mfa_challenges SET used_at = :now WHERE user_id = :user AND used_at IS NULL', ['now' => date('Y-m-d H:i:s'), 'user' => $userId]);
        $code = (string) random_int(100000, 999999);
        $challengeId = $db->insert('mfa_challenges', [
            'user_id' => $userId, 'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'attempts' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 600), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $sent = (new MailService())->send($email, 'Your Netaji staff sign-in code', '<p>Your verification code is <strong>' . e($code) . '</strong>.</p><p>It expires in 10 minutes. Do not share this code.</p>', 'staff_mfa');
        if (!$sent) $db->update('mfa_challenges', ['used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $challengeId]);
        return $sent;
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
