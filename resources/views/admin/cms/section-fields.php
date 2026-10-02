<?php
$bn = $sectionTranslation['bn'] ?? [];
$hi = $sectionTranslation['hi'] ?? [];
?>
<div class="section-field-grid">
    <fieldset><legend>Layout and publishing</legend><div class="form-grid three">
        <label><span>Section type</span><select name="section_type" required><?php foreach($sectionTypes as $value=>$label):?><option value="<?= e($value) ?>"<?= selected($section['section_type'],$value) ?>><?= e($label) ?></option><?php endforeach?></select></label>
        <label><span>Visual style</span><select name="style_variant"><?php foreach($sectionStyles as $value=>$label):?><option value="<?= e($value) ?>"<?= selected($section['style_variant'],$value) ?>><?= e($label) ?></option><?php endforeach?></select></label>
        <label><span>Section state</span><select name="section_status"><option value="draft"<?= selected($section['status'],'draft') ?>>Draft</option><option value="published"<?= selected($section['status'],'published') ?>>Published</option></select></label>
        <label><span>Anchor key</span><input name="section_key" value="<?= e($section['section_key']) ?>" placeholder="Generated from title"><small>Stable in-page link, for example #campus-life.</small></label>
        <label><span>Display order</span><input type="number" name="sort_order" value="<?= e($section['sort_order']) ?>"></label>
        <label><span>Live module source</span><select name="module_key"><option value="">Not a module section</option><?php foreach($moduleSources as $value=>$label):?><option value="<?= e($value) ?>"<?= selected($section['module_key'],$value) ?>><?= e($label) ?></option><?php endforeach?></select><small>Used only by the Live content module type.</small></label>
    </div></fieldset>

    <fieldset><legend>English source content</legend><div class="form-grid">
        <label><span>Eyebrow</span><input name="section_eyebrow" value="<?= e($section['eyebrow']) ?>"></label>
        <label><span>Section title</span><input name="section_title" value="<?= e($section['title']) ?>"></label>
        <label class="full"><span>Body copy</span><textarea name="section_body" rows="5"><?= e($section['body']) ?></textarea><small>Plain text with paragraph breaks is escaped safely on the public site.</small></label>
        <label class="full"><span>Cards or statistics — one per line</span><textarea name="items" rows="5" placeholder="Scientific depth | Strong foundations across pharmaceutical disciplines. | facilities"><?= e($itemsText($section['items_json'])) ?></textarea><small>Use: Title | Description or value | optional internal/https link. Up to 24 items.</small></label>
        <label><span>Button label</span><input name="link_label" value="<?= e($section['link_label']) ?>"></label>
        <label><span>Button destination</span><input name="link_url" value="<?= e($section['link_url']) ?>" placeholder="admissions or https://..."></label>
        <label><span>Image</span><input type="file" name="section_image" accept="image/jpeg,image/png,image/webp"><small><?= $section['image_path']?'Current: '.e($section['image_path']):'Optional for Image + text.' ?></small></label>
        <label><span>Image alternative text</span><input name="image_alt" value="<?= e($section['image_alt']) ?>"></label>
    </div></fieldset>

    <details class="translation-section"><summary>বাংলা translation <small><?= !empty($bn['title'])?'Added':'English fallback' ?></small></summary><div class="form-grid">
        <label><span>Eyebrow</span><input name="bn_eyebrow" value="<?= e($bn['eyebrow']??'') ?>"></label><label><span>বাংলা শিরোনাম</span><input name="bn_title" value="<?= e($bn['title']??'') ?>"></label>
        <label class="full"><span>মূল বিষয়বস্তু</span><textarea name="bn_body" rows="4"><?= e($bn['body']??'') ?></textarea></label>
        <label class="full"><span>Cards — Title | Description | Link</span><textarea name="bn_items" rows="4"><?= e($itemsText($bn['items_json']??null)) ?></textarea></label>
        <label><span>Button label</span><input name="bn_link_label" value="<?= e($bn['link_label']??'') ?>"></label><label><span>Image alternative text</span><input name="bn_image_alt" value="<?= e($bn['image_alt']??'') ?>"></label>
    </div></details>
    <details class="translation-section"><summary>हिन्दी translation <small><?= !empty($hi['title'])?'Added':'English fallback' ?></small></summary><div class="form-grid">
        <label><span>Eyebrow</span><input name="hi_eyebrow" value="<?= e($hi['eyebrow']??'') ?>"></label><label><span>हिन्दी शीर्षक</span><input name="hi_title" value="<?= e($hi['title']??'') ?>"></label>
        <label class="full"><span>मुख्य विषय</span><textarea name="hi_body" rows="4"><?= e($hi['body']??'') ?></textarea></label>
        <label class="full"><span>Cards — Title | Description | Link</span><textarea name="hi_items" rows="4"><?= e($itemsText($hi['items_json']??null)) ?></textarea></label>
        <label><span>Button label</span><input name="hi_link_label" value="<?= e($hi['link_label']??'') ?>"></label><label><span>Image alternative text</span><input name="hi_image_alt" value="<?= e($hi['image_alt']??'') ?>"></label>
    </div></details>
</div>
