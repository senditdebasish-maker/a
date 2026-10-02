<?php

declare(strict_types=1);

use App\Core\MigrationContext;

return [
    'version' => '004_cms_page_builder',
    'description' => 'Add a data-preserving multilingual section builder for public website pages.',
    'up' => static function (MigrationContext $m): void {
        $m->createTable('page_sections', <<<'SQL'
CREATE TABLE page_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    section_key VARCHAR(120) NOT NULL,
    section_type VARCHAR(40) NOT NULL,
    eyebrow VARCHAR(160) NULL,
    title VARCHAR(255) NULL,
    body LONGTEXT NULL,
    image_path VARCHAR(500) NULL,
    image_alt VARCHAR(255) NULL,
    link_label VARCHAR(120) NULL,
    link_url VARCHAR(500) NULL,
    items_json LONGTEXT NULL,
    module_key VARCHAR(60) NULL,
    style_variant VARCHAR(40) NOT NULL DEFAULT 'light',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    sort_order INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_page_section_key (page_id, section_key),
    INDEX idx_page_sections_public (page_id, status, sort_order),
    CONSTRAINT fk_page_sections_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_page_sections_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_page_sections_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        if ($m->isDryRun()) {
            $m->note('PLAN add missing public route page records and preserve existing page bodies as rich-text sections');
            return;
        }

        $pages = [
            ['home', 'Learn the science. Lead the change.', 'Pharmacy learning, campus life and admissions at Netaji College of Pharmacy.'],
            ['about', 'About Netaji College of Pharmacy', 'A college shaped around science, service and student growth.'],
            ['programs', 'Study the science behind better health.', 'Explore programmes, curriculum and professional pathways.'],
            ['admissions', 'Your next step, made clear.', 'Published admission cycles, eligibility, dates, fees and application guidance.'],
            ['facilities', 'Spaces that invite careful discovery.', 'Explore laboratories, learning resources and student support spaces.'],
            ['faculty', 'Guidance shaped by curiosity and care.', 'Meet the teachers and mentors who guide pharmacy learning.'],
            ['notices', 'Notices & announcements', 'Official public updates from Netaji College of Pharmacy.'],
            ['gallery', 'Learning, belonging and becoming.', 'A view of learning, laboratories and campus life.'],
            ['faq', 'Frequently asked questions', 'Clear answers about admissions, documents, payments and student services.'],
            ['contact', 'Talk to us', 'Contact the admissions team or send a secure enquiry.'],
            ['privacy', 'Privacy notice', 'How applicant and student information is collected, used and protected.'],
            ['terms', 'Terms of use', 'Rules for responsible use of the website and admissions portal.'],
        ];
        foreach ($pages as [$slug, $title, $excerpt]) {
            $m->execute(
                "INSERT INTO pages (title,slug,eyebrow,excerpt,body,template,hero_image,meta_title,meta_description,status,published_at,created_by,updated_by,created_at,updated_at) VALUES (:title,:slug,'Netaji College of Pharmacy',:excerpt,'','standard',NULL,:title,:excerpt,'published',NOW(),NULL,NULL,NOW(),NOW()) ON DUPLICATE KEY UPDATE slug=VALUES(slug)",
                ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt],
                "Ensure public page shell {$slug} exists"
            );
        }
        $m->execute(
            "INSERT INTO page_sections (page_id,section_key,section_type,eyebrow,title,body,image_path,image_alt,link_label,link_url,items_json,module_key,style_variant,status,sort_order,created_by,updated_by,created_at,updated_at)
             SELECT p.id,'overview','rich_text',NULL,NULL,p.body,NULL,NULL,NULL,NULL,NULL,NULL,'light','published',10,p.created_by,p.updated_by,p.created_at,p.updated_at
             FROM pages p
             WHERE p.body IS NOT NULL AND TRIM(p.body) <> ''
               AND NOT EXISTS (SELECT 1 FROM page_sections ps WHERE ps.page_id=p.id)",
            [],
            'Preserve existing page bodies as editable rich-text sections'
        );

        $homeId = (int) $m->value("SELECT id FROM pages WHERE slug='home' LIMIT 1");
        $homeSections = [
            ['purpose','feature_grid','A learning community with purpose','From molecule to medicine, understand the whole journey.','Pharmaceutical education grounded in careful science, ethical decisions and the people every medicine ultimately serves.',null,null,null,null,[['title'=>'Scientific depth','text'=>'Strong foundations across pharmaceutical disciplines.','link'=>'programs'],['title'=>'Practice-led learning','text'=>'Structured laboratory and field-based experiences.','link'=>'facilities'],['title'=>'Career perspective','text'=>'Exposure to diverse roles across the medicines ecosystem.','link'=>'programs'],['title'=>'Responsible service','text'=>'Professional practice grounded in patient wellbeing.','link'=>'about']],'soft',10],
            ['programme','image_text','Undergraduate programme','Build your foundation in pharmacy.','Explore drug discovery, formulation, analysis, pharmacology, natural products and pharmacy practice through guided practical work.','assets/images/research-lab.jpg','Students learning in a pharmaceutical laboratory','Discover the programme','programs',null,'image_left',20],
            ['why-netaji','feature_grid','Why Netaji','A focused environment for curious learners.','Learn in an environment that connects scientific practice, close guidance and a secure applicant journey.',null,null,null,null,[['title'=>'Spaces built for exploration','text'=>'Purpose-planned pharmaceutical laboratories connect principle to practice.','link'=>'facilities'],['title'=>'Mentors who stay close','text'=>'Faculty guidance supports questions, practical work and professional direction.','link'=>'faculty'],['title'=>'One connected journey','text'=>'Admissions, updates, support and records come together in one secure portal.','link'=>'admissions']],'light',30],
            ['admission-journey','feature_grid','Your admission journey','Four clear steps from exploration to enrolment.','Review current cycle requirements before creating one secure application.',null,null,null,null,[['title'=>'Explore','text'=>'Review programmes, eligibility, fees and documents.','link'=>'programs'],['title'=>'Apply','text'=>'Create your account and complete the guided form.','link'=>'admissions'],['title'=>'Verify','text'=>'Follow document checks and correction requests.','link'=>'login'],['title'=>'Join','text'=>'Complete verification and track the final decision.','link'=>'login']],'soft',40],
            ['latest-notices','module_feed','College updates','Latest notices & announcements','Published updates appear here automatically from the existing notices module.',null,null,'View all notices','notices',null,'light',50,'notices'],
            ['admissions-callout','call_to_action','Admissions','Ready to take the next step?','Review a published admission cycle, confirm eligibility and begin only when the official application window is open.',null,null,'View admissions','admissions',null,'teal',60],
        ];
        $starterKeys = "'purpose','programme','why-netaji','admission-journey','latest-notices','admissions-callout'";
        $customHomeSections = (int) $m->value("SELECT COUNT(*) FROM page_sections WHERE page_id=:page AND section_key NOT IN ({$starterKeys})", ['page'=>$homeId]);
        if ($customHomeSections === 0) {
            foreach ($homeSections as $definition) {
                [$key,$type,$eyebrow,$title,$body,$image,$imageAlt,$linkLabel,$linkUrl,$items,$style,$sort,$module] = array_pad($definition, 13, null);
                $m->execute(
                    "INSERT INTO page_sections (page_id,section_key,section_type,eyebrow,title,body,image_path,image_alt,link_label,link_url,items_json,module_key,style_variant,status,sort_order,created_by,updated_by,created_at,updated_at)
                     SELECT :page,:section_key,:section_type,:eyebrow,:title,:body,:image_path,:image_alt,:link_label,:link_url,:items_json,:module_key,:style_variant,'published',:sort_order,NULL,NULL,NOW(),NOW()
                     WHERE NOT EXISTS (SELECT 1 FROM page_sections WHERE page_id=:check_page AND section_key=:check_key)",
                    ['page'=>$homeId,'section_key'=>$key,'section_type'=>$type,'eyebrow'=>$eyebrow,'title'=>$title,'body'=>$body,'image_path'=>$image,'image_alt'=>$imageAlt,'link_label'=>$linkLabel,'link_url'=>$linkUrl,'items_json'=>$items ? json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,'module_key'=>$module,'style_variant'=>$style,'sort_order'=>$sort,'check_page'=>$homeId,'check_key'=>$key],
                    "Ensure editable home section {$key} exists"
                );
            }
        } else {
            $m->note('SKIP starter home composition because existing custom content was preserved');
        }
    },
    'verify' => static function (MigrationContext $m): void {
        if ($m->isDryRun()) {
            $m->note('PLAN verify page builder table and public page shells');
            return;
        }
        $m->assert($m->tableExists('page_sections'), 'Migration verification failed: page_sections is missing.');
        $m->assert((int) $m->value("SELECT COUNT(*) FROM pages WHERE slug IN ('home','about','programs','admissions','facilities','faculty','notices','gallery','faq','contact','privacy','terms')") === 12, 'Migration verification failed: one or more public page shells are missing.');
        $unpreserved = (int) $m->value("SELECT COUNT(*) FROM pages p WHERE p.body IS NOT NULL AND TRIM(p.body) <> '' AND NOT EXISTS (SELECT 1 FROM page_sections ps WHERE ps.page_id=p.id)");
        $m->assert($unpreserved === 0, 'Migration verification failed: an existing page body was not preserved in the section builder.');
        $customHomeSections = (int) $m->value("SELECT COUNT(*) FROM page_sections ps JOIN pages p ON p.id=ps.page_id WHERE p.slug='home' AND ps.section_key NOT IN ('purpose','programme','why-netaji','admission-journey','latest-notices','admissions-callout')");
        if ($customHomeSections === 0) {
            $homeSectionCount = (int) $m->value("SELECT COUNT(*) FROM page_sections ps JOIN pages p ON p.id=ps.page_id WHERE p.slug='home' AND ps.status='published'");
            $m->assert($homeSectionCount >= 6, 'Migration verification failed: editable home-page starter sections are missing.');
        }
    },
];
