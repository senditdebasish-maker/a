<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\AuditService;

final class ApplicationController extends Controller
{
    public function index(): void
    {
        $db = Database::get();
        $status = trim((string) ($_GET['status'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $where = ['1=1']; $params = [];
        if ($status !== '') { $where[] = 'a.status = :status'; $params['status'] = $status; }
        if ($search !== '') { $where[] = "(a.application_number LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR u.mobile LIKE :search)"; $params['search'] = '%' . $search . '%'; }
        $applications = $db->all("SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email, u.mobile, ac.name AS cycle_name,
            CONCAT(reviewer.first_name, ' ', reviewer.last_name) AS reviewer_name
            FROM applications a JOIN users u ON u.id = a.user_id JOIN admission_cycles ac ON ac.id = a.admission_cycle_id
            LEFT JOIN users reviewer ON reviewer.id = a.assigned_to WHERE " . implode(' AND ', $where) . " ORDER BY COALESCE(a.submitted_at, a.created_at) DESC LIMIT 200", $params);
        $counts = $db->all('SELECT status, COUNT(*) AS total FROM applications GROUP BY status');
        $this->view('admin/applications/index', compact('applications', 'counts', 'status', 'search') + ['title' => 'Applications'], 'admin');
    }

    public function show(string $id): void
    {
        $db = Database::get();
        $application = $db->fetch("SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email, u.mobile,
            ac.name AS cycle_name, ap.*, a.id AS id, a.status AS status FROM applications a JOIN users u ON u.id = a.user_id
            JOIN admission_cycles ac ON ac.id = a.admission_cycle_id LEFT JOIN applicant_profiles ap ON ap.user_id = a.user_id WHERE a.id = :id", ['id' => (int) $id]);
        if (!$application) { http_response_code(404); $this->view('errors/404', ['title' => 'Application not found'], 'admin'); return; }
        $address = $db->fetch('SELECT * FROM applicant_addresses WHERE application_id = :id', ['id' => $id]);
        $guardian = $db->fetch('SELECT * FROM guardians WHERE application_id = :id', ['id' => $id]);
        $education = $db->all('SELECT * FROM education_records WHERE application_id = :id ORDER BY level', ['id' => $id]);
        $preferences = $db->all('SELECT pref.*, p.name, p.code FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id JOIN programs p ON p.id = cp.program_id WHERE pref.application_id = :id ORDER BY pref.preference_order', ['id' => $id]);
        $documents = $db->all('SELECT ad.*, dt.name AS document_name, CONCAT(u.first_name, " ", u.last_name) AS reviewer_name FROM application_documents ad JOIN document_types dt ON dt.id = ad.document_type_id LEFT JOIN users u ON u.id = ad.reviewed_by WHERE ad.application_id = :id ORDER BY dt.sort_order', ['id' => $id]);
        $payments = $db->all('SELECT p.*, CONCAT(u.first_name, " ", u.last_name) AS verifier_name FROM payments p LEFT JOIN users u ON u.id = p.verified_by WHERE p.application_id = :id ORDER BY p.created_at DESC', ['id' => $id]);
        $timeline = $db->all('SELECT ash.*, CONCAT(u.first_name, " ", u.last_name) AS changed_by_name FROM application_status_history ash LEFT JOIN users u ON u.id = ash.changed_by WHERE ash.application_id = :id ORDER BY ash.created_at DESC', ['id' => $id]);
        $notes = $db->all('SELECT n.*, CONCAT(u.first_name, " ", u.last_name) AS author_name FROM staff_notes n JOIN users u ON u.id = n.user_id WHERE n.application_id = :id ORDER BY n.created_at DESC', ['id' => $id]);
        $reviewers = $db->all("SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug IN ('super-admin','admission-officer','reviewer') AND u.status = 'active' ORDER BY name");
        $this->view('admin/applications/show', compact('application', 'address', 'guardian', 'education', 'preferences', 'documents', 'payments', 'timeline', 'notes', 'reviewers') + ['title' => $application['application_number'] ?: 'Draft application'], 'admin');
    }

    public function status(string $id): never
    {
        $db = Database::get();
        $application = $db->fetch('SELECT * FROM applications WHERE id = :id', ['id' => (int) $id]);
        $next = (string) ($_POST['status'] ?? '');
        $allowed = ['submitted','under_review','correction_required','approved','selected','rejected','fee_verified','admitted','withdrawn'];
        if (!$application || !in_array($next, $allowed, true)) {
            Flash::set('warning', 'Invalid application status.'); $this->redirect('admin/applications/' . $id);
        }
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (in_array($next, ['correction_required','rejected'], true) && $remarks === '') {
            Flash::set('warning', 'Remarks are required for correction or rejection decisions.'); $this->redirect('admin/applications/' . $id);
        }
        $db->transaction(function (Database $db) use ($application, $next, $remarks): void {
            $updates = ['status' => $next, 'updated_at' => date('Y-m-d H:i:s')];
            if ($next === 'admitted') $updates['admitted_at'] = date('Y-m-d H:i:s');
            if ($next === 'correction_required') $updates['locked_at'] = null;
            $db->update('applications', $updates, 'id = :id', ['id' => $application['id']]);
            $db->insert('application_status_history', [
                'application_id' => $application['id'], 'from_status' => $application['status'], 'to_status' => $next,
                'remarks' => $remarks ?: 'Status updated by admissions team', 'changed_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('notifications', [
                'user_id' => $application['user_id'], 'type' => 'status', 'title' => 'Application status updated',
                'message' => 'Your application is now ' . str_replace('_', ' ', $next) . ($remarks ? '. ' . $remarks : '.'), 'read_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            if ($next === 'admitted') {
                $existing = $db->fetch('SELECT id FROM student_enrollments WHERE application_id = :id', ['id' => $application['id']]);
                if (!$existing) {
                    $db->insert('student_enrollments', [
                        'application_id' => $application['id'], 'user_id' => $application['user_id'],
                        'enrollment_number' => 'NCP-2027-' . str_pad((string) $application['id'], 5, '0', STR_PAD_LEFT),
                        'status' => 'active', 'enrolled_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        });
        AuditService::log('application_status_changed', 'application', $application['id'], ['status' => $application['status']], ['status' => $next, 'remarks' => $remarks]);
        Flash::set('success', 'Application status updated and the applicant was notified.');
        $this->redirect('admin/applications/' . $id);
    }

    public function assign(string $id): never
    {
        $reviewerId = (int) ($_POST['assigned_to'] ?? 0);
        $reviewer = Database::get()->fetch('SELECT id FROM users WHERE id = :id AND status = :status', ['id' => $reviewerId, 'status' => 'active']);
        if (!$reviewer) { Flash::set('warning', 'Select a valid reviewer.'); $this->redirect('admin/applications/' . $id); }
        Database::get()->update('applications', ['assigned_to' => $reviewerId, 'assigned_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $id]);
        AuditService::log('application_assigned', 'application', $id, [], ['assigned_to' => $reviewerId]);
        Flash::set('success', 'Application assigned to reviewer.');
        $this->redirect('admin/applications/' . $id);
    }

    public function note(string $id): never
    {
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') { Flash::set('warning', 'Enter a note.'); $this->redirect('admin/applications/' . $id); }
        Database::get()->insert('staff_notes', ['application_id' => (int) $id, 'user_id' => Auth::id(), 'note' => $note, 'visibility' => 'staff', 'created_at' => date('Y-m-d H:i:s')]);
        AuditService::log('staff_note_added', 'application', $id);
        Flash::set('success', 'Internal note added.');
        $this->redirect('admin/applications/' . $id . '#notes');
    }

    public function reviewDocument(string $id, string $documentId): never
    {
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['verified','rejected','resubmission_required'], true)) { Flash::set('warning', 'Invalid document decision.'); $this->redirect('admin/applications/' . $id); }
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($status !== 'verified' && $remarks === '') { Flash::set('warning', 'Add a reason for rejecting or requesting a new document.'); $this->redirect('admin/applications/' . $id); }
        $db = Database::get();
        $doc = $db->fetch('SELECT * FROM application_documents WHERE id = :doc AND application_id = :app', ['doc' => (int) $documentId, 'app' => (int) $id]);
        if (!$doc) { Flash::set('warning', 'Document not found.'); $this->redirect('admin/applications/' . $id); }
        $db->update('application_documents', ['status' => $status, 'review_remarks' => $remarks, 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $doc['id']]);
        AuditService::log('document_reviewed', 'application_document', $doc['id'], ['status' => $doc['status']], ['status' => $status]);
        Flash::set('success', 'Document review saved.');
        $this->redirect('admin/applications/' . $id . '#documents');
    }

    public function verifyPayment(string $id, string $paymentId): never
    {
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['verified','rejected'], true)) { Flash::set('warning', 'Invalid payment decision.'); $this->redirect('admin/applications/' . $id); }
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $db = Database::get();
        $payment = $db->fetch('SELECT * FROM payments WHERE id = :payment AND application_id = :application', ['payment' => (int) $paymentId, 'application' => (int) $id]);
        if (!$payment) { Flash::set('warning', 'Payment not found.'); $this->redirect('admin/applications/' . $id); }
        $receipt = $status === 'verified' ? 'NCP-RCT-' . date('Y') . '-' . str_pad((string) $payment['id'], 6, '0', STR_PAD_LEFT) : null;
        $db->update('payments', ['status' => $status, 'verification_remarks' => $remarks, 'verified_by' => Auth::id(), 'verified_at' => date('Y-m-d H:i:s'), 'receipt_number' => $receipt, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $payment['id']]);
        AuditService::log('payment_reviewed', 'payment', $payment['id'], ['status' => $payment['status']], ['status' => $status]);
        Flash::set('success', 'Payment verification saved.');
        $this->redirect('admin/applications/' . $id . '#payments');
    }

    public function export(): never
    {
        $rows = Database::get()->all("SELECT a.application_number, CONCAT(u.first_name, ' ', u.last_name) AS applicant, u.email, u.mobile, a.status, a.completion_percentage, a.submitted_at, ac.name AS cycle FROM applications a JOIN users u ON u.id = a.user_id JOIN admission_cycles ac ON ac.id = a.admission_cycle_id ORDER BY a.created_at DESC");
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="applications-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Application No.', 'Applicant', 'Email', 'Mobile', 'Status', 'Completion %', 'Submitted', 'Cycle']);
        foreach ($rows as $row) fputcsv($out, array_values($row));
        fclose($out);
        exit;
    }
}
