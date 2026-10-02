<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\AuditService;
use App\Services\CmsMediaService;
use RuntimeException;

final class CmsController extends Controller
{
    public function index(): void
    {
        $db = Database::get();
        $pages = $db->all('SELECT * FROM pages ORDER BY title');
        $notices = $db->all('SELECT * FROM notices ORDER BY created_at DESC LIMIT 20');
        $faqs = $db->all('SELECT * FROM faqs ORDER BY category, sort_order');
        $counts = [
            'notices' => (int) $db->scalar('SELECT COUNT(*) FROM notices'),
            'faculty' => (int) $db->scalar('SELECT COUNT(*) FROM faculty'),
            'facilities' => (int) $db->scalar('SELECT COUNT(*) FROM facilities'),
            'faqs' => (int) $db->scalar('SELECT COUNT(*) FROM faqs'),
            'gallery' => (int) $db->scalar('SELECT COUNT(*) FROM gallery_items'),
            'media' => (int) $db->scalar('SELECT COUNT(*) FROM media'),
            'sections' => (int) $db->scalar("SELECT COUNT(*) FROM page_sections WHERE status <> 'archived'"),
        ];
        $this->view('admin/cms/index', compact('pages', 'notices', 'faqs', 'counts') + ['title' => 'Website content'], 'admin');
    }

    public function editPage(string $id): void
    {
        $db = Database::get();
        $page = $db->fetch('SELECT * FROM pages WHERE id = :id', ['id' => (int) $id]);
        if (!$page) { http_response_code(404); $this->view('errors/404', ['title' => 'Page not found'], 'admin'); return; }
        $translations = $this->translations('page', (int) $id);
        $sections = $db->all("SELECT * FROM page_sections WHERE page_id = :page AND status <> 'archived' ORDER BY sort_order, id", ['page' => (int) $id]);
        $sectionTranslations = [];
        foreach ($sections as $section) $sectionTranslations[(int) $section['id']] = $this->translations('page_section', (int) $section['id']);
        $sectionTypes = $this->sectionTypes();
        $sectionStyles = $this->sectionStyles();
        $moduleSources = $this->moduleSources();
        $this->view('admin/cms/page', compact('page', 'translations', 'sections', 'sectionTranslations', 'sectionTypes', 'sectionStyles', 'moduleSources') + ['title' => 'Edit ' . $page['title']], 'admin');
    }

    public function updatePage(string $id): never
    {
        $db = Database::get();
        $page = $db->fetch('SELECT * FROM pages WHERE id = :id', ['id' => (int) $id]);
        if (!$page) { Flash::set('warning', 'Page not found.'); $this->redirect('admin/cms'); }
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') { Flash::set('warning', 'English page title is required.'); $this->redirect('admin/cms/pages/' . $id); }
        $db->transaction(function (Database $db) use ($id, $title): void {
            $db->update('pages', [
                'title' => $title, 'eyebrow' => trim((string) ($_POST['eyebrow'] ?? '')), 'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
                'body' => trim((string) ($_POST['body'] ?? '')), 'meta_title' => trim((string) ($_POST['meta_title'] ?? '')),
                'meta_description' => trim((string) ($_POST['meta_description'] ?? '')), 'status' => in_array($_POST['status'] ?? '', ['draft','published'], true) ? $_POST['status'] : 'draft',
                'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $id]);
            $this->saveTranslations($db, 'page', (int) $id, ['title','eyebrow','excerpt','body']);
        });
        AuditService::log('cms_page_updated', 'page', $id, $page, ['title' => $title]);
        Flash::set('success', 'Page and translations saved.');
        $this->redirect('admin/cms/pages/' . $id);
    }

    public function storeSection(string $id): never
    {
        $db = Database::get();
        $page = $db->fetch('SELECT * FROM pages WHERE id = :id', ['id' => (int) $id]);
        if (!$page) { Flash::set('warning', 'Page not found.'); $this->redirect('admin/cms'); }
        try {
            $data = $this->sectionData((int) $id, null);
            $sectionId = $db->transaction(function (Database $db) use ($data): int {
                $sectionId = $db->insert('page_sections', $data + ['created_by'=>Auth::id(),'updated_by'=>Auth::id(),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                $this->saveSectionTranslations($db, $sectionId);
                return $sectionId;
            });
            AuditService::log('cms_page_section_created', 'page_section', $sectionId, [], $data);
            Flash::set('success', 'Page section added.');
        } catch (RuntimeException $exception) {
            Flash::set('warning', $exception->getMessage());
        } catch (\Throwable) {
            Flash::set('warning', 'The section could not be added. Check its fields and try again.');
        }
        $this->redirect('admin/cms/pages/' . $id . '#page-sections');
    }

    public function updateSection(string $id, string $sectionId): never
    {
        $db = Database::get();
        $section = $db->fetch('SELECT * FROM page_sections WHERE id = :section AND page_id = :page', ['section'=>(int)$sectionId,'page'=>(int)$id]);
        if (!$section) { Flash::set('warning', 'Page section not found.'); $this->redirect('admin/cms/pages/' . $id); }
        try {
            $data = $this->sectionData((int) $id, $section);
            $db->transaction(function (Database $db) use ($sectionId, $data): void {
                $db->update('page_sections', $data + ['updated_by'=>Auth::id(),'updated_at'=>date('Y-m-d H:i:s')], 'id = :id', ['id'=>(int)$sectionId]);
                $this->saveSectionTranslations($db, (int) $sectionId);
            });
            AuditService::log('cms_page_section_updated', 'page_section', $sectionId, $section, $data);
            Flash::set('success', 'Page section updated.');
        } catch (RuntimeException $exception) {
            Flash::set('warning', $exception->getMessage());
        } catch (\Throwable) {
            Flash::set('warning', 'The section could not be updated. Check its fields and try again.');
        }
        $this->redirect('admin/cms/pages/' . $id . '?section=' . $sectionId . '#section-' . $sectionId);
    }

    public function archiveSection(string $id, string $sectionId): never
    {
        $db = Database::get();
        $section = $db->fetch('SELECT * FROM page_sections WHERE id = :section AND page_id = :page', ['section'=>(int)$sectionId,'page'=>(int)$id]);
        if (!$section) { Flash::set('warning', 'Page section not found.'); $this->redirect('admin/cms/pages/' . $id); }
        $db->update('page_sections', ['status'=>'archived','updated_by'=>Auth::id(),'updated_at'=>date('Y-m-d H:i:s')], 'id = :id', ['id'=>(int)$sectionId]);
        AuditService::log('cms_page_section_archived', 'page_section', $sectionId, $section, ['status'=>'archived']);
        Flash::set('success', 'Section archived without deleting its content.');
        $this->redirect('admin/cms/pages/' . $id . '#page-sections');
    }

    public function moveSection(string $id, string $sectionId): never
    {
        $direction = (string) ($_POST['direction'] ?? '');
        if (!in_array($direction, ['up','down'], true)) { Flash::set('warning', 'Invalid section direction.'); $this->redirect('admin/cms/pages/' . $id); }
        $db = Database::get();
        $section = $db->fetch("SELECT * FROM page_sections WHERE id=:section AND page_id=:page AND status <> 'archived'", ['section'=>(int)$sectionId,'page'=>(int)$id]);
        if (!$section) { Flash::set('warning', 'Page section not found.'); $this->redirect('admin/cms/pages/' . $id); }
        $ordered = array_map('intval', array_column($db->all("SELECT id FROM page_sections WHERE page_id=:page AND status <> 'archived' ORDER BY sort_order,id", ['page'=>(int)$id]), 'id'));
        $position = array_search((int)$sectionId, $ordered, true);
        $target = $direction === 'up' ? (int)$position - 1 : (int)$position + 1;
        if ($position !== false && isset($ordered[$target])) {
            [$ordered[$position], $ordered[$target]] = [$ordered[$target], $ordered[$position]];
            $db->transaction(function (Database $db) use ($ordered): void {
                $now = date('Y-m-d H:i:s');
                foreach ($ordered as $index => $orderedId) $db->update('page_sections', ['sort_order'=>($index+1)*10,'updated_by'=>Auth::id(),'updated_at'=>$now], 'id=:id', ['id'=>$orderedId]);
            });
            AuditService::log('cms_page_section_moved', 'page_section', $sectionId, ['position'=>(int)$position], ['position'=>$target]);
        }
        $this->redirect('admin/cms/pages/' . $id . '#section-' . $sectionId);
    }

    public function module(string $module): void
    {
        $definition = $this->definition($module);
        if (!$definition) { http_response_code(404); $this->view('errors/404', ['title' => 'CMS module not found'], 'admin'); return; }
        $db = Database::get();
        $records = $db->all('SELECT * FROM `' . $definition['table'] . '` ORDER BY ' . $definition['order'] . ' LIMIT 250');
        $editing = null; $translations = [];
        if (!empty($_GET['edit'])) {
            $editing = $db->fetch('SELECT * FROM `' . $definition['table'] . '` WHERE id = :id', ['id' => (int) $_GET['edit']]);
            if ($editing) $translations = $this->translations($definition['entity'], (int) $editing['id']);
        }
        $departments = $module === 'faculty' ? $db->all("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name") : [];
        $this->view('admin/cms/module', compact('module', 'definition', 'records', 'editing', 'translations', 'departments') + ['title' => $definition['label']], 'admin');
    }

    public function storeModule(string $module): never
    {
        $definition = $this->definition($module);
        if (!$definition) { Flash::set('warning', 'Unknown CMS module.'); $this->redirect('admin/cms'); }
        try {
            $data = $this->moduleData($definition, null);
            $db = Database::get();
            $id = $db->transaction(function (Database $db) use ($definition, $data): int {
                $id = $db->insert($definition['table'], $data + ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $this->saveTranslations($db, $definition['entity'], $id, $definition['translatable']);
                return $id;
            });
            AuditService::log('cms_record_created', $definition['entity'], $id, [], $data);
            Flash::set('success', $definition['singular'] . ' created.');
        } catch (RuntimeException $exception) { Flash::withInput($_POST); Flash::set('warning', $exception->getMessage()); }
        catch (\Throwable) { Flash::withInput($_POST); Flash::set('warning', 'The content could not be saved. Review field lengths and try again.'); }
        $this->redirect('admin/cms/' . $module);
    }

    public function updateModule(string $module, string $id): never
    {
        $definition = $this->definition($module);
        if (!$definition) { Flash::set('warning', 'Unknown CMS module.'); $this->redirect('admin/cms'); }
        $db = Database::get();
        $record = $db->fetch('SELECT * FROM `' . $definition['table'] . '` WHERE id = :id', ['id' => (int) $id]);
        if (!$record) { Flash::set('warning', 'Content record not found.'); $this->redirect('admin/cms/' . $module); }
        try {
            $data = $this->moduleData($definition, $record);
            $db->transaction(function (Database $db) use ($definition, $data, $id): void {
                $db->update($definition['table'], $data + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $id]);
                $this->saveTranslations($db, $definition['entity'], (int) $id, $definition['translatable']);
            });
            AuditService::log('cms_record_updated', $definition['entity'], $id, $record, $data);
            Flash::set('success', $definition['singular'] . ' and translations updated.');
        } catch (RuntimeException $exception) { Flash::set('warning', $exception->getMessage()); }
        catch (\Throwable) { Flash::set('warning', 'The content could not be updated. Review field lengths and try again.'); }
        $this->redirect('admin/cms/' . $module . '?edit=' . $id);
    }

    public function deleteModule(string $module, string $id): never
    {
        $definition = $this->definition($module);
        if (!$definition) { Flash::set('warning', 'Unknown CMS module.'); $this->redirect('admin/cms'); }
        $db = Database::get();
        $record = $db->fetch('SELECT * FROM `' . $definition['table'] . '` WHERE id = :id', ['id' => (int) $id]);
        if (!$record) { Flash::set('warning', 'Content record not found.'); $this->redirect('admin/cms/' . $module); }
        $db->transaction(function (Database $db) use ($definition, $id): void {
            $db->query('DELETE FROM content_translations WHERE entity_type = :type AND entity_id = :id', ['type' => $definition['entity'], 'id' => (int) $id]);
            $db->query('DELETE FROM `' . $definition['table'] . '` WHERE id = :id', ['id' => (int) $id]);
        });
        AuditService::log('cms_record_deleted', $definition['entity'], $id, $record, []);
        Flash::set('success', $definition['singular'] . ' deleted.');
        $this->redirect('admin/cms/' . $module);
    }

    private function sectionData(int $pageId, ?array $existing): array
    {
        $type = (string) ($_POST['section_type'] ?? 'rich_text');
        if (!array_key_exists($type, $this->sectionTypes())) throw new RuntimeException('Choose a supported section type.');
        $style = (string) ($_POST['style_variant'] ?? 'light');
        if (!array_key_exists($style, $this->sectionStyles())) $style = 'light';
        $module = trim((string) ($_POST['module_key'] ?? ''));
        if ($type === 'module_feed' && !array_key_exists($module, $this->moduleSources())) throw new RuntimeException('Choose a valid website module for this section.');
        if ($type !== 'module_feed') $module = '';
        $title = trim((string) ($_POST['section_title'] ?? ''));
        if (in_array($type, ['feature_grid','stats','image_text','call_to_action','module_feed'], true) && $title === '') throw new RuntimeException('A section title is required for this layout.');
        $link = $this->safeSectionLink(trim((string) ($_POST['link_url'] ?? '')));
        $items = $this->parseSectionItems((string) ($_POST['items'] ?? ''));
        foreach (['bn','hi'] as $locale) {
            $translatedItems = $this->parseSectionItems((string) ($_POST[$locale . '_items'] ?? ''));
            $_POST[$locale . '_items_json'] = $translatedItems ? json_encode($translatedItems, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : '';
        }
        $keyInput = trim((string) ($_POST['section_key'] ?? ($existing['section_key'] ?? '')));
        $baseKey = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $keyInput !== '' ? $keyInput : ($title !== '' ? $title : $type))), '-') ?: 'section';
        $sectionKey = $this->uniqueSectionKey($pageId, $baseKey, isset($existing['id']) ? (int) $existing['id'] : null);
        $imagePath = $existing['image_path'] ?? null;
        if (!empty($_FILES['section_image']['name'])) $imagePath = (new CmsMediaService())->store($_FILES['section_image'], $title !== '' ? $title : $type);
        $sort = max(-10000, min(10000, (int) ($_POST['sort_order'] ?? ($existing['sort_order'] ?? 100))));
        return [
            'page_id'=>$pageId,
            'section_key'=>$sectionKey,
            'section_type'=>$type,
            'eyebrow'=>trim((string) ($_POST['section_eyebrow'] ?? '')),
            'title'=>$title,
            'body'=>trim((string) ($_POST['section_body'] ?? '')),
            'image_path'=>$imagePath,
            'image_alt'=>trim((string) ($_POST['image_alt'] ?? '')),
            'link_label'=>trim((string) ($_POST['link_label'] ?? '')),
            'link_url'=>$link,
            'items_json'=>$items ? json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            'module_key'=>$module !== '' ? $module : null,
            'style_variant'=>$style,
            'status'=>in_array($_POST['section_status'] ?? '', ['draft','published'], true) ? $_POST['section_status'] : 'draft',
            'sort_order'=>$sort,
        ];
    }

    private function saveSectionTranslations(Database $db, int $sectionId): void
    {
        $this->saveTranslations($db, 'page_section', $sectionId, ['eyebrow','title','body','image_alt','link_label','items_json']);
    }

    private function parseSectionItems(string $input): array
    {
        $items = [];
        foreach (preg_split('/\\R/', $input) ?: [] as $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode('|', $line, 3));
            $title = mb_substr($parts[0] ?? '', 0, 180);
            $text = mb_substr($parts[1] ?? '', 0, 800);
            $link = $this->safeSectionLink($parts[2] ?? '');
            if ($title === '' && $text === '') continue;
            $items[] = ['title'=>$title,'text'=>$text,'link'=>$link];
            if (count($items) >= 24) break;
        }
        return $items;
    }

    private function safeSectionLink(string $link): string
    {
        $link = trim($link);
        if ($link === '') return '';
        if (str_starts_with($link, '#') || str_starts_with($link, '/') || preg_match('#^(https://|mailto:|tel:)#i', $link)) return mb_substr($link, 0, 500);
        if (preg_match('#^[a-z0-9][a-z0-9/_?&=.%+-]*$#i', $link)) return mb_substr($link, 0, 500);
        throw new RuntimeException('Links must use an internal path, https, mailto, tel or a page anchor.');
    }

    private function uniqueSectionKey(int $pageId, string $base, ?int $ignoreId): string
    {
        $key = mb_substr($base, 0, 105); $suffix = 2; $db = Database::get();
        while ($db->fetch('SELECT id FROM page_sections WHERE page_id=:page AND section_key=:key' . ($ignoreId ? ' AND id<>:id' : ''), $ignoreId ? ['page'=>$pageId,'key'=>$key,'id'=>$ignoreId] : ['page'=>$pageId,'key'=>$key])) {
            $key = mb_substr($base, 0, 105) . '-' . $suffix++;
        }
        return $key;
    }

    private function sectionTypes(): array
    {
        return [
            'rich_text'=>'Rich text','image_text'=>'Image + text','feature_grid'=>'Feature cards','stats'=>'Statistics','call_to_action'=>'Call to action','module_feed'=>'Live content module',
        ];
    }

    private function sectionStyles(): array
    {
        return ['light'=>'White','soft'=>'Soft teal','teal'=>'Institutional teal','gold'=>'Gold accent','dark'=>'Dark contrast','image_left'=>'Image left','image_right'=>'Image right'];
    }

    private function moduleSources(): array
    {
        return ['programs'=>'Programmes','admissions'=>'Published admission cycles','notices'=>'Notices','facilities'=>'Facilities','faculty'=>'Faculty','gallery'=>'Gallery','faqs'=>'FAQs'];
    }

    private function moduleData(array $definition, ?array $existing): array
    {
        $data = [];
        foreach ($definition['fields'] as $name => $field) {
            if (($field['type'] ?? '') === 'file') continue;
            $value = $_POST[$name] ?? null;
            if (($field['required'] ?? false) && trim((string) $value) === '') throw new RuntimeException($field['label'] . ' is required.');
            if (($field['type'] ?? '') === 'checkbox') $value = isset($_POST[$name]) ? 1 : 0;
            elseif (($field['type'] ?? '') === 'number') $value = (int) ($value ?? 0);
            elseif (($field['type'] ?? '') === 'department') $value = $value ? (int) $value : null;
            elseif (($field['type'] ?? '') === 'datetime') $value = $value ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string) $value))) : null;
            elseif (($field['type'] ?? '') === 'date' && trim((string) $value) === '') $value = null;
            else $value = trim((string) ($value ?? ''));
            $data[$name] = $value;
        }
        if (isset($definition['slug_source'])) {
            $data['slug'] = $this->uniqueSlug($definition['table'], (string) $data[$definition['slug_source']], $existing['id'] ?? null);
        }
        if (!empty($definition['file_column']) && !empty($_FILES['image']['name'])) {
            $data[$definition['file_column']] = (new CmsMediaService())->store($_FILES['image'], (string) ($data[$definition['title_column']] ?? ''));
        } elseif (!empty($definition['file_column']) && $existing) {
            $data[$definition['file_column']] = $existing[$definition['file_column']];
        } elseif (!empty($definition['file_required'])) {
            throw new RuntimeException('An image is required.');
        }
        if ($definition['table'] === 'notices') {
            $data['created_by'] = $existing['created_by'] ?? Auth::id();
            if ($data['status'] === 'published' && empty($data['published_at'])) $data['published_at'] = date('Y-m-d H:i:s');
            $data['attachment_path'] = $existing['attachment_path'] ?? null;
        }
        return $data;
    }

    private function saveTranslations(Database $db, string $entity, int $id, array $fields): void
    {
        foreach (['bn','hi'] as $locale) {
            $values = [];
            foreach ($fields as $field) $values[$field] = trim((string) ($_POST[$locale . '_' . $field] ?? ''));
            $existing = $db->fetch('SELECT id FROM content_translations WHERE entity_type = :type AND entity_id = :id AND locale = :locale', ['type' => $entity, 'id' => $id, 'locale' => $locale]);
            $data = ['fields_json' => json_encode($values, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')];
            $existing ? $db->update('content_translations', $data, 'id = :translation_id', ['translation_id' => $existing['id']]) : $db->insert('content_translations', $data + ['entity_type' => $entity, 'entity_id' => $id, 'locale' => $locale, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function translations(string $entity, int $id): array
    {
        $rows = Database::get()->all('SELECT locale, fields_json FROM content_translations WHERE entity_type = :type AND entity_id = :id', ['type' => $entity, 'id' => $id]);
        $result = [];
        foreach ($rows as $row) $result[$row['locale']] = json_decode((string) $row['fields_json'], true) ?: [];
        return $result;
    }

    private function uniqueSlug(string $table, string $source, ?int $ignoreId): string
    {
        $base = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $source)), '-') ?: 'content';
        $slug = $base; $suffix = 2; $db = Database::get();
        while ($db->fetch('SELECT id FROM `' . $table . '` WHERE slug = :slug' . ($ignoreId ? ' AND id <> :id' : ''), $ignoreId ? ['slug' => $slug, 'id' => $ignoreId] : ['slug' => $slug])) $slug = $base . '-' . $suffix++;
        return $slug;
    }

    private function definition(string $module): ?array
    {
        $commonStatus = ['draft' => 'Draft', 'published' => 'Published'];
        $definitions = [
            'notices' => [
                'table' => 'notices', 'entity' => 'notice', 'label' => 'Notices', 'singular' => 'Notice', 'title_column' => 'title', 'order' => 'is_pinned DESC, published_at DESC, id DESC', 'slug_source' => 'title',
                'translatable' => ['title','excerpt','body'],
                'fields' => [
                    'title' => ['label'=>'Title','type'=>'text','required'=>true], 'category' => ['label'=>'Category','type'=>'text','required'=>true],
                    'excerpt' => ['label'=>'Short summary','type'=>'textarea','required'=>true], 'body' => ['label'=>'Full notice','type'=>'textarea','required'=>true],
                    'audience' => ['label'=>'Audience','type'=>'select','options'=>['public'=>'Public','applicants'=>'Applicants','students'=>'Students'],'required'=>true],
                    'status' => ['label'=>'Status','type'=>'select','options'=>$commonStatus,'required'=>true], 'is_pinned' => ['label'=>'Pin this notice','type'=>'checkbox'],
                    'published_at' => ['label'=>'Publish date/time','type'=>'datetime'], 'expires_at' => ['label'=>'Expiry date','type'=>'date'],
                ],
            ],
            'faculty' => [
                'table' => 'faculty', 'entity' => 'faculty', 'label' => 'Faculty directory', 'singular' => 'Faculty profile', 'title_column' => 'name', 'order' => 'sort_order, name', 'file_column' => 'photo_path',
                'translatable' => ['name','designation','qualifications','specialisation','bio'],
                'fields' => [
                    'department_id' => ['label'=>'Department','type'=>'department'], 'name' => ['label'=>'Name','type'=>'text','required'=>true],
                    'designation' => ['label'=>'Designation','type'=>'text','required'=>true], 'qualifications' => ['label'=>'Qualifications','type'=>'text'],
                    'specialisation' => ['label'=>'Specialisation','type'=>'text'], 'bio' => ['label'=>'Biography','type'=>'textarea'], 'email' => ['label'=>'Public email','type'=>'email'],
                    'status' => ['label'=>'Status','type'=>'select','options'=>['active'=>'Active','inactive'=>'Inactive'],'required'=>true], 'sort_order' => ['label'=>'Display order','type'=>'number'],
                    'image' => ['label'=>'Portrait image','type'=>'file'],
                ],
            ],
            'facilities' => [
                'table' => 'facilities', 'entity' => 'facility', 'label' => 'Facilities', 'singular' => 'Facility', 'title_column' => 'name', 'order' => 'sort_order, name', 'slug_source' => 'name', 'file_column' => 'image_path',
                'translatable' => ['name','summary','description'],
                'fields' => [
                    'name' => ['label'=>'Name','type'=>'text','required'=>true], 'icon' => ['label'=>'Short icon/symbol','type'=>'text'],
                    'summary' => ['label'=>'Summary','type'=>'textarea','required'=>true], 'description' => ['label'=>'Description','type'=>'textarea'],
                    'status' => ['label'=>'Status','type'=>'select','options'=>$commonStatus,'required'=>true], 'sort_order' => ['label'=>'Display order','type'=>'number'],
                    'image' => ['label'=>'Facility image','type'=>'file'],
                ],
            ],
            'faqs' => [
                'table' => 'faqs', 'entity' => 'faq', 'label' => 'Frequently asked questions', 'singular' => 'FAQ', 'title_column' => 'question', 'order' => 'category, sort_order, id',
                'translatable' => ['question','answer'],
                'fields' => [
                    'category' => ['label'=>'Category','type'=>'text','required'=>true], 'question' => ['label'=>'Question','type'=>'text','required'=>true],
                    'answer' => ['label'=>'Answer','type'=>'textarea','required'=>true], 'status' => ['label'=>'Status','type'=>'select','options'=>$commonStatus,'required'=>true],
                    'sort_order' => ['label'=>'Display order','type'=>'number'],
                ],
            ],
            'gallery' => [
                'table' => 'gallery_items', 'entity' => 'gallery_item', 'label' => 'Gallery', 'singular' => 'Gallery item', 'title_column' => 'title', 'order' => 'sort_order, created_at DESC', 'file_column' => 'image_path', 'file_required' => true,
                'translatable' => ['title','caption'],
                'fields' => [
                    'title' => ['label'=>'Title','type'=>'text','required'=>true], 'caption' => ['label'=>'Caption','type'=>'textarea'], 'album' => ['label'=>'Album','type'=>'text'],
                    'status' => ['label'=>'Status','type'=>'select','options'=>$commonStatus,'required'=>true], 'sort_order' => ['label'=>'Display order','type'=>'number'],
                    'image' => ['label'=>'Gallery image','type'=>'file'],
                ],
            ],
        ];
        return $definitions[$module] ?? null;
    }
}
