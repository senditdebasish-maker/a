<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\AuditService;

final class SupportController extends Controller
{
    public function index(): void
    {
        $status = trim((string) ($_GET['status'] ?? ''));
        $params = [];
        $where = '1=1';
        if ($status !== '') { $where = 't.status = :status'; $params['status'] = $status; }
        $tickets = Database::get()->all("SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email,
            CONCAT(a.first_name, ' ', a.last_name) AS assignee_name, (SELECT COUNT(*) FROM ticket_messages tm WHERE tm.ticket_id = t.id) AS message_count
            FROM support_tickets t JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assigned_to
            WHERE {$where} ORDER BY FIELD(t.status,'open','in_progress','resolved','closed'), t.updated_at DESC LIMIT 200", $params);
        $counts = Database::get()->all('SELECT status, COUNT(*) AS total FROM support_tickets GROUP BY status');
        $this->view('admin/support/index', compact('tickets', 'counts', 'status') + ['title' => 'Applicant support'], 'admin');
    }

    public function show(string $id): void
    {
        $db = Database::get();
        $ticket = $db->fetch("SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email, u.mobile FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = :id", ['id' => (int) $id]);
        if (!$ticket) { http_response_code(404); $this->view('errors/404', ['title' => 'Ticket not found'], 'admin'); return; }
        $messages = $db->all("SELECT tm.*, CONCAT(u.first_name, ' ', u.last_name) AS sender_name FROM ticket_messages tm JOIN users u ON u.id = tm.user_id WHERE tm.ticket_id = :id ORDER BY tm.created_at", ['id' => (int) $id]);
        $this->view('admin/support/show', compact('ticket', 'messages') + ['title' => $ticket['ticket_number']], 'admin');
    }

    public function reply(string $id): never
    {
        $message = trim((string) ($_POST['message'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'in_progress');
        if ($message === '' || !in_array($status, ['open','in_progress','resolved','closed'], true)) {
            Flash::set('warning', 'Enter a reply and choose a valid status.'); $this->redirect('admin/support/' . $id);
        }
        $db = Database::get();
        $ticket = $db->fetch('SELECT * FROM support_tickets WHERE id = :id', ['id' => (int) $id]);
        if (!$ticket) { Flash::set('warning', 'Ticket not found.'); $this->redirect('admin/support'); }
        $db->transaction(function (Database $db) use ($ticket, $message, $status): void {
            $db->insert('ticket_messages', ['ticket_id' => $ticket['id'], 'user_id' => Auth::id(), 'message' => $message, 'attachment_path' => null, 'is_staff_reply' => 1, 'created_at' => date('Y-m-d H:i:s')]);
            $db->update('support_tickets', ['status' => $status, 'assigned_to' => Auth::id(), 'closed_at' => $status === 'closed' ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $ticket['id']]);
            $db->insert('notifications', ['user_id' => $ticket['user_id'], 'type' => 'support', 'title' => 'Support ticket updated', 'message' => 'The admissions team replied to ' . $ticket['ticket_number'] . '.', 'action_url' => '/student/support', 'read_at' => null, 'created_at' => date('Y-m-d H:i:s')]);
        });
        AuditService::log('support_ticket_replied', 'support_ticket', $id, ['status' => $ticket['status']], ['status' => $status]);
        Flash::set('success', 'Reply sent and the applicant was notified.');
        $this->redirect('admin/support/' . $id);
    }
}
