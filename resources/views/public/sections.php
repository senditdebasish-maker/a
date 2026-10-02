<?php
$sectionUrl = static function (?string $link): string {
    $link = trim((string) $link);
    if ($link === '') return '';
    if (str_starts_with($link, '#') || preg_match('#^(https://|mailto:|tel:)#i', $link)) return $link;
    return url(ltrim($link, '/'));
};
$paragraphs = static function (?string $body): array {
    return array_values(array_filter(preg_split('/\R{2,}/', trim((string)$body)) ?: [], static fn(string $part): bool => trim($part) !== ''));
};
?>
<div class="cms-page-sections" aria-label="Additional page content">
<?php foreach ($cmsSections as $section):
    $type = (string)$section['section_type'];
    $style = (string)$section['style_variant'];
    $link = $sectionUrl($section['link_url'] ?? '');
?>
<section class="cms-section cms-section-<?= e($type) ?> cms-style-<?= e($style) ?>" id="<?= e($section['section_key']) ?>">
    <div class="container">
    <?php if ($type === 'image_text'): ?>
        <div class="cms-image-text <?= $style==='image_right'?'reverse':'' ?>">
            <?php if (!empty($section['image_path'])): ?><div class="cms-section-image"><img src="<?= url($section['image_path']) ?>" alt="<?= e($section['image_alt'] ?: $section['title']) ?>" loading="lazy"></div><?php endif ?>
            <div class="cms-section-copy"><?php if($section['eyebrow']):?><span class="eyebrow"><?= e($section['eyebrow']) ?></span><?php endif?><h2><?= e($section['title']) ?></h2><?php foreach($paragraphs($section['body']) as $paragraph):?><p><?= nl2br(e($paragraph)) ?></p><?php endforeach?><?php if($link&&$section['link_label']):?><a class="button button-primary" href="<?= e($link) ?>"><?= e($section['link_label']) ?> →</a><?php endif?></div>
        </div>
    <?php elseif ($type === 'feature_grid'): ?>
        <div class="section-heading centered"><?php if($section['eyebrow']):?><span class="eyebrow"><?= e($section['eyebrow']) ?></span><?php endif?><h2><?= e($section['title']) ?></h2><?php if($section['body']):?><p><?= e($section['body']) ?></p><?php endif?></div>
        <div class="cms-feature-grid"><?php foreach($section['items'] as $index=>$item):?><article><span class="card-number"><?= str_pad((string)($index+1),2,'0',STR_PAD_LEFT) ?></span><h3><?= e($item['title']??'') ?></h3><p><?= e($item['text']??'') ?></p><?php if(!empty($item['link'])):?><a href="<?= e($sectionUrl($item['link'])) ?>">Learn more →</a><?php endif?></article><?php endforeach?></div>
    <?php elseif ($type === 'stats'): ?>
        <div class="section-heading centered"><?php if($section['eyebrow']):?><span class="eyebrow"><?= e($section['eyebrow']) ?></span><?php endif?><h2><?= e($section['title']) ?></h2><?php if($section['body']):?><p><?= e($section['body']) ?></p><?php endif?></div>
        <div class="cms-stats-grid"><?php foreach($section['items'] as $item):?><article><strong><?= e($item['title']??'') ?></strong><span><?= e($item['text']??'') ?></span></article><?php endforeach?></div>
    <?php elseif ($type === 'call_to_action'): ?>
        <div class="cms-cta"><div><?php if($section['eyebrow']):?><span class="eyebrow eyebrow-light"><?= e($section['eyebrow']) ?></span><?php endif?><h2><?= e($section['title']) ?></h2><?php foreach($paragraphs($section['body']) as $paragraph):?><p><?= nl2br(e($paragraph)) ?></p><?php endforeach?></div><?php if($link&&$section['link_label']):?><a class="button button-gold" href="<?= e($link) ?>"><?= e($section['link_label']) ?> →</a><?php endif?></div>
    <?php elseif ($type === 'module_feed'): ?>
        <div class="section-heading heading-row"><div><?php if($section['eyebrow']):?><span class="eyebrow"><?= e($section['eyebrow']) ?></span><?php endif?><h2><?= e($section['title']) ?></h2><?php if($section['body']):?><p><?= e($section['body']) ?></p><?php endif?></div><?php if($link&&$section['link_label']):?><a class="text-link" href="<?= e($link) ?>"><?= e($section['link_label']) ?> →</a><?php endif?></div>
        <div class="cms-module-grid cms-module-<?= e($section['module_key']) ?>">
        <?php foreach($section['feed_items'] as $item): ?>
            <?php if($section['module_key']==='programs'): ?><article><span class="eyebrow"><?= e($item['code']) ?></span><h3><?= e($item['name']) ?></h3><p><?= e($item['summary']) ?></p><a href="<?= url('programs/'.$item['slug']) ?>">View programme →</a></article>
            <?php elseif($section['module_key']==='admissions'): ?><article><span class="status-pill status-<?= e($item['effective_status']) ?>"><?= e(ucwords(str_replace('_',' ',$item['effective_status']))) ?></span><h3><?= e($item['name']) ?></h3><p><?= e($item['summary']) ?></p><a href="<?= url('admissions/'.$item['slug']) ?>">Admission details →</a></article>
            <?php elseif($section['module_key']==='notices'): ?><article><small><?= e($item['category']) ?> · <?= format_date($item['published_at']) ?></small><h3><?= e($item['title']) ?></h3><p><?= e($item['excerpt']) ?></p><a href="<?= url('notices/'.$item['slug']) ?>">Read notice →</a></article>
            <?php elseif($section['module_key']==='facilities'): ?><article><?php if($item['image_path']):?><img src="<?= url($item['image_path']) ?>" alt="<?= e($item['name']) ?>" loading="lazy"><?php endif?><h3><?= e($item['name']) ?></h3><p><?= e($item['summary']) ?></p></article>
            <?php elseif($section['module_key']==='faculty'): ?><article><h3><?= e($item['name']) ?></h3><small><?= e($item['designation']) ?></small><p><?= e($item['specialisation']) ?></p></article>
            <?php elseif($section['module_key']==='gallery'): ?><figure><img src="<?= url($item['image_path']) ?>" alt="<?= e($item['title']) ?>" loading="lazy"><figcaption><b><?= e($item['title']) ?></b><span><?= e($item['caption']) ?></span></figcaption></figure>
            <?php elseif($section['module_key']==='faqs'): ?><details><summary><?= e($item['question']) ?></summary><p><?= nl2br(e($item['answer'])) ?></p></details>
            <?php endif ?>
        <?php endforeach ?>
        </div>
    <?php else: ?>
        <div class="cms-rich-text narrow-content"><?php if($section['eyebrow']):?><span class="eyebrow"><?= e($section['eyebrow']) ?></span><?php endif?><?php if($section['title']):?><h2><?= e($section['title']) ?></h2><?php endif?><div class="prose"><?php foreach($paragraphs($section['body']) as $paragraph):?><p><?= nl2br(e($paragraph)) ?></p><?php endforeach?></div><?php if($link&&$section['link_label']):?><a class="button button-primary" href="<?= e($link) ?>"><?= e($section['link_label']) ?> →</a><?php endif?></div>
    <?php endif ?>
    </div>
</section>
<?php endforeach ?>
</div>
