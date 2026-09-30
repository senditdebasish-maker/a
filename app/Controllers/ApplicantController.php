<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Encryption;
use App\Core\Flash;
use App\Core\Translator;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\EligibilityService;
use App\Services\UploadService;
use RuntimeException;

final class ApplicantController extends Controller
{
    public function dashboard(): void
    {
        $db = Database::get();
        $application = $this->applicationRecord();
        $documents = [];
        $timeline = [];
        $notifications = $db->all('SELECT * FROM notifications WHERE user_id = :user ORDER BY created_at DESC LIMIT 5', ['user' => Auth::id()]);
        $notices = $db->all("SELECT * FROM notices WHERE status = 'published' AND audience IN ('public','applicants','students') AND (expires_at IS NULL OR expires_at >= :today) ORDER BY is_pinned DESC, published_at DESC LIMIT 4", ['today' => date('Y-m-d')]);
        $notices = array_map(fn (array $notice): array => $this->localizeContent('notice', $notice), $notices);
        if ($application) {
            $documents = $db->all('SELECT ad.*, dt.name AS document_name FROM application_documents ad JOIN document_types dt ON dt.id = ad.document_type_id WHERE ad.application_id = :id ORDER BY dt.sort_order', ['id' => $application['id']]);
            $timeline = $db->all('SELECT ash.*, CONCAT(u.first_name, " ", u.last_name) AS changed_by_name FROM application_status_history ash LEFT JOIN users u ON u.id = ash.changed_by WHERE ash.application_id = :id ORDER BY ash.created_at DESC', ['id' => $application['id']]);
        }
        $profile = $db->fetch('SELECT * FROM applicant_profiles WHERE user_id = :user', ['user' => Auth::id()]);
        $this->view('student/dashboard', compact('application', 'documents', 'timeline', 'notifications', 'notices', 'profile') + ['title' => 'My dashboard'], 'student');
    }

    public function application(): void
    {
        $db = Database::get();
        $application = $this->applicationRecord();
        if (!$application) {
            $cycle = $db->fetch("SELECT * FROM admission_cycles WHERE status IN ('open','draft') ORDER BY CASE WHEN status = 'open' THEN 0 ELSE 1 END, starts_at DESC LIMIT 1");
            if (!$cycle) {
                Flash::set('warning', 'There is no admission cycle accepting applications right now.');
                $this->redirect('student/dashboard');
            }
            $id = $db->insert('applications', [
                'user_id' => Auth::id(), 'admission_cycle_id' => $cycle['id'], 'status' => 'draft', 'current_step' => 1,
                'completion_percentage' => 10, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('application_status_history', ['application_id' => $id, 'from_status' => null, 'to_status' => 'draft', 'remarks' => 'Application created', 'changed_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s')]);
            $application = $this->applicationRecord();
        }
        $profile = $db->fetch('SELECT * FROM applicant_profiles WHERE user_id = :id', ['id' => Auth::id()]);
        $address = $db->fetch('SELECT * FROM applicant_addresses WHERE application_id = :id LIMIT 1', ['id' => $application['id']]);
        $guardian = $db->fetch('SELECT * FROM guardians WHERE application_id = :id LIMIT 1', ['id' => $application['id']]);
        $education = $db->all('SELECT * FROM education_records WHERE application_id = :id ORDER BY level', ['id' => $application['id']]);
        $exam = $db->fetch('SELECT * FROM entrance_exams WHERE application_id = :id LIMIT 1', ['id' => $application['id']]);
        $programs = $db->all("SELECT p.*, cp.id AS cycle_program_id, cp.seat_capacity, cp.application_fee FROM cycle_programs cp JOIN programs p ON p.id = cp.program_id WHERE cp.admission_cycle_id = :cycle AND p.status = 'active' ORDER BY p.sort_order", ['cycle' => $application['admission_cycle_id']]);
        $preferences = $db->all('SELECT * FROM application_preferences WHERE application_id = :id ORDER BY preference_order', ['id' => $application['id']]);
        $requirements = $db->all('SELECT cdr.*, dt.name, dt.description FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id = cdr.document_type_id WHERE cdr.admission_cycle_id = :cycle ORDER BY cdr.sort_order', ['cycle' => $application['admission_cycle_id']]);
        $documents = $db->all('SELECT * FROM application_documents WHERE application_id = :id', ['id' => $application['id']]);
        $aadhaarPolicy = $this->aadhaarPolicy($application);
        $identityCollectionOpen = $this->identityCollectionOpen($aadhaarPolicy, (string) $application['status']);
        $this->view('student/application', compact('application', 'profile', 'address', 'guardian', 'education', 'exam', 'programs', 'preferences', 'requirements', 'documents', 'aadhaarPolicy', 'identityCollectionOpen') + ['title' => 'My application'], 'student');
    }

    public function saveApplication(): never
    {
        $application = $this->editableApplication();
        $db = Database::get();
        $section = (string) $this->input('section', 'personal');
        try {
            $db->transaction(function (Database $db) use ($section, $application): void {
                switch ($section) {
                    case 'personal':
                        $this->savePersonal($db);
                        break;
                    case 'address':
                        $this->saveAddress($db, (int) $application['id']);
                        break;
                    case 'guardian':
                        $this->saveGuardian($db, (int) $application['id']);
                        break;
                    case 'academic':
                        $this->saveAcademic($db, (int) $application['id']);
                        break;
                    case 'preferences':
                        $this->savePreferences($db, (int) $application['id'], (int) $application['admission_cycle_id']);
                        break;
                    default:
                        throw new RuntimeException('Unknown form section.');
                }
                $completion = $this->completionScore((int) $application['id']);
                $db->update('applications', ['completion_percentage' => $completion, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $application['id']]);
            });
            AuditService::log('application_section_saved', 'application', $application['id'], [], ['section' => $section]);
            Flash::set('success', ucfirst($section) . ' details saved.');
        } catch (RuntimeException $exception) {
            Flash::withInput($_POST);
            Flash::set('warning', $exception->getMessage());
        }
        $this->redirect('student/application#' . $section);
    }

    public function submitApplication(): never
    {
        $application = $this->editableApplication();
        $db = Database::get();
        $profile = $db->fetch('SELECT * FROM applicant_profiles WHERE user_id = :id', ['id' => Auth::id()]);
        $preferenceCount = (int) $db->scalar('SELECT COUNT(*) FROM application_preferences WHERE application_id = :id', ['id' => $application['id']]);
        $requiredDocs = (int) $db->scalar("SELECT COUNT(*) FROM cycle_document_requirements WHERE admission_cycle_id = :cycle AND is_required = 1 AND stage = 'application'", ['cycle' => $application['admission_cycle_id']]);
        $uploadedRequired = (int) $db->scalar("SELECT COUNT(DISTINCT ad.document_type_id) FROM application_documents ad JOIN cycle_document_requirements cdr ON cdr.document_type_id = ad.document_type_id AND cdr.admission_cycle_id = :cycle WHERE ad.application_id = :id AND cdr.is_required = 1 AND cdr.stage = 'application'", ['cycle' => $application['admission_cycle_id'], 'id' => $application['id']]);
        $educationCount = (int) $db->scalar('SELECT COUNT(*) FROM education_records WHERE application_id = :id', ['id' => $application['id']]);
        $errors = [];
        if (!$profile || !$profile['date_of_birth'] || !$profile['gender'] || !$profile['category']) $errors[] = 'Complete your personal details.';
        if ($preferenceCount < 1) $errors[] = 'Select at least one programme preference.';
        if ($educationCount < 2) $errors[] = 'Add both Class 10 and Class 12 academic records.';
        if ($uploadedRequired < $requiredDocs) $errors[] = 'Upload all documents required at application stage.';
        if (!$this->input('declaration')) $errors[] = 'Accept the applicant declaration.';
        if ($errors) {
            Flash::set('warning', implode(' ', $errors));
            $this->redirect('student/application#review');
        }

        $db->transaction(function (Database $db) use ($application): void {
            $number = 'NCP-APP-' . date('Y') . '-' . str_pad((string) $application['id'], 6, '0', STR_PAD_LEFT);
            $db->update('applications', [
                'application_number' => $number, 'status' => 'submitted', 'completion_percentage' => 100,
                'submitted_at' => date('Y-m-d H:i:s'), 'locked_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $application['id']]);
            $db->insert('application_declarations', [
                'application_id' => $application['id'], 'declaration_version' => '1.0', 'accepted' => 1,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '', 'accepted_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('application_status_history', [
                'application_id' => $application['id'], 'from_status' => $application['status'], 'to_status' => 'submitted',
                'remarks' => 'Application submitted by applicant', 'changed_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('notifications', [
                'user_id' => Auth::id(), 'type' => 'application', 'title' => 'Application submitted',
                'message' => 'Your application ' . $number . ' has been received for review.', 'read_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        });
        try {
            $eligibility = (new EligibilityService())->evaluate((int) $application['id']);
        } catch (\Throwable) {
            $eligibility = ['status' => 'needs_review'];
            $db->update('applications', ['eligibility_status' => 'needs_review', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $application['id']]);
        }
        AuditService::log('application_submitted', 'application', $application['id'], [], ['eligibility_status' => $eligibility['status']]);
        Flash::set('success', 'Application submitted successfully. Your application number is now available.');
        $this->redirect('student/dashboard');
    }

    public function uploadDocument(): never
    {
        $application = $this->editableApplication();
        $typeId = (int) $this->input('document_type_id');
        $required = Database::get()->fetch('SELECT * FROM cycle_document_requirements WHERE admission_cycle_id = :cycle AND document_type_id = :type LIMIT 1', ['cycle' => $application['admission_cycle_id'], 'type' => $typeId]);
        if (!$required || empty($_FILES['document'])) {
            Flash::set('warning', 'Select a valid document and file.');
            $this->redirect('student/application#documents');
        }
        try {
            $stored = (new UploadService())->store($_FILES['document'], 'applications/' . $application['id']);
            $existing = Database::get()->fetch('SELECT * FROM application_documents WHERE application_id = :application AND document_type_id = :type LIMIT 1', ['application' => $application['id'], 'type' => $typeId]);
            $data = $stored + ['status' => 'pending', 'uploaded_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
            if ($existing) {
                @unlink(BASE_PATH . '/storage/private/' . $existing['path']);
                Database::get()->update('application_documents', $data, 'id = :id', ['id' => $existing['id']]);
                $documentId = $existing['id'];
            } else {
                $documentId = Database::get()->insert('application_documents', $data + ['application_id' => $application['id'], 'document_type_id' => $typeId, 'created_at' => date('Y-m-d H:i:s')]);
            }
            AuditService::log('document_uploaded', 'application_document', $documentId);
            Flash::set('success', 'Document uploaded securely.');
        } catch (RuntimeException $exception) {
            Flash::set('warning', $exception->getMessage());
        }
        $this->redirect('student/application#documents');
    }

    public function payments(): void
    {
        $db = Database::get();
        $application = $this->applicationRecord();
        $payments = $application ? $db->all('SELECT * FROM payments WHERE application_id = :id ORDER BY created_at DESC', ['id' => $application['id']]) : [];
        $paymentInfo = null;
        if ($application) {
            $paymentInfo = $db->fetch('SELECT cp.application_fee, cp.admission_fee, p.name AS program_name FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id JOIN programs p ON p.id = cp.program_id WHERE pref.application_id = :id ORDER BY pref.preference_order LIMIT 1', ['id' => $application['id']]);
        }
        $paymentSettings = [];
        foreach ($db->all("SELECT key_name, value FROM settings WHERE key_name IN ('upi_id','bank_details')") as $setting) $paymentSettings[$setting['key_name']] = $setting['value'];
        $paymentType = $application && in_array($application['status'], ['selected','fee_verified','admitted'], true) ? 'admission_fee' : 'application_fee';
        $expectedAmount = (float) ($paymentInfo[$paymentType] ?? 0);
        $paymentOpen = $application && !in_array($application['status'], ['fee_verified','admitted'], true);
        $this->view('student/payments', compact('application', 'payments', 'paymentInfo', 'paymentSettings', 'paymentType', 'expectedAmount', 'paymentOpen') + ['title' => 'Payments & receipts'], 'student');
    }

    public function submitPayment(): never
    {
        $application = $this->applicationRecord();
        if (!$application || empty($_FILES['proof'])) {
            Flash::set('warning', 'Upload a payment proof.');
            $this->redirect('student/payments');
        }
        $db = Database::get();
        $paymentType = in_array($application['status'], ['selected','fee_verified','admitted'], true) ? 'admission_fee' : 'application_fee';
        if (in_array($application['status'], ['fee_verified','admitted'], true)) { Flash::set('warning', 'No further payment is due for this application.'); $this->redirect('student/payments'); }
        $feeColumn = $paymentType === 'admission_fee' ? 'admission_fee' : 'application_fee';
        $expectedFee = (float) ($db->scalar("SELECT cp.{$feeColumn} FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id WHERE pref.application_id = :id ORDER BY pref.preference_order LIMIT 1", ['id' => $application['id']]) ?: 0);
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['amount' => 'required|numeric', 'reference_number' => 'required|max:100', 'paid_at' => 'required|date']);
        if ($expectedFee <= 0) $errors['amount'][] = 'The required fee has not been configured. Contact Admissions before paying.';
        if (abs((float) ($_POST['amount'] ?? 0) - $expectedFee) > 0.01) $errors['amount'][] = 'The amount must match the configured fee of ' . money($expectedFee) . '.';
        if ($db->fetch("SELECT id FROM payments WHERE application_id = :id AND type = :type AND status IN ('pending','verified') LIMIT 1", ['id' => $application['id'], 'type' => $paymentType])) $errors['amount'][] = 'A payment for this fee is already pending or verified.';
        if ($errors) {
            Flash::withErrors($errors); Flash::withInput($_POST); $this->redirect('student/payments');
        }
        try {
            $stored = (new UploadService())->store($_FILES['proof'], 'payments/' . $application['id']);
            $id = Database::get()->insert('payments', [
                'application_id' => $application['id'], 'user_id' => Auth::id(), 'type' => $paymentType,
                'amount' => (float) $_POST['amount'], 'currency' => 'INR', 'method' => (string) ($_POST['method'] ?? 'upi'),
                'reference_number' => trim((string) $_POST['reference_number']), 'proof_path' => $stored['path'], 'proof_original_name' => $stored['original_name'],
                'status' => 'pending', 'paid_at' => (string) $_POST['paid_at'], 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            AuditService::log('payment_proof_submitted', 'payment', $id);
            Flash::set('success', 'Payment proof submitted for Accounts verification.');
        } catch (RuntimeException $exception) {
            Flash::set('warning', $exception->getMessage());
        }
        $this->redirect('student/payments');
    }

    public function messages(): void
    {
        $notifications = Database::get()->all('SELECT * FROM notifications WHERE user_id = :user ORDER BY created_at DESC LIMIT 100', ['user' => Auth::id()]);
        Database::get()->query('UPDATE notifications SET read_at = COALESCE(read_at, :now) WHERE user_id = :user', ['now' => date('Y-m-d H:i:s'), 'user' => Auth::id()]);
        $this->view('student/messages', ['notifications' => $notifications, 'title' => 'Messages & notifications'], 'student');
    }

    public function tickets(): void
    {
        $tickets = Database::get()->all('SELECT * FROM support_tickets WHERE user_id = :user ORDER BY updated_at DESC', ['user' => Auth::id()]);
        $this->view('student/tickets', ['tickets' => $tickets, 'title' => 'Help & support'], 'student');
    }

    public function createTicket(): never
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['subject' => 'required|max:180', 'category' => 'required|in:admission,documents,payment,technical,other', 'message' => 'required|min:10|max:3000']);
        if ($errors) {
            Flash::withErrors($errors); Flash::withInput($_POST); $this->redirect('student/support');
        }
        $db = Database::get();
        $ticketId = $db->transaction(function (Database $db): int {
            $id = $db->insert('support_tickets', [
                'ticket_number' => 'TKT-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                'user_id' => Auth::id(), 'subject' => trim((string) $_POST['subject']), 'category' => $_POST['category'],
                'priority' => 'normal', 'status' => 'open', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('ticket_messages', ['ticket_id' => $id, 'user_id' => Auth::id(), 'message' => trim((string) $_POST['message']), 'is_staff_reply' => 0, 'created_at' => date('Y-m-d H:i:s')]);
            return $id;
        });
        AuditService::log('ticket_created', 'support_ticket', $ticketId);
        Flash::set('success', 'Support ticket created.');
        $this->redirect('student/support');
    }

    public function showTicket(string $id): void
    {
        $db = Database::get();
        $ticket = $db->fetch('SELECT * FROM support_tickets WHERE id = :id AND user_id = :user', ['id' => (int) $id, 'user' => Auth::id()]);
        if (!$ticket) { http_response_code(404); $this->view('errors/404', ['title' => 'Ticket not found'], 'student'); return; }
        $messages = $db->all("SELECT tm.*, CONCAT(u.first_name, ' ', u.last_name) AS sender_name FROM ticket_messages tm JOIN users u ON u.id = tm.user_id WHERE tm.ticket_id = :id ORDER BY tm.created_at", ['id' => (int) $id]);
        $this->view('student/ticket', compact('ticket', 'messages') + ['title' => $ticket['ticket_number']], 'student');
    }

    public function replyTicket(string $id): never
    {
        $db = Database::get();
        $ticket = $db->fetch('SELECT * FROM support_tickets WHERE id = :id AND user_id = :user', ['id' => (int) $id, 'user' => Auth::id()]);
        $message = trim((string) ($_POST['message'] ?? ''));
        if (!$ticket || in_array($ticket['status'], ['closed'], true) || mb_strlen($message) < 2 || mb_strlen($message) > 3000) {
            Flash::set('warning', 'The ticket is closed or the reply is invalid.');
            $this->redirect('student/support/' . $id);
        }
        $db->transaction(function (Database $db) use ($ticket, $message): void {
            $db->insert('ticket_messages', ['ticket_id' => $ticket['id'], 'user_id' => Auth::id(), 'message' => $message, 'attachment_path' => null, 'is_staff_reply' => 0, 'created_at' => date('Y-m-d H:i:s')]);
            $db->update('support_tickets', ['status' => 'open', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $ticket['id']]);
        });
        AuditService::log('applicant_ticket_replied', 'support_ticket', $ticket['id']);
        Flash::set('success', 'Your reply was added to the ticket.');
        $this->redirect('student/support/' . $id);
    }

    public function saveIdentity(): never
    {
        $application = $this->applicationRecord();
        $policy = $this->aadhaarPolicy($application);
        if (!$application || !$this->identityCollectionOpen($policy, (string) $application['status'])) {
            Flash::set('warning', 'Identity collection is not open at this application stage.');
            $this->redirect('student/application');
        }
        $type = (string) ($_POST['government_id_type'] ?? '');
        $identifier = preg_replace('/\s+/', '', trim((string) ($_POST['government_id'] ?? '')));
        if (!in_array($type, ['aadhaar','passport','voter_id'], true) || $identifier === '' || empty($_POST['identity_consent'])) {
            Flash::set('warning', 'Choose an identity type, enter its number, and provide the specific collection consent.');
            $this->redirect('student/application#identity-stage');
        }
        if ($type === 'aadhaar' && !preg_match('/^[0-9]{12}$/', $identifier)) {
            Flash::set('warning', 'Aadhaar numbers must contain exactly 12 digits.');
            $this->redirect('student/application#identity-stage');
        }
        $db = Database::get();
        $db->transaction(function (Database $db) use ($application, $type, $identifier, $policy): void {
            $db->update('applicant_profiles', [
                'government_id_type' => $type, 'government_id_encrypted' => Encryption::encrypt($identifier),
                'government_id_last4' => substr(preg_replace('/\D+/', '', $identifier) ?: $identifier, -4), 'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :user', ['user' => Auth::id()]);
            $db->insert('consent_records', [
                'user_id' => Auth::id(), 'application_id' => $application['id'], 'consent_type' => 'identity_collection',
                'purpose' => 'Identity verification for admission at the configured ' . $policy . ' stage', 'version' => '1.0',
                'granted' => 1, 'ip_address' => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), 'withdrawn_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        });
        AuditService::log('sensitive_identity_collected', 'application', $application['id'], [], ['type' => $type, 'stage' => $policy, 'last4' => substr($identifier, -4)]);
        Flash::set('success', 'Identity details were encrypted and your consent was recorded.');
        $this->redirect('student/application#identity-stage');
    }

    public function printApplication(): void
    {
        $application = $this->applicationRecord();
        if (!$application) {
            $this->redirect('student/dashboard');
        }
        $db = Database::get();
        $profile = $db->fetch('SELECT ap.*, u.first_name, u.last_name, u.email, u.mobile FROM applicant_profiles ap JOIN users u ON u.id = ap.user_id WHERE ap.user_id = :user', ['user' => Auth::id()]);
        $education = $db->all('SELECT * FROM education_records WHERE application_id = :id ORDER BY level', ['id' => $application['id']]);
        $preferences = $db->all('SELECT p.name, pref.preference_order FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id JOIN programs p ON p.id = cp.program_id WHERE pref.application_id = :id ORDER BY pref.preference_order', ['id' => $application['id']]);
        $this->view('documents/application', compact('application', 'profile', 'education', 'preferences') + ['title' => 'Application ' . ($application['application_number'] ?: 'Draft')], 'document');
    }

    private function localizeContent(string $entity, array $record): array
    {
        $locale = Translator::locale();
        if ($locale === 'en') return $record;
        $row = Database::get()->fetch('SELECT fields_json FROM content_translations WHERE entity_type = :type AND entity_id = :id AND locale = :locale', ['type' => $entity, 'id' => $record['id'], 'locale' => $locale]);
        $fields = $row ? json_decode((string) $row['fields_json'], true) : null;
        if (is_array($fields)) foreach ($fields as $key => $value) if ($value !== null && $value !== '') $record[$key] = $value;
        return $record;
    }

    private function identityCollectionOpen(string $policy, string $status): bool
    {
        if ($policy === 'application') return in_array($status, ['draft','correction_required'], true);
        if ($policy === 'post_selection') return in_array($status, ['selected','fee_verified','admitted'], true);
        if ($policy === 'admission') return in_array($status, ['fee_verified','admitted'], true);
        return false;
    }

    private function aadhaarPolicy(?array $application): string
    {
        $db = Database::get();
        $policy = (string) ($db->scalar("SELECT value FROM settings WHERE key_name = 'aadhaar_collection_stage'") ?: 'disabled');
        if ($policy === 'configurable' && !empty($application['admission_cycle_id'])) {
            $cyclePolicy = (string) ($db->scalar("SELECT value FROM settings WHERE key_name = :key", ['key' => 'aadhaar_collection_stage_cycle_' . $application['admission_cycle_id']]) ?: 'disabled');
            return in_array($cyclePolicy, ['disabled','application','post_selection','admission'], true) ? $cyclePolicy : 'disabled';
        }
        return in_array($policy, ['disabled','application','post_selection','admission'], true) ? $policy : 'disabled';
    }

    private function applicationRecord(): ?array
    {
        return Database::get()->fetch('SELECT a.*, ac.name AS cycle_name, ac.status AS cycle_status FROM applications a JOIN admission_cycles ac ON ac.id = a.admission_cycle_id WHERE a.user_id = :user ORDER BY a.created_at DESC LIMIT 1', ['user' => Auth::id()]);
    }

    private function editableApplication(): array
    {
        $application = $this->applicationRecord();
        if (!$application || !in_array($application['status'], ['draft', 'correction_required'], true)) {
            Flash::set('warning', 'This application is read-only at its current stage.');
            $this->redirect('student/dashboard');
        }
        return $application;
    }

    private function savePersonal(Database $db): void
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['date_of_birth' => 'required|date', 'gender' => 'required|in:male,female,other,prefer_not_to_say', 'category' => 'required|max:50', 'nationality' => 'required|max:80']);
        if ($errors) throw new RuntimeException(implode(' ', array_map(fn($e) => $e[0], $errors)));
        $existingProfile = $db->fetch('SELECT government_id_type, government_id_encrypted, government_id_last4 FROM applicant_profiles WHERE user_id = :user', ['user' => Auth::id()]) ?: [];
        $application = $this->applicationRecord();
        $identityPolicy = $this->aadhaarPolicy($application);
        $identityAllowed = $application && $this->identityCollectionOpen($identityPolicy, (string) $application['status']);
        $newIdentifier = $identityAllowed ? trim((string) ($_POST['government_id'] ?? '')) : '';
        $identifierDigits = preg_replace('/\s+/', '', $newIdentifier);
        $identityType = trim((string) ($_POST['government_id_type'] ?? ($existingProfile['government_id_type'] ?? '')));
        if ($newIdentifier !== '' && empty($_POST['identity_consent'])) throw new RuntimeException('Consent is required before collecting an identity number.');
        if ($newIdentifier !== '' && $identityType === 'aadhaar' && !preg_match('/^[0-9]{12}$/', $identifierDigits)) throw new RuntimeException('Aadhaar numbers must contain exactly 12 digits.');
        $data = [
            'date_of_birth' => $_POST['date_of_birth'], 'gender' => $_POST['gender'], 'category' => trim((string) $_POST['category']),
            'nationality' => trim((string) $_POST['nationality']), 'blood_group' => trim((string) ($_POST['blood_group'] ?? '')),
            'religion' => trim((string) ($_POST['religion'] ?? '')), 'mother_tongue' => trim((string) ($_POST['mother_tongue'] ?? '')),
            'government_id_type' => $identityAllowed ? $identityType : ($existingProfile['government_id_type'] ?? null),
            'government_id_encrypted' => $newIdentifier !== '' ? Encryption::encrypt($identifierDigits) : ($existingProfile['government_id_encrypted'] ?? null),
            'government_id_last4' => $newIdentifier !== '' ? substr(preg_replace('/\D+/', '', $newIdentifier), -4) : ($existingProfile['government_id_last4'] ?? null),
            'profile_completion' => 35, 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $db->update('applicant_profiles', $data, 'user_id = :user', ['user' => Auth::id()]);
        if ($newIdentifier !== '' && $application) {
            $db->insert('consent_records', [
                'user_id' => Auth::id(), 'application_id' => $application['id'], 'consent_type' => 'identity_collection',
                'purpose' => 'Identity verification for admission at the configured ' . $identityPolicy . ' stage', 'version' => '1.0',
                'granted' => 1, 'ip_address' => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), 'withdrawn_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function saveAddress(Database $db, int $applicationId): void
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['address_line1' => 'required|max:200', 'city' => 'required|max:100', 'state' => 'required|max:100', 'postal_code' => 'required|min:6|max:10', 'country' => 'required|max:80']);
        if ($errors) throw new RuntimeException(implode(' ', array_map(fn($e) => $e[0], $errors)));
        $data = [
            'application_id' => $applicationId, 'address_line1' => trim((string) $_POST['address_line1']), 'address_line2' => trim((string) ($_POST['address_line2'] ?? '')),
            'city' => trim((string) $_POST['city']), 'district' => trim((string) ($_POST['district'] ?? '')), 'state' => trim((string) $_POST['state']),
            'postal_code' => trim((string) $_POST['postal_code']), 'country' => trim((string) $_POST['country']), 'same_as_correspondence' => isset($_POST['same_as_correspondence']) ? 1 : 0,
            'correspondence_address' => trim((string) ($_POST['correspondence_address'] ?? '')), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $existing = $db->fetch('SELECT id FROM applicant_addresses WHERE application_id = :id', ['id' => $applicationId]);
        $existing ? $db->update('applicant_addresses', $data, 'id = :id', ['id' => $existing['id']]) : $db->insert('applicant_addresses', $data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    private function saveGuardian(Database $db, int $applicationId): void
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['guardian_name' => 'required|max:160', 'relationship' => 'required|max:50', 'guardian_mobile' => 'required|min:10|max:15']);
        if ($errors) throw new RuntimeException(implode(' ', array_map(fn($e) => $e[0], $errors)));
        $data = [
            'application_id' => $applicationId, 'name' => trim((string) $_POST['guardian_name']), 'relationship' => trim((string) $_POST['relationship']),
            'occupation' => trim((string) ($_POST['occupation'] ?? '')), 'annual_income' => (float) ($_POST['annual_income'] ?? 0),
            'mobile' => trim((string) $_POST['guardian_mobile']), 'email' => mb_strtolower(trim((string) ($_POST['guardian_email'] ?? ''))), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $existing = $db->fetch('SELECT id FROM guardians WHERE application_id = :id', ['id' => $applicationId]);
        $existing ? $db->update('guardians', $data, 'id = :id', ['id' => $existing['id']]) : $db->insert('guardians', $data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    private function saveAcademic(Database $db, int $applicationId): void
    {
        foreach (['10', '12'] as $level) {
            $prefix = 'class_' . $level . '_';
            $data = [
                'application_id' => $applicationId, 'level' => 'class_' . $level, 'board' => trim((string) ($_POST[$prefix . 'board'] ?? '')),
                'institution' => trim((string) ($_POST[$prefix . 'institution'] ?? '')), 'passing_year' => (int) ($_POST[$prefix . 'year'] ?? 0),
                'roll_number' => trim((string) ($_POST[$prefix . 'roll'] ?? '')), 'total_marks' => (float) ($_POST[$prefix . 'total'] ?? 0),
                'obtained_marks' => (float) ($_POST[$prefix . 'obtained'] ?? 0), 'percentage' => (float) ($_POST[$prefix . 'percentage'] ?? 0),
                'subjects' => trim((string) ($_POST[$prefix . 'subjects'] ?? '')), 'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (!$data['board'] || !$data['institution'] || !$data['passing_year']) throw new RuntimeException('Complete both Class 10 and Class 12 details.');
            $existing = $db->fetch('SELECT id FROM education_records WHERE application_id = :id AND level = :level', ['id' => $applicationId, 'level' => 'class_' . $level]);
            $existing ? $db->update('education_records', $data, 'id = :id', ['id' => $existing['id']]) : $db->insert('education_records', $data + ['created_at' => date('Y-m-d H:i:s')]);
        }
        $examData = [
            'application_id' => $applicationId, 'exam_name' => trim((string) ($_POST['exam_name'] ?? '')), 'roll_number' => trim((string) ($_POST['exam_roll'] ?? '')),
            'rank_score' => trim((string) ($_POST['exam_rank'] ?? '')), 'exam_year' => (int) ($_POST['exam_year'] ?? 0), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($examData['exam_name']) {
            $existing = $db->fetch('SELECT id FROM entrance_exams WHERE application_id = :id', ['id' => $applicationId]);
            $existing ? $db->update('entrance_exams', $examData, 'id = :id', ['id' => $existing['id']]) : $db->insert('entrance_exams', $examData + ['created_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function savePreferences(Database $db, int $applicationId, int $cycleId): void
    {
        $choices = array_values(array_unique(array_map('intval', (array) ($_POST['program_preferences'] ?? []))));
        if (!$choices) throw new RuntimeException('Select at least one programme.');
        $valid = $db->all('SELECT id FROM cycle_programs WHERE admission_cycle_id = :cycle', ['cycle' => $cycleId]);
        $validIds = array_map(fn($row) => (int) $row['id'], $valid);
        $db->query('DELETE FROM application_preferences WHERE application_id = :id', ['id' => $applicationId]);
        foreach ($choices as $index => $choice) {
            if (!in_array($choice, $validIds, true)) continue;
            $db->insert('application_preferences', ['application_id' => $applicationId, 'cycle_program_id' => $choice, 'preference_order' => $index + 1, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function completionScore(int $applicationId): int
    {
        $db = Database::get();
        $score = 10;
        $profile = $db->fetch('SELECT date_of_birth, gender, category FROM applicant_profiles WHERE user_id = :user', ['user' => Auth::id()]);
        if ($profile && $profile['date_of_birth'] && $profile['gender'] && $profile['category']) $score += 20;
        if ($db->scalar('SELECT COUNT(*) FROM applicant_addresses WHERE application_id = :id', ['id' => $applicationId])) $score += 15;
        if ($db->scalar('SELECT COUNT(*) FROM guardians WHERE application_id = :id', ['id' => $applicationId])) $score += 15;
        if ((int) $db->scalar('SELECT COUNT(*) FROM education_records WHERE application_id = :id', ['id' => $applicationId]) >= 2) $score += 20;
        if ($db->scalar('SELECT COUNT(*) FROM application_preferences WHERE application_id = :id', ['id' => $applicationId])) $score += 10;
        if ($db->scalar('SELECT COUNT(*) FROM application_documents WHERE application_id = :id', ['id' => $applicationId])) $score += 10;
        return min(100, $score);
    }
}
