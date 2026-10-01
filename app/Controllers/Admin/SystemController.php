<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\MailService;
use Throwable;

final class SystemController extends Controller
{
    public function users(): void
    {
        $users = Database::get()->all("SELECT u.id, u.first_name, u.last_name, u.email, u.mobile, u.status, u.last_login_at, GROUP_CONCAT(r.name) AS roles, GROUP_CONCAT(r.id) AS role_ids FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id GROUP BY u.id ORDER BY u.created_at DESC LIMIT 250");
        $roles = Database::get()->all('SELECT * FROM roles ORDER BY name');
        $this->view('admin/users', compact('users', 'roles') + ['title' => 'Users & access'], 'admin');
    }

    public function createUser(): never
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, [
            'first_name' => 'required|max:80', 'last_name' => 'required|max:80', 'email' => 'required|email|max:190',
            'password' => 'required|min:12', 'role_id' => 'required|numeric',
        ]);
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) $errors['password'][] = 'Use upper-case, lower-case, a number, and a symbol.';
        $db = Database::get();
        if ($db->fetch('SELECT id FROM users WHERE email = :email', ['email' => $email])) $errors['email'][] = 'That email already belongs to a user.';
        $role = $db->fetch('SELECT id, slug FROM roles WHERE id = :id', ['id' => (int) ($_POST['role_id'] ?? 0)]);
        if (!$role || $role['slug'] === 'applicant') $errors['role_id'][] = 'Choose a staff role.';
        if ($errors) {
            Flash::withErrors($errors); Flash::withInput($_POST); Flash::set('warning', 'Review the staff account details.'); $this->redirect('admin/users');
        }
        $userId = $db->transaction(function (Database $db) use ($email, $password, $role): int {
            $id = $db->insert('users', [
                'first_name' => trim((string) $_POST['first_name']), 'last_name' => trim((string) $_POST['last_name']),
                'email' => $email, 'mobile' => trim((string) ($_POST['mobile'] ?? '')), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => 'active', 'preferred_locale' => 'en', 'email_verified_at' => date('Y-m-d H:i:s'), 'password_changed_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $role['id'], 'assigned_by' => \App\Core\Auth::id(), 'assigned_at' => date('Y-m-d H:i:s')]);
            return $id;
        });
        AuditService::log('staff_user_created', 'user', $userId, [], ['role' => $role['slug']]);
        Flash::set('success', 'Staff account created. Share the temporary password through an approved secure channel.');
        $this->redirect('admin/users');
    }

    public function updateUser(string $id): never
    {
        $status = (string) ($_POST['status'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);
        if ((int) $id === \App\Core\Auth::id() && $status !== 'active') {
            Flash::set('warning', 'You cannot suspend your own account.'); $this->redirect('admin/users');
        }
        $db = Database::get();
        $user = $db->fetch('SELECT * FROM users WHERE id = :id', ['id' => (int) $id]);
        $role = $db->fetch("SELECT * FROM roles WHERE id = :id AND slug <> 'applicant'", ['id' => $roleId]);
        if (!$user || !$role || !in_array($status, ['active','suspended'], true)) {
            Flash::set('warning', 'Invalid user update.'); $this->redirect('admin/users');
        }
        $db->transaction(function (Database $db) use ($id, $status, $roleId): void {
            $db->update('users', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $id]);
            $db->query('DELETE FROM user_roles WHERE user_id = :id', ['id' => (int) $id]);
            $db->insert('user_roles', ['user_id' => (int) $id, 'role_id' => $roleId, 'assigned_by' => \App\Core\Auth::id(), 'assigned_at' => date('Y-m-d H:i:s')]);
        });
        AuditService::log('staff_user_updated', 'user', $id, ['status' => $user['status']], ['status' => $status, 'role_id' => $roleId]);
        Flash::set('success', 'User access updated.');
        $this->redirect('admin/users');
    }

    public function roles(): void
    {
        $db = Database::get();
        $roles = $db->all("SELECT r.*, COUNT(DISTINCT ur.user_id) AS user_count FROM roles r LEFT JOIN user_roles ur ON ur.role_id = r.id GROUP BY r.id ORDER BY r.name");
        $permissions = $db->all('SELECT * FROM permissions ORDER BY module, name');
        $assigned = [];
        foreach ($db->all('SELECT role_id, permission_id FROM role_permissions') as $row) $assigned[(int) $row['role_id']][] = (int) $row['permission_id'];
        $this->view('admin/roles', compact('roles', 'permissions', 'assigned') + ['title' => 'Roles & permissions'], 'admin');
    }

    public function updateRole(string $id): never
    {
        $db = Database::get();
        $role = $db->fetch('SELECT * FROM roles WHERE id = :id', ['id' => (int) $id]);
        if (!$role || in_array($role['slug'], ['super-admin','applicant'], true)) {
            Flash::set('warning', 'This system role is protected from permission editing.'); $this->redirect('admin/roles');
        }
        $requested = array_values(array_unique(array_map('intval', (array) ($_POST['permissions'] ?? []))));
        $valid = array_map('intval', $db->query('SELECT id FROM permissions')->fetchAll(\PDO::FETCH_COLUMN));
        $requested = array_values(array_intersect($requested, $valid));
        $db->transaction(function (Database $db) use ($id, $requested): void {
            $db->query('DELETE FROM role_permissions WHERE role_id = :id', ['id' => (int) $id]);
            foreach ($requested as $permissionId) $db->insert('role_permissions', ['role_id' => (int) $id, 'permission_id' => $permissionId]);
        });
        AuditService::log('role_permissions_updated', 'role', $id, [], ['permission_ids' => $requested]);
        Flash::set('success', 'Role permissions updated. Existing sessions use the new rules immediately.');
        $this->redirect('admin/roles#role-' . $id);
    }

    public function settings(): void
    {
        $settings = [];
        foreach (Database::get()->all('SELECT * FROM settings ORDER BY group_name, key_name') as $row) $settings[$row['key_name']] = $row;
        $this->view('admin/settings', compact('settings') + ['title' => 'College & admission settings'], 'admin');
    }

    public function updateSettings(): never
    {
        $allowed = ['college_name','college_short_name','college_email','college_phone','college_address','upi_id','bank_details','aadhaar_collection_stage','privacy_contact','primary_color'];
        if (isset($_POST['aadhaar_collection_stage']) && !in_array($_POST['aadhaar_collection_stage'], ['disabled','application','post_selection','admission','configurable'], true)) {
            Flash::set('warning', 'Invalid Aadhaar collection policy.');
            $this->redirect('admin/settings#admission');
        }
        if (isset($_POST['aadhaar_collection_stage']) && $_POST['aadhaar_collection_stage'] !== 'disabled' && !isset($_POST['aadhaar_compliance_ack'])) {
            Flash::set('warning', 'Confirm the Aadhaar compliance acknowledgement before enabling collection.');
            $this->redirect('admin/settings#admission');
        }
        $db = Database::get();
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $_POST)) continue;
            $value = trim((string) $_POST[$key]);
            $existing = $db->fetch('SELECT id FROM settings WHERE key_name = :key', ['key' => $key]);
            if ($existing) $db->update('settings', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $existing['id']]);
            else $db->insert('settings', ['group_name' => 'college', 'key_name' => $key, 'value' => $value, 'is_public' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }
        AuditService::log('settings_updated', 'settings', null, [], ['aadhaar_stage' => $_POST['aadhaar_collection_stage'] ?? null, 'aadhaar_compliance_acknowledged' => isset($_POST['aadhaar_compliance_ack'])]);
        Flash::set('success', 'Settings updated.');
        $this->redirect('admin/settings');
    }

    public function enquiries(): void
    {
        $db = Database::get();
        $status = (string) ($_GET['status'] ?? '');
        $allowed = ['new','in_progress','replied','closed','spam'];
        $where = in_array($status, $allowed, true) ? 'WHERE status = :status' : '';
        $params = $where ? ['status' => $status] : [];
        $enquiries = $db->all("SELECT * FROM contact_submissions {$where} ORDER BY created_at DESC LIMIT 300", $params);
        $selected = !empty($_GET['view']) ? $db->fetch('SELECT * FROM contact_submissions WHERE id = :id', ['id' => (int) $_GET['view']]) : null;
        if ($selected && $selected['status'] === 'new') {
            $db->update('contact_submissions', ['status' => 'in_progress', 'assigned_to' => Auth::id()], 'id = :id', ['id' => $selected['id']]);
            $selected['status'] = 'in_progress';
            AuditService::log('contact_enquiry_assigned', 'contact_submission', $selected['id'], ['status' => 'new'], ['status' => 'in_progress']);
        }
        if ($selected) AuditService::log('contact_enquiry_viewed', 'contact_submission', $selected['id']);
        $this->view('admin/enquiries', compact('enquiries', 'selected', 'status') + ['title' => 'Contact enquiries'], 'admin');
    }

    public function updateEnquiry(string $id): never
    {
        $db = Database::get();
        $enquiry = $db->fetch('SELECT * FROM contact_submissions WHERE id = :id', ['id' => (int) $id]);
        $status = (string) ($_POST['status'] ?? '');
        if (!$enquiry || !in_array($status, ['new','in_progress','replied','closed','spam'], true)) { Flash::set('warning', 'Enquiry or status not found.'); $this->redirect('admin/enquiries'); }
        $data = ['status' => $status, 'assigned_to' => Auth::id()];
        if (in_array($status, ['replied','closed'], true)) $data['responded_at'] = date('Y-m-d H:i:s');
        $db->update('contact_submissions', $data, 'id = :id', ['id' => (int) $id]);
        AuditService::log('contact_enquiry_updated', 'contact_submission', $id, ['status' => $enquiry['status']], ['status' => $status]);
        Flash::set('success', 'Enquiry marked ' . str_replace('_', ' ', $status) . '.');
        $this->redirect('admin/enquiries?view=' . $id);
    }

    public function replyEnquiry(string $id): never
    {
        $db = Database::get();
        $enquiry = $db->fetch('SELECT * FROM contact_submissions WHERE id = :id', ['id' => (int) $id]);
        $message = trim((string) ($_POST['message'] ?? ''));
        if (!$enquiry || mb_strlen($message) < 5 || mb_strlen($message) > 5000) {
            Flash::set('warning', 'Enter a reply between 5 and 5,000 characters.');
            $this->redirect('admin/enquiries?view=' . $id);
        }
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $html = '<p>Dear ' . htmlspecialchars((string) $enquiry['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p><p>' . $safeMessage . '</p><p>Regards,<br>Admissions Office<br>Netaji College of Pharmacy</p>';
        if (!(new MailService())->send((string) $enquiry['email'], 'Re: ' . preg_replace('/[\r\n]+/', ' ', (string) $enquiry['subject']), $html, 'contact-enquiry-reply')) {
            Flash::set('warning', 'Email delivery failed. Review Admin → Email log before trying again.');
            $this->redirect('admin/enquiries?view=' . $id);
        }
        $db->update('contact_submissions', ['status' => 'replied', 'assigned_to' => Auth::id(), 'responded_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $id]);
        AuditService::log('contact_enquiry_replied', 'contact_submission', $id, ['status' => $enquiry['status']], ['status' => 'replied']);
        Flash::set('success', 'Reply sent or recorded in the local email log, and the enquiry was marked replied.');
        $this->redirect('admin/enquiries?view=' . $id);
    }

    public function audit(): void
    {
        $logs = Database::get()->all("SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 300");
        $this->view('admin/audit', ['logs' => $logs, 'title' => 'Audit trail'], 'admin');
    }

    public function mailLog(): void
    {
        $messages = Database::get()->all('SELECT * FROM mail_logs ORDER BY created_at DESC LIMIT 200');
        $this->view('admin/mail', ['messages' => $messages, 'title' => 'Email delivery log'], 'admin');
    }

    public function backups(): void
    {
        $backups = Database::get()->all("SELECT bl.*, CONCAT(u.first_name, ' ', u.last_name) AS initiated_by_name FROM backup_logs bl LEFT JOIN users u ON u.id = bl.initiated_by ORDER BY bl.created_at DESC LIMIT 100");
        $this->view('admin/backups', ['backups' => $backups, 'title' => 'Backup & recovery'], 'admin');
    }

    public function createBackup(): never
    {
        try {
            $backup = (new BackupService())->create();
            Flash::set('success', 'Encrypted backup created. Verify and store the downloaded copy away from the application server.');
            $this->redirect('admin/backups/' . $backup['id'] . '/download');
        } catch (Throwable $exception) {
            Flash::set('warning', $exception->getMessage());
            $this->redirect('admin/backups');
        }
    }

    public function downloadBackup(string $id): never
    {
        $backup = Database::get()->fetch("SELECT * FROM backup_logs WHERE id = :id AND status = 'completed'", ['id' => (int) $id]);
        if (!$backup) { http_response_code(404); exit('Backup not found.'); }
        $base = realpath(BASE_PATH . '/storage/backups');
        $path = realpath(BASE_PATH . '/storage/backups/' . basename((string) $backup['filename']));
        if (!$base || !$path || !str_starts_with($path, $base) || !is_file($path)) { http_response_code(404); exit('Backup file is unavailable.'); }
        AuditService::log('encrypted_backup_downloaded', 'backup', $backup['id']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
