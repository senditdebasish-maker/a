<?php
$itemsText = static function (?string $json): string {
    $items = json_decode((string) $json, true);
    if (!is_array($items)) return '';
    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $lines[] = trim((string)($item['title'] ?? '')) . ' | ' . trim((string)($item['text'] ?? '')) . ' | ' . trim((string)($item['link'] ?? ''));
    }
    return implode("\n", $lines);
};
$previewPath = $page['slug'] === 'home' ? '' : (string) $page['slug'];
?>
<div class="page-heading admin-heading">
    <div><a class="back-link" href="<?= url('admin/cms') ?>">← Website content</a><span class="eyebrow">Flexible page builder</span><h1><?= e($page['title']) ?></h1><p>Manage the page introduction, search metadata and ordered, reusable content sections.</p></div>
    <div class="heading-actions"><a class="button button-outline" href="<?= url($previewPath) ?>" target="_blank" rel="noopener">Preview ↗</a><a class="button button-primary" href="#add-section">+ Add section</a></div>
</div>

<form class="cms-editor" method="post" action="<?= url('admin/cms/pages/'.$page['id']) ?>">
    <?= csrf_field() ?>
    <section class="card">
        <div class="card-heading"><div><span class="eyebrow">Page shell</span><h2>Title and introduction</h2></div><code>/<?= e($previewPath) ?></code></div>
        <div class="editor-tabs" data-tabs>
            <button type="button" class="active" data-tab="english">English <small>Source</small></button>
            <button type="button" data-tab="bengali">বাংলা <small><?= !empty($translations['bn']['title'])?'Translated':'English fallback' ?></small></button>
            <button type="button" data-tab="hindi">हिन्दी <small><?= !empty($translations['hi']['title'])?'Translated':'English fallback' ?></small></button>
        </div>
        <div class="editor-panel active" data-panel="english"><div class="form-grid"><label><span>Page title *</span><input name="title" value="<?= e($page['title']) ?>" required></label><label><span>Eyebrow</span><input name="eyebrow" value="<?= e($page['eyebrow']) ?>"></label><label class="full"><span>Short introduction</span><textarea name="excerpt" rows="3"><?= e($page['excerpt']) ?></textarea></label><label class="full"><span>Legacy body fallback</span><textarea name="body" rows="6"><?= e($page['body']) ?></textarea><small>Shown only when this page has no published builder section. Existing copy is retained for safe upgrades.</small></label></div></div>
        <div class="editor-panel" data-panel="bengali"><div class="translation-hint">Blank fields automatically fall back to English.</div><div class="form-grid"><label><span>বাংলা শিরোনাম</span><input name="bn_title" value="<?= e($translations['bn']['title']??'') ?>"></label><label><span>Eyebrow</span><input name="bn_eyebrow" value="<?= e($translations['bn']['eyebrow']??'') ?>"></label><label class="full"><span>সংক্ষিপ্ত পরিচিতি</span><textarea name="bn_excerpt" rows="3"><?= e($translations['bn']['excerpt']??'') ?></textarea></label><label class="full"><span>Fallback body</span><textarea name="bn_body" rows="6"><?= e($translations['bn']['body']??'') ?></textarea></label></div></div>
        <div class="editor-panel" data-panel="hindi"><div class="translation-hint">Blank fields automatically fall back to English.</div><div class="form-grid"><label><span>हिन्दी शीर्षक</span><input name="hi_title" value="<?= e($translations['hi']['title']??'') ?>"></label><label><span>Eyebrow</span><input name="hi_eyebrow" value="<?= e($translations['hi']['eyebrow']??'') ?>"></label><label class="full"><span>संक्षिप्त परिचय</span><textarea name="hi_excerpt" rows="3"><?= e($translations['hi']['excerpt']??'') ?></textarea></label><label class="full"><span>Fallback body</span><textarea name="hi_body" rows="6"><?= e($translations['hi']['body']??'') ?></textarea></label></div></div>
    </section>
    <aside>
        <section class="card publish-card"><h3>Publishing</h3><label><span>Page status</span><select name="status"><option value="draft"<?= selected($page['status'],'draft') ?>>Draft</option><option value="published"<?= selected($page['status'],'published') ?>>Published</option></select></label><button class="button button-primary button-block">Save page shell</button><small>Sections have their own draft/published state.</small></section>
        <section class="card seo-card"><h3>Search preview</h3><label><span>Meta title</span><input name="meta_title" value="<?= e($page['meta_title']) ?>"></label><label><span>Meta description</span><textarea name="meta_description" rows="4"><?= e($page['meta_description']) ?></textarea></label><div class="search-preview"><b><?= e($page['meta_title']?:$page['title']) ?></b><span><?= e(url($previewPath)) ?></span><p><?= e($page['meta_description']?:$page['excerpt']) ?></p></div></section>
    </aside>
</form>

<section class="page-builder" id="page-sections">
    <div class="page-builder-heading"><div><span class="eyebrow">Page composition</span><h2>Content sections</h2><p>Sections appear in this order after the page’s route-specific content. Draft sections remain private. Moving or archiving never deletes applicant or operational data.</p></div><span class="count-badge"><?= count($sections) ?> sections</span></div>
    <?php if (!$sections): ?><div class="card empty-state"><h3>No builder sections yet</h3><p>Add a branded section below. Existing route content remains available while you build.</p></div><?php endif ?>
    <div class="section-builder-list">
        <?php foreach ($sections as $index => $section):
            $sectionTranslation = $sectionTranslations[(int)$section['id']] ?? [];
        ?>
        <article class="card section-builder-card" id="section-<?= e($section['id']) ?>">
            <div class="section-builder-summary"><div><span class="builder-order"><?= $index + 1 ?></span><div><small><?= e($sectionTypes[$section['section_type']] ?? $section['section_type']) ?> · <?= e($sectionStyles[$section['style_variant']] ?? $section['style_variant']) ?></small><h3><?= e($section['title'] ?: ucfirst(str_replace('_',' ',$section['section_key']))) ?></h3><code>#<?= e($section['section_key']) ?></code></div></div><span class="status-pill status-<?= e($section['status']) ?>"><?= e(ucfirst($section['status'])) ?></span></div>
            <div class="section-builder-actions">
                <form method="post" action="<?= url('admin/cms/pages/'.$page['id'].'/sections/'.$section['id'].'/move') ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button class="text-button" <?= $index===0?'disabled':'' ?> aria-label="Move section up">↑ Up</button></form>
                <form method="post" action="<?= url('admin/cms/pages/'.$page['id'].'/sections/'.$section['id'].'/move') ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button class="text-button" <?= $index===count($sections)-1?'disabled':'' ?> aria-label="Move section down">↓ Down</button></form>
                <a class="text-button" href="#edit-section-<?= e($section['id']) ?>">Edit fields</a>
                <form method="post" action="<?= url('admin/cms/pages/'.$page['id'].'/sections/'.$section['id'].'/archive') ?>"><?= csrf_field() ?><button class="text-button danger" data-confirm="Archive this section? Its content will be preserved but removed from the public page.">Archive</button></form>
            </div>
            <details class="section-editor" id="edit-section-<?= e($section['id']) ?>" <?= (string)($_GET['section']??'')===(string)$section['id']?'open':'' ?>><summary>Edit section content</summary>
                <form method="post" enctype="multipart/form-data" action="<?= url('admin/cms/pages/'.$page['id'].'/sections/'.$section['id']) ?>">
                    <?= csrf_field() ?>
                    <?php require BASE_PATH . '/resources/views/admin/cms/section-fields.php'; ?>
                    <div class="form-actions"><button class="button button-primary">Save section</button></div>
                </form>
            </details>
        </article>
        <?php endforeach ?>
    </div>

    <details class="card add-section-card" id="add-section" <?= !$sections?'open':'' ?>><summary><span>+</span><div><b>Add a content section</b><small>Choose a reusable branded layout and publish it when ready.</small></div></summary>
        <?php $section = ['id'=>null,'section_key'=>'','section_type'=>'rich_text','eyebrow'=>'','title'=>'','body'=>'','image_path'=>null,'image_alt'=>'','link_label'=>'','link_url'=>'','items_json'=>null,'module_key'=>null,'style_variant'=>'light','status'=>'draft','sort_order'=>(count($sections)+1)*10]; $sectionTranslation = []; ?>
        <form method="post" enctype="multipart/form-data" action="<?= url('admin/cms/pages/'.$page['id'].'/sections') ?>">
            <?= csrf_field() ?>
            <?php require BASE_PATH . '/resources/views/admin/cms/section-fields.php'; ?>
            <div class="form-actions"><button class="button button-primary">Add section</button></div>
        </form>
    </details>
</section>
