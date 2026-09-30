<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Translator;
use App\Core\Validator;

final class PublicController extends Controller
{
    public function home(): void
    {
        $db = Database::get();
        $notices = $this->localizeMany('notice', $db->all("SELECT * FROM notices WHERE status = 'published' AND audience = 'public' AND (expires_at IS NULL OR expires_at >= :today) ORDER BY is_pinned DESC, published_at DESC LIMIT 3", ['today' => date('Y-m-d')]));
        $program = $db->fetch("SELECT * FROM programs WHERE status = 'active' ORDER BY id LIMIT 1");
        $this->view('public/home', compact('notices', 'program') + ['title' => 'Learn the science. Lead the change.']);
    }

    public function page(string $slug): void
    {
        $page = Database::get()->fetch("SELECT * FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1", ['slug' => $slug]);
        if (!$page) {
            http_response_code(404);
            $this->view('errors/404', ['title' => 'Page not found']);
            return;
        }
        $page = $this->localize('page', $page);
        $this->view('public/page', ['page' => $page, 'title' => $page['title'], 'slug' => $slug]);
    }

    public function programs(): void
    {
        $programs = Database::get()->all("SELECT * FROM programs WHERE status = 'active' ORDER BY sort_order, name");
        $this->view('public/programs', ['programs' => $programs, 'title' => 'Pharmacy programmes']);
    }

    public function program(string $slug): void
    {
        $program = Database::get()->fetch("SELECT * FROM programs WHERE slug = :slug AND status = 'active' LIMIT 1", ['slug' => $slug]);
        if (!$program) {
            http_response_code(404);
            $this->view('errors/404', ['title' => 'Programme not found']);
            return;
        }
        $cycle = Database::get()->fetch("SELECT ac.*, cp.seat_capacity, cp.application_fee, cp.minimum_marks_general, cp.minimum_marks_reserved
            FROM cycle_programs cp JOIN admission_cycles ac ON ac.id = cp.admission_cycle_id
            WHERE cp.program_id = :program AND ac.status IN ('draft','open') ORDER BY ac.starts_at DESC LIMIT 1", ['program' => $program['id']]);
        $this->view('public/program', ['program' => $program, 'cycle' => $cycle, 'title' => $program['name']]);
    }

    public function admissions(): void
    {
        $db = Database::get();
        $cycle = $db->fetch("SELECT * FROM admission_cycles WHERE status IN ('draft','open') ORDER BY starts_at DESC LIMIT 1");
        $requirements = [];
        if ($cycle) {
            $requirements = $db->all("SELECT dt.name, cdr.is_required, cdr.stage FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id = cdr.document_type_id WHERE cdr.admission_cycle_id = :id ORDER BY cdr.sort_order", ['id' => $cycle['id']]);
        }
        $this->view('public/admissions', ['cycle' => $cycle, 'requirements' => $requirements, 'title' => 'Admissions 2027–28']);
    }

    public function facilities(): void
    {
        $facilities = $this->localizeMany('facility', Database::get()->all("SELECT * FROM facilities WHERE status = 'published' ORDER BY sort_order, name"));
        $this->view('public/facilities', ['facilities' => $facilities, 'title' => 'Learning spaces']);
    }

    public function faculty(): void
    {
        $faculty = $this->localizeMany('faculty', Database::get()->all("SELECT f.*, d.name AS department_name FROM faculty f LEFT JOIN departments d ON d.id = f.department_id WHERE f.status = 'active' ORDER BY f.sort_order, f.name"));
        $this->view('public/faculty', ['faculty' => $faculty, 'title' => 'Meet our faculty']);
    }

    public function notices(): void
    {
        $db = Database::get();
        $categories = array_column($db->all("SELECT DISTINCT category FROM notices WHERE status = 'published' AND audience = 'public' ORDER BY category"), 'category');
        $category = trim((string) ($_GET['category'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $where = "status = 'published' AND audience = 'public' AND (expires_at IS NULL OR expires_at >= :today)";
        $params = ['today' => date('Y-m-d')];
        if ($category !== '' && in_array($category, $categories, true)) { $where .= ' AND category = :category'; $params['category'] = $category; }
        if ($search !== '') {
            $where .= ' AND (title LIKE :search_title OR excerpt LIKE :search_excerpt OR body LIKE :search_body)';
            $term = '%' . $search . '%';
            $params += ['search_title' => $term, 'search_excerpt' => $term, 'search_body' => $term];
        }
        $notices = $this->localizeMany('notice', $db->all("SELECT * FROM notices WHERE {$where} ORDER BY is_pinned DESC, published_at DESC LIMIT 100", $params));
        $this->view('public/notices', compact('notices', 'categories', 'category', 'search') + ['title' => 'Notices & announcements']);
    }

    public function notice(string $slug): void
    {
        $db = Database::get();
        $notice = $db->fetch("SELECT * FROM notices WHERE slug = :slug AND status = 'published' AND audience = 'public' AND (expires_at IS NULL OR expires_at >= :today) LIMIT 1", ['slug' => $slug, 'today' => date('Y-m-d')]);
        if (!$notice) { http_response_code(404); $this->view('errors/404', ['title' => 'Notice not found']); return; }
        $notice = $this->localize('notice', $notice);
        $related = $this->localizeMany('notice', $db->all("SELECT * FROM notices WHERE status = 'published' AND audience = 'public' AND id <> :id AND category = :category ORDER BY published_at DESC LIMIT 3", ['id' => $notice['id'], 'category' => $notice['category']]));
        $this->view('public/notice', compact('notice', 'related') + ['title' => $notice['title']]);
    }

    public function gallery(): void
    {
        $items = $this->localizeMany('gallery_item', Database::get()->all("SELECT * FROM gallery_items WHERE status = 'published' ORDER BY sort_order, created_at DESC"));
        $this->view('public/gallery', ['items' => $items, 'title' => 'Life at Netaji']);
    }

    public function faq(): void
    {
        $faqs = $this->localizeMany('faq', Database::get()->all("SELECT * FROM faqs WHERE status = 'published' ORDER BY category, sort_order"));
        $this->view('public/faq', ['faqs' => $faqs, 'title' => 'Frequently asked questions']);
    }

    public function contact(): void
    {
        $this->view('public/contact', ['title' => 'Talk to us']);
    }

    public function submitContact(): never
    {
        $ip = mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $recent = (int) Database::get()->scalar('SELECT COUNT(*) FROM contact_submissions WHERE ip_address = :ip AND created_at >= :since', ['ip' => $ip, 'since' => date('Y-m-d H:i:s', time() - 3600)]);
        if ($recent >= 5) { Flash::set('warning', 'Too many enquiries were submitted from this connection. Please try again later.'); $this->redirect('contact'); }
        $validator = new Validator();
        $errors = $validator->validate($_POST, [
            'name' => 'required|max:120', 'email' => 'required|email|max:190', 'phone' => 'max:20',
            'subject' => 'required|max:180', 'message' => 'required|min:10|max:3000',
        ]);
        if ($errors) {
            Flash::withInput($_POST);
            Flash::withErrors($errors);
            $this->redirect('contact');
        }
        Database::get()->insert('contact_submissions', [
            'name' => trim((string) $_POST['name']), 'email' => mb_strtolower(trim((string) $_POST['email'])),
            'phone' => trim((string) ($_POST['phone'] ?? '')), 'subject' => trim((string) $_POST['subject']),
            'message' => trim((string) $_POST['message']), 'status' => 'new',
            'ip_address' => $ip, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Flash::set('success', 'Thank you. Our admissions team will respond shortly.');
        $this->redirect('contact');
    }

    public function language(string $locale): never
    {
        Translator::setLocale($locale);
        $target = $_SERVER['HTTP_REFERER'] ?? url();
        header('Location: ' . $target);
        exit;
    }

    private function localizeMany(string $type, array $records): array
    {
        return array_map(fn (array $record): array => $this->localize($type, $record), $records);
    }

    private function localize(string $type, array $record): array
    {
        $locale = Translator::locale();
        if ($locale === 'en') {
            return $record;
        }
        $translation = Database::get()->fetch('SELECT fields_json FROM content_translations WHERE entity_type = :type AND entity_id = :id AND locale = :locale', [
            'type' => $type, 'id' => $record['id'], 'locale' => $locale,
        ]);
        if ($translation) {
            $fields = json_decode((string) $translation['fields_json'], true);
            if (is_array($fields)) {
                foreach ($fields as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $record[$key] = $value;
                    }
                }
            }
        }
        return $record;
    }
}
