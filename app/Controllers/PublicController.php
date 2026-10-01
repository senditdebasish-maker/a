<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Translator;
use App\Core\Validator;
use App\Services\AdmissionCycleService;

final class PublicController extends Controller
{
    public function home(): void
    {
        $db = Database::get();
        $notices = $this->localizeMany('notice', $db->all("SELECT * FROM notices WHERE status = 'published' AND audience = 'public' AND (expires_at IS NULL OR expires_at >= :today) ORDER BY is_pinned DESC, published_at DESC LIMIT 3", ['today' => date('Y-m-d')]));
        $program = $db->fetch("SELECT * FROM programs WHERE status = 'active' ORDER BY id LIMIT 1");
        $admissionCycles = (new AdmissionCycleService())->publicCycles(true);
        $this->view('public/home', compact('notices', 'program', 'admissionCycles') + ['title' => 'Learn the science. Lead the change.']);
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
        $cycle = null;
        foreach ((new AdmissionCycleService())->publicCycles(true) as $candidate) {
            $configured = Database::get()->fetch('SELECT seat_capacity,application_fee,minimum_marks_general,minimum_marks_reserved FROM cycle_programs WHERE admission_cycle_id=:cycle AND program_id=:program AND status=:status',['cycle'=>$candidate['id'],'program'=>$program['id'],'status'=>'active']);
            if ($configured) { $cycle=$candidate+$configured; break; }
        }
        $this->view('public/program', ['program' => $program, 'cycle' => $cycle, 'title' => $program['name']]);
    }

    public function admissions(): void
    {
        $cycles=(new AdmissionCycleService())->publicCycles(true);
        $this->view('public/admissions',['cycles'=>$cycles,'title'=>'Admissions']);
    }

    public function admission(string $slug): void
    {
        $service=new AdmissionCycleService();
        $cycle=$service->publicCycle($slug);
        if (!$cycle) { http_response_code(404); $this->view('errors/404',['title'=>'Admission cycle not found']); return; }
        $db=Database::get();
        $programs=$db->all("SELECT cp.*,p.name,p.code,p.slug AS program_slug,p.summary,p.duration_years,p.award_type,
            (SELECT COALESCE(SUM(sm.seats-sm.filled_seats),0) FROM seat_matrix sm WHERE sm.cycle_program_id=cp.id) AS available_seats
            FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle AND cp.status='active' AND p.status='active' ORDER BY p.sort_order,p.name",['cycle'=>$cycle['id']]);
        $requirements=$db->all("SELECT dt.name,dt.description,dt.allowed_mimes,dt.max_size_mb,cdr.is_required,cdr.stage,p.name AS program_name,cdr.category
            FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id LEFT JOIN programs p ON p.id=cdr.program_id
            WHERE cdr.admission_cycle_id=:cycle ORDER BY cdr.sort_order,dt.name",['cycle'=>$cycle['id']]);
        $this->view('public/admission-detail',compact('cycle','programs','requirements')+['title'=>$cycle['name']]);
    }

    public function admissionProspectus(string $slug): never
    {
        $cycle=(new AdmissionCycleService())->publicCycle($slug);
        if (!$cycle||!$cycle['prospectus_path']) { http_response_code(404); exit('Prospectus not found.'); }
        $base=realpath(BASE_PATH.'/storage/private');
        $path=realpath(BASE_PATH.'/storage/private/'.ltrim((string)$cycle['prospectus_path'],'/'));
        if (!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)||!is_file($path)) { http_response_code(404); exit('Prospectus not found.'); }
        header('Content-Type: '.($cycle['prospectus_mime_type']?:'application/pdf'));
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: inline; filename="'.str_replace(['"',"\r","\n"],'',basename((string)($cycle['prospectus_original_name']?:'prospectus.pdf'))).'"');
        header('X-Content-Type-Options: nosniff');
        readfile($path); exit;
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
