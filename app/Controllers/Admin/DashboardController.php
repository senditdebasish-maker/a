<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $db = Database::get();
        $stats = [
            'total' => (int) $db->scalar('SELECT COUNT(*) FROM applications'),
            'submitted' => (int) $db->scalar("SELECT COUNT(*) FROM applications WHERE status IN ('submitted','under_review')"),
            'corrections' => (int) $db->scalar("SELECT COUNT(*) FROM applications WHERE status = 'correction_required'"),
            'selected' => (int) $db->scalar("SELECT COUNT(*) FROM applications WHERE status IN ('approved','selected','fee_verified','admitted')"),
            'pending_docs' => (int) $db->scalar("SELECT COUNT(*) FROM application_documents WHERE status = 'pending'"),
            'pending_payments' => (int) $db->scalar("SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
            'open_tickets' => (int) $db->scalar("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')"),
        ];
        $byStatus = $db->all('SELECT status, COUNT(*) AS total FROM applications GROUP BY status ORDER BY total DESC');
        $recent = $db->all("SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email, ac.name AS cycle_name
            FROM applications a JOIN users u ON u.id = a.user_id JOIN admission_cycles ac ON ac.id = a.admission_cycle_id
            ORDER BY COALESCE(a.submitted_at, a.created_at) DESC LIMIT 8");
        $queue = $db->all("SELECT a.id, a.application_number, a.status, a.submitted_at, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name,
            DATEDIFF(CURRENT_DATE, DATE(a.submitted_at)) AS waiting_days
            FROM applications a JOIN users u ON u.id = a.user_id
            WHERE a.status IN ('submitted','under_review','correction_required') ORDER BY a.submitted_at ASC LIMIT 6");
        $seat = $db->fetch("SELECT COALESCE(SUM(cp.seat_capacity),0) AS capacity,
            (SELECT COUNT(*) FROM applications WHERE status IN ('selected','fee_verified','admitted')) AS offered,
            (SELECT COUNT(*) FROM applications WHERE status = 'admitted') AS enrolled
            FROM cycle_programs cp JOIN admission_cycles ac ON ac.id = cp.admission_cycle_id WHERE ac.status IN ('draft','open','processing')");
        $this->view('admin/dashboard', compact('stats', 'byStatus', 'recent', 'queue', 'seat') + ['title' => 'Admissions overview'], 'admin');
    }
}
