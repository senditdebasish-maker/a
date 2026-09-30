<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\AuditService;

final class CmsController extends Controller
{
    public function index(): void
    {
        $db = Database::get();
        $pages = $db->all('SELECT * FROM pages ORDER BY title');
        $notices = $db->all('SELECT * FROM notices ORDER BY created_at DESC LIMIT 20');
        $faqs = $db->all('SELECT * FROM faqs ORDER BY category, sort_order');
        $this->view('admin/cms/index', compact('pages', 'notices', 'faqs') + ['title' => 'Website content'], 'admin');
    }

    public function editPage(string $id): void
    {
        $db = Database::get();
        $page = $db->fetch('SELECT * FROM pages WHERE id = :id', ['id' => (int) $id]);
        if (!$page) { http_response_code(404); $this->view('errors/404', ['title' => 'Page not found'], 'admin'); return; }
        $translations = [];
        foreach ($db->all("SELECT locale, fields_json FROM content_translations WHERE entity_type = 'page' AND entity_id = :id", ['id' => $id]) as $row) {
            $translations[$row['locale']] = json_decode((string) $row['fields_json'], true) ?: [];
        }
        $this->view('admin/cms/page', compact('page', 'translations') + ['title' => 'Edit ' . $page['title']], 'admin');
    }

    public function updatePage(string $id): never
    {
        $db = Database::get();
        $page = $db->fetch('SELECT * FROM pages WHERE id = :id', ['id' => (int) $id]);
        if (!$page) { Flash::set('warning', 'Page not found.'); $this->redirect('admin/cms'); }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') { Flash::set('warning', 'English page title is required.'); $this->redirect('admin/cms/pages/' . $id); }
        $db->transaction(function (Database $db) use ($id, $page, $title): void {
            $db->update('pages', [
                'title' => $title, 'eyebrow' => trim((string) ($_POST['eyebrow'] ?? '')), 'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
                'body' => trim((string) ($_POST['body'] ?? '')), 'meta_title' => trim((string) ($_POST['meta_title'] ?? '')),
                'meta_description' => trim((string) ($_POST['meta_description'] ?? '')), 'status' => in_array($_POST['status'] ?? '', ['draft','published'], true) ? $_POST['status'] : 'draft',
                'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $id]);
            foreach (['bn','hi'] as $locale) {
                $fields = [
                    'title' => trim((string) ($_POST[$locale . '_title'] ?? '')), 'eyebrow' => trim((string) ($_POST[$locale . '_eyebrow'] ?? '')),
                    'excerpt' => trim((string) ($_POST[$locale . '_excerpt'] ?? '')), 'body' => trim((string) ($_POST[$locale . '_body'] ?? '')),
                ];
                $existing = $db->fetch("SELECT id FROM content_translations WHERE entity_type = 'page' AND entity_id = :id AND locale = :locale", ['id' => $id, 'locale' => $locale]);
                $data = ['fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')];
                $existing ? $db->update('content_translations', $data, 'id = :id', ['id' => $existing['id']]) : $db->insert('content_translations', $data + ['entity_type' => 'page', 'entity_id' => (int) $id, 'locale' => $locale, 'created_at' => date('Y-m-d H:i:s')]);
            }
        });
        AuditService::log('cms_page_updated', 'page', $id, $page, ['title' => $title]);
        Flash::set('success', 'Page and translations saved.');
        $this->redirect('admin/cms/pages/' . $id);
    }
}
