<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;

final class ReportController extends Controller
{
    public function index(): void
    {
        $db = Database::get();
        $status = $db->all('SELECT status AS label, COUNT(*) AS total FROM applications GROUP BY status ORDER BY total DESC');
        $category = $db->all("SELECT COALESCE(ap.category, 'Not provided') AS label, COUNT(*) AS total FROM applications a LEFT JOIN applicant_profiles ap ON ap.user_id = a.user_id GROUP BY ap.category ORDER BY total DESC");
        $gender = $db->all("SELECT COALESCE(ap.gender, 'Not provided') AS label, COUNT(*) AS total FROM applications a LEFT JOIN applicant_profiles ap ON ap.user_id = a.user_id GROUP BY ap.gender ORDER BY total DESC");
        $geography = $db->all("SELECT COALESCE(addr.state, 'Not provided') AS label, COUNT(*) AS total FROM applications a LEFT JOIN applicant_addresses addr ON addr.application_id = a.id GROUP BY addr.state ORDER BY total DESC LIMIT 12");
        $daily = $db->all("SELECT DATE(submitted_at) AS label, COUNT(*) AS total FROM applications WHERE submitted_at IS NOT NULL GROUP BY DATE(submitted_at) ORDER BY label DESC LIMIT 14");
        $reviewers = $db->all("SELECT CONCAT(u.first_name, ' ', u.last_name) AS label, COUNT(a.id) AS total FROM users u LEFT JOIN applications a ON a.assigned_to = u.id WHERE a.id IS NOT NULL GROUP BY u.id ORDER BY total DESC");
        $this->view('admin/reports', compact('status', 'category', 'gender', 'geography', 'daily', 'reviewers') + ['title' => 'Admissions reports'], 'admin');
    }
}
