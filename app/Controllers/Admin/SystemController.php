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
        $db = Database::get();
        $cycles = $db->all('SELECT * FROM admission_cycles ORDER BY starts_at DESC');
        $programs = $db->all('SELECT * FROM programs ORDER BY name');
        $cyclePrograms = $db->all('SELECT cp.*, p.name AS program_name, ac.name AS cycle_name FROM cycle_programs cp JOIN programs p ON p.id = cp.program_id JOIN admission_cycles ac ON ac.id = cp.admission_cycle_id ORDER BY ac.starts_at DESC, p.name');
        $seatMatrix = $db->all('SELECT sm.*, p.name AS program_name FROM seat_matrix sm JOIN cycle_programs cp ON cp.id = sm.cycle_program_id JOIN programs p ON p.id = cp.program_id ORDER BY sm.cycle_program_id, sm.category');
        $documentRequirements = $db->all('SELECT cdr.*, dt.name AS document_name FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id = cdr.document_type_id ORDER BY cdr.admission_cycle_id, cdr.sort_order');
        $this->view('admin/settings', compact('settings', 'cycles', 'programs', 'cyclePrograms', 'seatMatrix', 'documentRequirements') + ['title' => 'College & admission settings'], 'admin');
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

    public function createProgram(): never
    {
        $db = Database::get();
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        if ($name === '' || !preg_match('/^[A-Z0-9-]{2,20}$/', $code) || $db->fetch('SELECT id FROM programs WHERE code = :code', ['code' => $code])) {
            Flash::set('warning', 'Enter a unique programme name and 2–20 character code.'); $this->redirect('admin/settings#cycles');
        }
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
        $departmentId = (int) $db->scalar('SELECT id FROM departments ORDER BY id LIMIT 1');
        $id = $db->insert('programs', [
            'department_id' => $departmentId ?: null, 'name' => $name, 'code' => $code, 'slug' => $slug . '-' . strtolower($code),
            'award_type' => trim((string) ($_POST['award_type'] ?? 'Undergraduate Degree')), 'duration_years' => max(.5, (float) ($_POST['duration_years'] ?? 4)),
            'total_semesters' => max(1, (int) ($_POST['total_semesters'] ?? 8)), 'summary' => trim((string) ($_POST['summary'] ?? '')),
            'description' => '', 'eligibility_summary' => '', 'career_summary' => '', 'image_path' => 'assets/images/research-lab.jpg',
            'status' => 'active', 'sort_order' => 10, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        AuditService::log('program_created', 'program', $id, [], ['name' => $name, 'code' => $code]);
        Flash::set('success', 'Programme created. Add it to an admission cycle and configure rules before publication.');
        $this->redirect('admin/settings#cycles');
    }

    public function createCycle(): never
    {
        $db = Database::get();
        $sessionName = trim((string) ($_POST['session_name'] ?? ''));
        $cycleName = trim((string) ($_POST['cycle_name'] ?? ''));
        $code = strtoupper(trim((string) ($_POST['cycle_code'] ?? '')));
        $sessionStart = (string) ($_POST['session_starts_on'] ?? ''); $sessionEnd = (string) ($_POST['session_ends_on'] ?? '');
        $startsAt = str_replace('T', ' ', (string) ($_POST['starts_at'] ?? '')); $endsAt = str_replace('T', ' ', (string) ($_POST['ends_at'] ?? ''));
        if ($sessionName === '' || $cycleName === '' || !preg_match('/^[A-Z0-9-]{2,30}$/', $code) || strtotime($sessionStart) === false || strtotime($sessionEnd) === false || strtotime($startsAt) === false || strtotime($endsAt) === false || strtotime($startsAt) >= strtotime($endsAt)) {
            Flash::set('warning', 'Complete the new session and cycle with valid names, code and dates.'); $this->redirect('admin/settings#cycles');
        }
        try {
            $cycleId = $db->transaction(function (Database $db) use ($sessionName, $cycleName, $code, $sessionStart, $sessionEnd, $startsAt, $endsAt): int {
                $session = $db->fetch('SELECT id FROM academic_sessions WHERE name = :name', ['name' => $sessionName]);
                $sessionId = $session ? (int) $session['id'] : $db->insert('academic_sessions', ['name' => $sessionName, 'starts_on' => $sessionStart, 'ends_on' => $sessionEnd, 'status' => 'upcoming', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $newCycleId = $db->insert('admission_cycles', [
                    'academic_session_id' => $sessionId, 'name' => $cycleName, 'code' => $code, 'starts_at' => date('Y-m-d H:i:s', strtotime($startsAt)),
                    'ends_at' => date('Y-m-d H:i:s', strtotime($endsAt)), 'correction_deadline' => null, 'status' => 'draft',
                    'instructions' => 'Complete every section and retain your acknowledgement.', 'declaration_text' => 'I declare that the information provided is correct.',
                    'application_number_prefix' => 'NCP-APP-' . date('Y', strtotime($startsAt)), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                foreach ($db->all("SELECT id, code, sort_order FROM document_types WHERE status = 'active' ORDER BY sort_order") as $documentType) {
                    $optional = in_array($documentType['code'], ['entrance-scorecard','domicile-certificate','category-certificate','transfer-migration'], true);
                    $stage = in_array($documentType['code'], ['identity-proof','transfer-migration'], true) ? 'admission' : 'application';
                    $db->insert('cycle_document_requirements', ['admission_cycle_id' => $newCycleId, 'document_type_id' => $documentType['id'], 'program_id' => null, 'category' => null, 'is_required' => $optional ? 0 : 1, 'stage' => $stage, 'sort_order' => $documentType['sort_order'], 'created_at' => date('Y-m-d H:i:s')]);
                }
                return $newCycleId;
            });
            AuditService::log('admission_cycle_created', 'admission_cycle', $cycleId, [], ['code' => $code]);
            Flash::set('success', 'Draft admission cycle created. Add programmes and document requirements before opening.');
        } catch (Throwable $exception) { Flash::set('warning', 'The cycle could not be created. Check that its code and session name are unique.'); }
        $this->redirect('admin/settings#cycles');
    }

    public function addCycleProgram(string $id): never
    {
        $db = Database::get();
        $cycle = $db->fetch('SELECT id FROM admission_cycles WHERE id = :id', ['id' => (int) $id]);
        $programId = (int) ($_POST['program_id'] ?? 0);
        $program = $db->fetch('SELECT id FROM programs WHERE id = :id AND status = :status', ['id' => $programId, 'status' => 'active']);
        if (!$cycle || !$program || $db->fetch('SELECT id FROM cycle_programs WHERE admission_cycle_id = :cycle AND program_id = :program', ['cycle' => $id, 'program' => $programId])) {
            Flash::set('warning', 'Choose an active programme that is not already in this cycle.'); $this->redirect('admin/settings#cycles');
        }
        $recordId = $db->insert('cycle_programs', [
            'admission_cycle_id' => (int) $id, 'program_id' => $programId, 'seat_capacity' => max(0, (int) ($_POST['seat_capacity'] ?? 0)),
            'application_fee' => max(0, (float) ($_POST['application_fee'] ?? 0)), 'admission_fee' => 0,
            'minimum_marks_general' => null, 'minimum_marks_reserved' => null, 'min_age' => null, 'max_age' => null,
            'accepted_entrance_exams' => null, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $capacity = max(0, (int) ($_POST['seat_capacity'] ?? 0));
        $db->insert('seat_matrix', ['cycle_program_id' => $recordId, 'category' => 'General', 'quota' => 'general', 'seats' => $capacity, 'filled_seats' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        AuditService::log('program_added_to_cycle', 'cycle_program', $recordId, [], ['cycle_id' => $id, 'program_id' => $programId]);
        Flash::set('success', 'Programme added to the admission cycle.');
        $this->redirect('admin/settings#cycles');
    }

    public function updateCycle(string $id): never
    {
        $db = Database::get();
        $cycle = $db->fetch('SELECT * FROM admission_cycles WHERE id = :id', ['id' => (int) $id]);
        $status = (string) ($_POST['status'] ?? 'draft');
        $startsAt = str_replace('T', ' ', trim((string) ($_POST['starts_at'] ?? '')));
        $endsAt = str_replace('T', ' ', trim((string) ($_POST['ends_at'] ?? '')));
        if (!$cycle || !in_array($status, ['draft','open','closed','processing','archived'], true) || strtotime($startsAt) === false || strtotime($endsAt) === false || strtotime($startsAt) >= strtotime($endsAt)) {
            Flash::set('warning', 'Review the cycle status and opening/closing dates.'); $this->redirect('admin/settings#cycles');
        }
        if ($status === 'open' && empty($_POST['opening_ack'])) {
            Flash::set('warning', 'Opening a cycle requires the institutional compliance acknowledgement.'); $this->redirect('admin/settings#cycles');
        }
        $aadhaarStage = in_array($_POST['aadhaar_collection_stage'] ?? '', ['disabled','application','post_selection','admission'], true) ? $_POST['aadhaar_collection_stage'] : 'disabled';
        if ($aadhaarStage !== 'disabled' && empty($_POST['aadhaar_ack'])) {
            Flash::set('warning', 'A non-disabled Aadhaar stage requires the intake-specific compliance acknowledgement.'); $this->redirect('admin/settings#cycles');
        }
        $new = [
            'name' => trim((string) ($_POST['name'] ?? $cycle['name'])), 'starts_at' => date('Y-m-d H:i:s', strtotime($startsAt)),
            'ends_at' => date('Y-m-d H:i:s', strtotime($endsAt)), 'correction_deadline' => !empty($_POST['correction_deadline']) ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string) $_POST['correction_deadline']))) : null,
            'status' => $status, 'instructions' => trim((string) ($_POST['instructions'] ?? '')), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $db->update('admission_cycles', $new, 'id = :id', ['id' => (int) $id]);
        $settingKey = 'aadhaar_collection_stage_cycle_' . (int) $id;
        $stageSetting = $db->fetch('SELECT id FROM settings WHERE key_name = :key', ['key' => $settingKey]);
        $settingData = ['group_name' => 'admissions', 'value' => $aadhaarStage, 'is_public' => 0, 'updated_at' => date('Y-m-d H:i:s')];
        $stageSetting ? $db->update('settings', $settingData, 'id = :id', ['id' => $stageSetting['id']]) : $db->insert('settings', $settingData + ['key_name' => $settingKey, 'created_at' => date('Y-m-d H:i:s')]);
        $new['aadhaar_collection_stage'] = $aadhaarStage;
        $new['aadhaar_compliance_acknowledged'] = isset($_POST['aadhaar_ack']);
        AuditService::log('admission_cycle_updated', 'admission_cycle', $id, $cycle, $new);
        Flash::set('success', 'Admission cycle updated.');
        $this->redirect('admin/settings#cycles');
    }

    public function updateCycleProgram(string $id): never
    {
        $db = Database::get();
        $record = $db->fetch('SELECT * FROM cycle_programs WHERE id = :id', ['id' => (int) $id]);
        if (!$record) { Flash::set('warning', 'Cycle programme not found.'); $this->redirect('admin/settings#cycles'); }
        $new = [
            'seat_capacity' => max(0, (int) ($_POST['seat_capacity'] ?? 0)), 'application_fee' => max(0, (float) ($_POST['application_fee'] ?? 0)),
            'admission_fee' => max(0, (float) ($_POST['admission_fee'] ?? 0)), 'minimum_marks_general' => (float) ($_POST['minimum_marks_general'] ?? 0),
            'minimum_marks_reserved' => (float) ($_POST['minimum_marks_reserved'] ?? 0), 'min_age' => max(0, (int) ($_POST['min_age'] ?? 0)),
            'accepted_entrance_exams' => trim((string) ($_POST['accepted_entrance_exams'] ?? '')), 'status' => in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($new['minimum_marks_general'] > 100 || $new['minimum_marks_reserved'] > 100) { Flash::set('warning', 'Minimum marks cannot exceed 100%.'); $this->redirect('admin/settings#cycles'); }
        $db->update('cycle_programs', $new, 'id = :id', ['id' => (int) $id]);
        AuditService::log('cycle_program_updated', 'cycle_program', $id, $record, $new);
        Flash::set('success', 'Programme intake and fee rules updated.');
        $this->redirect('admin/settings#cycles');
    }

    public function updateSeat(string $id): never
    {
        $db = Database::get();
        $seat = $db->fetch('SELECT * FROM seat_matrix WHERE id = :id', ['id' => (int) $id]);
        $seats = max(0, (int) ($_POST['seats'] ?? 0));
        if (!$seat || $seats < (int) $seat['filled_seats']) { Flash::set('warning', 'Seat allocation cannot be lower than seats already filled.'); $this->redirect('admin/settings#cycles'); }
        $db->update('seat_matrix', ['seats' => $seats, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $id]);
        AuditService::log('seat_matrix_updated', 'seat_matrix', $id, ['seats' => $seat['seats']], ['seats' => $seats]);
        Flash::set('success', 'Seat matrix updated.');
        $this->redirect('admin/settings#cycles');
    }

    public function updateDocumentRequirement(string $id): never
    {
        $db = Database::get();
        $requirement = $db->fetch('SELECT * FROM cycle_document_requirements WHERE id = :id', ['id' => (int) $id]);
        $stage = (string) ($_POST['stage'] ?? 'application');
        if (!$requirement || !in_array($stage, ['application','admission'], true)) { Flash::set('warning', 'Document requirement not found.'); $this->redirect('admin/settings#cycles'); }
        $new = ['is_required' => isset($_POST['is_required']) ? 1 : 0, 'stage' => $stage];
        $db->update('cycle_document_requirements', $new, 'id = :id', ['id' => (int) $id]);
        AuditService::log('document_requirement_updated', 'cycle_document_requirement', $id, $requirement, $new);
        Flash::set('success', 'Document checklist updated.');
        $this->redirect('admin/settings#cycles');
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
