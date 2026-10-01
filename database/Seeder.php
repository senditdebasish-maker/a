<?php

declare(strict_types=1);

namespace Database;

use PDO;

final class Seeder
{
    private string $now;

    public function __construct(private readonly PDO $db)
    {
        $this->now = date('Y-m-d H:i:s');
    }

    public function run(array $admin, string $collegeName, bool $demo = false): void
    {
        $this->db->beginTransaction();
        try {
            $this->rolesAndPermissions();
            $adminId = $this->user([
                'first_name' => $admin['first_name'], 'last_name' => $admin['last_name'], 'email' => mb_strtolower($admin['email']),
                'mobile' => $admin['mobile'] ?? null, 'password' => $admin['password'], 'verified' => true,
            ], 'super-admin');
            $this->settings($collegeName);
            [$departmentId, $programId, $cycleId, $cycleProgramId] = $this->academicSeed();
            $this->documents($cycleId);
            $configurationVersionId = $this->admissionModuleSeed($cycleId, $cycleProgramId, $adminId);
            $this->cms($adminId, $departmentId);
            if ($demo) {
                $this->demoOperations($cycleId, $cycleProgramId, $configurationVersionId);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    private function rolesAndPermissions(): void
    {
        $roles = [
            ['Super Admin', 'super-admin', 'Full system and compliance administration'],
            ['Admission Officer', 'admission-officer', 'Application processing and decisions'],
            ['Application Reviewer', 'reviewer', 'Assigned application and document review'],
            ['Accounts Officer', 'accounts-officer', 'Payment verification and receipts'],
            ['Principal', 'principal', 'Executive oversight and reports'],
            ['Head of Department', 'hod', 'Department administration'],
            ['Faculty', 'faculty', 'Academic staff access'],
            ['Librarian', 'librarian', 'Library operations'],
            ['Examination Cell', 'exam-cell', 'Assessment operations'],
            ['Office Staff', 'office-staff', 'College office operations'],
            ['CMS Editor', 'cms-editor', 'Public website content'],
            ['Support Agent', 'support-agent', 'Applicant support tickets'],
            ['Auditor', 'auditor', 'Read-only compliance access'],
            ['Applicant / Student', 'applicant', 'Applicant and student self-service'],
        ];
        foreach ($roles as [$name, $slug, $description]) {
            $this->insert('roles', ['name' => $name, 'slug' => $slug, 'description' => $description, 'is_system' => 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }

        $permissions = [
            ['View dashboard','dashboard.view','dashboard'],
            ['View applications','applications.view','admissions'], ['Review applications','applications.review','admissions'],
            ['Assign applications','applications.assign','admissions'], ['Decide applications','applications.decide','admissions'],
            ['View admission configuration','admissions.view','admissions'], ['Manage admission cycles','admissions.manage','admissions'],
            ['Publish admission cycles','admissions.publish','admissions'], ['Duplicate admission cycles','admissions.duplicate','admissions'],
            ['Manage admission forms','admission_forms.manage','admissions'], ['Manage admission documents','admission_documents.manage','admissions'],
            ['Manage admission fees','admission_fees.manage','admissions'], ['Manage admission seats','admission_seats.manage','admissions'],
            ['Manage correction requests','applications.correct','admissions'], ['Withdraw applications','applications.withdraw','admissions'],
            ['View documents','documents.view','documents'], ['Verify documents','documents.verify','documents'],
            ['View payments','payments.view','finance'], ['Verify payments','payments.verify','finance'], ['Reverse payments','payments.reverse','finance'],
            ['View reports','reports.view','reports'], ['Export reports','reports.export','reports'],
            ['View CMS','cms.view','cms'], ['Edit CMS','cms.edit','cms'], ['Publish CMS','cms.publish','cms'],
            ['View users','users.view','access'], ['Manage users','users.manage','access'], ['Manage roles','roles.manage','access'],
            ['View settings','settings.view','settings'], ['Edit settings','settings.edit','settings'],
            ['View audit trail','audit.view','compliance'], ['Manage backups','backups.manage','compliance'],
            ['View support tickets','support.view','support'], ['Reply to support tickets','support.reply','support'],
            ['View academic records','academics.view','academics'], ['Manage academic records','academics.manage','academics'],
        ];
        foreach ($permissions as [$name, $slug, $module]) {
            $this->insert('permissions', ['name' => $name, 'slug' => $slug, 'module' => $module, 'description' => null, 'created_at' => $this->now]);
        }
        $super = $this->id('roles', 'slug', 'super-admin');
        foreach ($this->db->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN) as $permissionId) {
            $this->insert('role_permissions', ['role_id' => $super, 'permission_id' => $permissionId]);
        }
        $matrix = [
            'admission-officer' => ['dashboard.view','applications.view','applications.review','applications.assign','applications.decide','applications.correct','applications.withdraw','admissions.view','admissions.manage','admissions.publish','admissions.duplicate','admission_forms.manage','admission_documents.manage','admission_fees.manage','admission_seats.manage','documents.view','documents.verify','payments.view','reports.view','reports.export','support.view','support.reply'],
            'reviewer' => ['dashboard.view','applications.view','applications.review','applications.correct','admissions.view','documents.view','documents.verify'],
            'accounts-officer' => ['dashboard.view','applications.view','admissions.view','admission_fees.manage','payments.view','payments.verify','reports.view','reports.export'],
            'principal' => ['dashboard.view','applications.view','admissions.view','admissions.publish','documents.view','payments.view','reports.view','reports.export','audit.view'],
            'cms-editor' => ['dashboard.view','cms.view','cms.edit','cms.publish'],
            'support-agent' => ['dashboard.view','applications.view','support.view','support.reply'],
            'auditor' => ['dashboard.view','applications.view','admissions.view','documents.view','payments.view','reports.view','audit.view'],
            'office-staff' => ['dashboard.view','applications.view','admissions.view','documents.view','support.view'],
        ];
        foreach ($matrix as $role => $slugs) {
            $roleId = $this->id('roles', 'slug', $role);
            foreach ($slugs as $slug) {
                $this->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $this->id('permissions', 'slug', $slug)]);
            }
        }
    }

    private function settings(string $collegeName): void
    {
        $settings = [
            ['college','college_name',$collegeName,1], ['college','college_short_name','NCP',1],
            ['college','college_email','admissions@example.edu.in',1], ['college','college_phone','+91 33 4000 2027',1],
            ['college','college_address','New Town, Kolkata, West Bengal 700156',1],
            ['college','affiliation_note','Affiliation and statutory approval details must be configured by the deploying institution.',1],
            ['branding','primary_color','#075e61',1], ['branding','accent_color','#e7a83e',1],
            ['regional','timezone','Asia/Kolkata',0], ['regional','currency','INR',0], ['regional','date_format','d-m-Y',0],
            ['admissions','aadhaar_collection_stage','configurable',0], ['admissions','application_number_format','NCP-APP-{YEAR}-{SEQUENCE}',0],
            ['payments','upi_id','',0], ['payments','bank_details','Configure bank details before opening applications.',0],
            ['privacy','privacy_contact','privacy@example.edu.in',1], ['privacy','retention_months','84',0],
        ];
        foreach ($settings as [$group, $key, $value, $public]) {
            $this->insert('settings', ['group_name' => $group, 'key_name' => $key, 'value' => $value, 'value_type' => 'string', 'is_public' => $public, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
    }

    private function academicSeed(): array
    {
        $departmentId = $this->insert('departments', [
            'name' => 'Pharmaceutical Sciences', 'code' => 'PHARM', 'description' => 'Teaching and research across core pharmaceutical disciplines.',
            'status' => 'active', 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $programId = $this->insert('programs', [
            'department_id' => $departmentId, 'name' => 'Bachelor of Pharmacy', 'code' => 'BPHARM', 'slug' => 'bachelor-of-pharmacy',
            'award_type' => 'Undergraduate Degree', 'duration_years' => 4, 'total_semesters' => 8,
            'summary' => 'A rigorous four-year programme connecting drug science, patient care, research and responsible professional practice.',
            'description' => 'Explore pharmaceutics, pharmaceutical chemistry, pharmacology, pharmacognosy and pharmacy practice through classroom learning, laboratory investigation and guided field exposure.',
            'eligibility_summary' => 'Pass 10+2 or equivalent with English, Physics and Chemistry, together with Mathematics or Biology. Exact marks, age and entrance requirements are configured for each admission cycle.',
            'career_summary' => 'Graduates pursue community and hospital pharmacy, production, quality, regulatory affairs, clinical research, pharmacovigilance, sales, public service and higher studies.',
            'image_path' => 'assets/images/research-lab.jpg', 'status' => 'active', 'sort_order' => 1, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $sessionId = $this->insert('academic_sessions', [
            'name' => '2027–28', 'starts_on' => '2027-08-01', 'ends_on' => '2028-07-31', 'status' => 'upcoming', 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $cycleId = $this->insert('admission_cycles', [
            'academic_session_id' => $sessionId, 'name' => 'Undergraduate Admissions 2027–28', 'code' => 'UG-2027',
            'slug' => 'undergraduate-admissions-2027-28', 'summary' => 'Apply to the undergraduate pharmacy programme through one secure, guided application.',
            'starts_at' => '2027-01-15 10:00:00', 'ends_at' => '2027-07-15 23:59:59', 'correction_deadline' => '2027-07-22 23:59:59',
            'status' => 'published', 'instructions' => 'Create one account, complete every section, upload legible documents and retain the acknowledgement after submission.',
            'declaration_text' => 'I declare that the information and documents provided are complete and correct to the best of my knowledge.',
            'application_number_prefix' => 'NCP-APP-2027', 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $cycleProgramId = $this->insert('cycle_programs', [
            'admission_cycle_id' => $cycleId, 'program_id' => $programId, 'seat_capacity' => 100,
            'application_fee' => 1000, 'admission_fee' => 25000, 'minimum_marks_general' => 45, 'minimum_marks_reserved' => 40,
            'min_age' => 17, 'max_age' => null, 'accepted_entrance_exams' => 'WBJEE, JEE Main, or another configured qualifying route',
            'status' => 'active', 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        foreach ([['General',50],['SC',22],['ST',6],['OBC-A',10],['OBC-B',7],['EWS',5]] as [$category,$seats]) {
            $this->insert('seat_matrix', ['cycle_program_id' => $cycleProgramId, 'category' => $category, 'quota' => 'state', 'seats' => $seats, 'filled_seats' => 0, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        foreach ([
            ['subject','class_12_subjects','contains','Physics,Chemistry,Mathematics|Biology','Class 12 must include Physics, Chemistry and Mathematics or Biology.',1],
            ['marks','class_12_percentage','gte','45','Minimum marks are checked against the configured category rule.',0],
            ['age','age_on_cutoff','gte','17','Applicant should meet the configured minimum age.',0],
        ] as [$type,$field,$operator,$value,$message,$blocking]) {
            $this->insert('eligibility_rules', ['cycle_program_id' => $cycleProgramId, 'rule_type' => $type, 'field_name' => $field, 'operator' => $operator, 'comparison_value' => $value, 'message' => $message, 'is_blocking' => $blocking, 'sort_order' => 1, 'created_at' => $this->now]);
        }
        return [$departmentId, $programId, $cycleId, $cycleProgramId];
    }

    private function documents(int $cycleId): void
    {
        $docs = [
            ['Recent passport photograph','photo','Clear recent colour photograph',1,'application'],
            ['Applicant signature','signature','Signature on a plain white background',2,'application'],
            ['Class 10 marksheet','class-10-marksheet','Secondary examination marksheet',3,'application'],
            ['Class 10 certificate','class-10-certificate','Proof of date of birth and qualification',4,'application'],
            ['Class 12 marksheet','class-12-marksheet','Higher secondary marksheet',5,'application'],
            ['Class 12 certificate','class-12-certificate','Higher secondary pass certificate',6,'application'],
            ['Identity proof','identity-proof','Government-issued identity proof; Aadhaar is subject to configured collection policy',7,'admission'],
            ['Entrance scorecard','entrance-scorecard','Scorecard for the applicable entrance examination',8,'application'],
            ['Domicile certificate','domicile-certificate','Where required for seat category or quota',9,'application'],
            ['Category certificate','category-certificate','Valid certificate where reservation is claimed',10,'application'],
            ['Transfer / migration certificate','transfer-migration','Original required during final verification',11,'admission'],
        ];
        foreach ($docs as [$name,$code,$description,$sort,$stage]) {
            $typeId = $this->insert('document_types', ['name' => $name, 'code' => $code, 'description' => $description, 'allowed_mimes' => 'application/pdf,image/jpeg,image/png', 'max_size_mb' => 5, 'sort_order' => $sort, 'status' => 'active', 'created_at' => $this->now, 'updated_at' => $this->now]);
            $required = !in_array($code, ['entrance-scorecard','domicile-certificate','category-certificate','transfer-migration'], true);
            $this->insert('cycle_document_requirements', ['admission_cycle_id' => $cycleId, 'document_type_id' => $typeId, 'program_id' => null, 'category' => null, 'is_required' => $required ? 1 : 0, 'stage' => $stage, 'sort_order' => $sort, 'created_at' => $this->now]);
        }
    }

    private function admissionModuleSeed(int $cycleId, int $cycleProgramId, int $adminId): int
    {
        foreach ([
            ['General','General',0,10], ['SC','Scheduled Caste',1,20], ['ST','Scheduled Tribe',1,30],
            ['OBC-A','Other Backward Class A',1,40], ['OBC-B','Other Backward Class B',1,50], ['EWS','Economically Weaker Section',1,60],
        ] as [$code,$name,$reserved,$sort]) {
            $this->insert('admission_categories', ['code'=>$code,'name'=>$name,'is_reserved'=>$reserved,'sort_order'=>$sort,'status'=>'active','created_at'=>$this->now,'updated_at'=>$this->now]);
        }

        $cycleProgram = $this->db->query('SELECT * FROM cycle_programs WHERE id = ' . (int) $cycleProgramId)->fetch(PDO::FETCH_ASSOC);
        foreach ([['application_fee','Application fee',$cycleProgram['application_fee']],['admission_fee','Admission fee',$cycleProgram['admission_fee']]] as [$type,$label,$amount]) {
            $this->insert('admission_fee_rules', ['cycle_program_id'=>$cycleProgramId,'category_code'=>null,'fee_type'=>$type,'label'=>$label,'amount'=>$amount,'currency'=>'INR','due_at'=>null,'late_fee_amount'=>0,'refund_policy'=>null,'status'=>'active','created_at'=>$this->now,'updated_at'=>$this->now]);
        }

        $sections = [
            ['personal','Personal details','Identity and applicant profile',10],
            ['address','Address','Permanent and correspondence address',20],
            ['guardian','Parent / guardian','Parent or guardian contact',30],
            ['academic','Academic history','Qualifying examinations and entrance test',40],
            ['preferences','Programme preferences','Rank programme choices',50],
        ];
        $sectionIds = [];
        foreach ($sections as [$key,$title,$description,$sort]) {
            $sectionIds[$key] = $this->insert('admission_form_sections', ['admission_cycle_id'=>$cycleId,'section_key'=>$key,'title'=>$title,'description'=>$description,'sort_order'=>$sort,'status'=>'active','created_at'=>$this->now,'updated_at'=>$this->now]);
        }
        $fields = [
            ['personal','date_of_birth','Date of birth','date','applicant_profiles.date_of_birth',1,null,10],
            ['personal','gender','Gender','select','applicant_profiles.gender',1,['female'=>'Female','male'=>'Male','other'=>'Other','prefer_not_to_say'=>'Prefer not to say'],20],
            ['personal','category','Category','select','applicant_profiles.category',1,null,30],
            ['personal','nationality','Nationality','text','applicant_profiles.nationality',1,null,40],
            ['personal','blood_group','Blood group','select','applicant_profiles.blood_group',0,['A+'=>'A+','A-'=>'A-','B+'=>'B+','B-'=>'B-','AB+'=>'AB+','AB-'=>'AB-','O+'=>'O+','O-'=>'O-'],50],
            ['personal','mother_tongue','Mother tongue','text','applicant_profiles.mother_tongue',0,null,60],
            ['personal','religion','Religion','text','applicant_profiles.religion',0,null,70],
            ['address','address_line1','Address line 1','text','applicant_addresses.address_line1',1,null,10],
            ['address','address_line2','Address line 2','text','applicant_addresses.address_line2',0,null,20],
            ['address','city','City / town','text','applicant_addresses.city',1,null,30],
            ['address','district','District','text','applicant_addresses.district',0,null,40],
            ['address','state','State','text','applicant_addresses.state',1,null,50],
            ['address','postal_code','PIN code','text','applicant_addresses.postal_code',1,null,60],
            ['address','country','Country','text','applicant_addresses.country',1,null,70],
            ['guardian','guardian_name','Full name','text','guardians.name',1,null,10],
            ['guardian','relationship','Relationship','text','guardians.relationship',1,null,20],
            ['guardian','occupation','Occupation','text','guardians.occupation',0,null,30],
            ['guardian','annual_income','Annual family income','number','guardians.annual_income',0,null,40],
            ['guardian','guardian_mobile','Mobile number','tel','guardians.mobile',1,null,50],
            ['guardian','guardian_email','Email','email','guardians.email',0,null,60],
            ['academic','class_10_percentage','Class 10 percentage','number','education_records.class_10.percentage',1,null,10],
            ['academic','class_12_percentage','Class 12 percentage','number','education_records.class_12.percentage',1,null,20],
            ['academic','class_12_subjects','Class 12 subjects','text','education_records.class_12.subjects',1,null,30],
            ['academic','entrance_exam','Entrance examination','text','entrance_exams.exam_name',0,null,40],
            ['preferences','program_preferences','Programme preferences','program_preferences','application_preferences.cycle_program_ids',1,null,10],
        ];
        foreach ($fields as [$section,$key,$label,$type,$binding,$required,$options,$sort]) {
            $this->insert('admission_form_fields', [
                'admission_cycle_id'=>$cycleId,'section_id'=>$sectionIds[$section],'field_key'=>$key,'label'=>$label,'field_type'=>$type,
                'canonical_binding'=>$binding,'help_text'=>null,'placeholder'=>null,'default_value'=>null,
                'options_json'=>$options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                'validation_rules'=>json_encode(['required'=>(bool)$required], JSON_UNESCAPED_UNICODE),'conditional_rules'=>null,
                'is_required'=>$required,'is_searchable'=>in_array($key,['category','city','district','state'],true)?1:0,
                'sort_order'=>$sort,'status'=>'active','created_at'=>$this->now,'updated_at'=>$this->now,
            ]);
        }

        $this->db->prepare('UPDATE admission_cycles SET configuration_version = 1 WHERE id = :id')->execute(['id'=>$cycleId]);
        $queries = [
            'cycle'=>'SELECT * FROM admission_cycles WHERE id = :id',
            'programs'=>'SELECT cp.*, p.code AS program_code, p.name AS program_name FROM cycle_programs cp JOIN programs p ON p.id = cp.program_id WHERE cp.admission_cycle_id = :id ORDER BY p.sort_order, p.name',
            'eligibility_rules'=>'SELECT er.* FROM eligibility_rules er JOIN cycle_programs cp ON cp.id = er.cycle_program_id WHERE cp.admission_cycle_id = :id ORDER BY er.sort_order, er.id',
            'documents'=>'SELECT cdr.*, dt.code AS document_code, dt.name AS document_name, dt.allowed_mimes, dt.max_size_mb FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id = cdr.document_type_id WHERE cdr.admission_cycle_id = :id ORDER BY cdr.sort_order, cdr.id',
            'seats'=>'SELECT sm.* FROM seat_matrix sm JOIN cycle_programs cp ON cp.id = sm.cycle_program_id WHERE cp.admission_cycle_id = :id ORDER BY sm.category, sm.quota',
            'form_sections'=>'SELECT * FROM admission_form_sections WHERE admission_cycle_id = :id ORDER BY sort_order, id',
            'form_fields'=>'SELECT * FROM admission_form_fields WHERE admission_cycle_id = :id ORDER BY section_id, sort_order, id',
            'fees'=>'SELECT afr.* FROM admission_fee_rules afr JOIN cycle_programs cp ON cp.id = afr.cycle_program_id WHERE cp.admission_cycle_id = :id ORDER BY afr.fee_type, afr.id',
        ];
        $snapshot = [];
        foreach ($queries as $key=>$sql) {
            $statement=$this->db->prepare($sql); $statement->execute(['id'=>$cycleId]);
            $snapshot[$key] = $key === 'cycle' ? $statement->fetch(PDO::FETCH_ASSOC) : $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return $this->insert('admission_configuration_versions', ['admission_cycle_id'=>$cycleId,'version_no'=>1,'snapshot_json'=>$json,'snapshot_hash'=>hash('sha256',$json),'status'=>'seeded_baseline','created_by'=>$adminId,'created_at'=>$this->now]);
    }

    private function cms(int $adminId, int $departmentId): void
    {
        $pages = [
            ['About Netaji','about','A college shaped around science, service and student growth.','Netaji College of Pharmacy is an editable demonstration identity for a modern pharmacy institution in Kolkata. The deploying college can replace every name, address, policy and regulatory detail from the administration area.\n\nOur academic environment brings together foundational science, formulation, quality, clinical understanding and ethical practice. Students learn in focused classrooms and well-planned laboratories, supported by mentors who value curiosity and responsibility.'],
            ['Privacy notice','privacy','How applicant and student information is collected, used and protected.','The institution must publish its final, legally reviewed privacy notice before accepting real applications. The platform records consent, limits access by role, encrypts configured identity values, protects uploaded files and maintains an audit trail. Applicants may contact the configured privacy office to exercise applicable data rights.'],
            ['Terms of use','terms','Rules for responsible use of the admissions portal.','Applicants are responsible for providing accurate information, protecting their credentials and submitting only authentic documents. Submission does not guarantee admission. Eligibility, verification, selection and fee requirements remain subject to the institution’s published rules.'],
        ];
        foreach ($pages as [$title,$slug,$excerpt,$body]) {
            $this->insert('pages', ['title' => $title, 'slug' => $slug, 'eyebrow' => 'Netaji College of Pharmacy', 'excerpt' => $excerpt, 'body' => $body, 'template' => 'standard', 'hero_image' => null, 'meta_title' => $title . ' | Netaji College of Pharmacy', 'meta_description' => $excerpt, 'status' => 'published', 'published_at' => $this->now, 'created_by' => $adminId, 'updated_by' => $adminId, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        foreach ([
            ['Admissions','Applications for 2027–28 are configured as a draft cycle','Review eligibility, the document checklist and key dates before the cycle is opened by the college.','admissions-cycle-2027',1],
            ['Academic','B.Pharm programme profile published','Explore the four-year learning journey, practical experience and career pathways.','bpharm-profile',0],
            ['Student services','Applicant helpdesk available in the portal','Registered applicants can create and track support tickets from one place.','applicant-helpdesk',0],
        ] as [$category,$title,$excerpt,$slug,$pinned]) {
            $this->insert('notices', ['title' => $title, 'slug' => $slug, 'category' => $category, 'excerpt' => $excerpt, 'body' => $excerpt, 'attachment_path' => null, 'is_pinned' => $pinned, 'audience' => 'public', 'status' => 'published', 'published_at' => $this->now, 'expires_at' => null, 'created_by' => $adminId, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        foreach ([
            ['Formulation & Pharmaceutics Lab','lab','Purpose-designed benches and instruments for dosage-form design and evaluation.','assets/images/research-lab.jpg'],
            ['Pharmaceutical Chemistry Lab','flask','A supervised environment for analysis, synthesis and quality-focused practical learning.','assets/images/pharmacy-hero.jpg'],
            ['Library & Learning Commons','book','Quiet study, reference support and access to configured digital learning resources.','assets/images/campus-life.jpg'],
            ['Student Support Centre','heart','Admissions, mentoring and support services brought together around the student journey.','assets/images/campus-life.jpg'],
        ] as $i => [$name,$icon,$summary,$image]) {
            $this->insert('facilities', ['name' => $name, 'slug' => strtolower(str_replace([' & ',' '], ['-','-'], $name)), 'icon' => $icon, 'summary' => $summary, 'description' => $summary, 'image_path' => $image, 'status' => 'published', 'sort_order' => $i + 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        foreach ([
            ['Dr. Ananya Sen','Professor & Principal','M.Pharm, Ph.D.','Pharmaceutics and drug delivery'],
            ['Dr. Arindam Basu','Associate Professor','M.Pharm, Ph.D.','Pharmaceutical chemistry'],
            ['Dr. Riya Mukherjee','Assistant Professor','Pharm.D, Ph.D.','Pharmacy practice'],
            ['Mr. Sayan Ghosh','Assistant Professor','M.Pharm','Pharmacology'],
        ] as $i => [$name,$designation,$qualifications,$specialisation]) {
            $this->insert('faculty', ['department_id' => $departmentId, 'name' => $name, 'designation' => $designation, 'qualifications' => $qualifications, 'specialisation' => $specialisation, 'bio' => 'Demonstration faculty profile; replace with verified institutional information before launch.', 'email' => null, 'photo_path' => null, 'status' => 'active', 'sort_order' => $i + 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        foreach ([
            ['admissions','Can I save my application and return later?','Yes. Each section saves separately while the application remains in draft or correction-required status.'],
            ['admissions','Can I choose more than one programme?','The platform supports ranked programme preferences within one application and one admission cycle.'],
            ['documents','Which file types are accepted?','PDF, JPG and PNG files up to the configured size are accepted. Upload clear, readable files.'],
            ['payments','How is a manual payment verified?','Submit the bank or UPI reference and upload proof. The Accounts team reviews it and issues a receipt after verification.'],
            ['privacy','Is Aadhaar always required?','No universal assumption is made. The authorised administrator configures if and when it is collected, records purpose and consent, and receives a compliance warning.'],
        ] as $i => [$category,$question,$answer]) {
            $this->insert('faqs', ['category' => $category, 'question' => $question, 'answer' => $answer, 'status' => 'published', 'sort_order' => $i + 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        foreach ([
            ['Learning through practice','Students collaborating in the pharmaceutical laboratory.','assets/images/pharmacy-hero.jpg','Laboratories'],
            ['Research-ready spaces','A modern environment for careful investigation.','assets/images/research-lab.jpg','Laboratories'],
            ['A connected campus','Student life beyond the classroom.','assets/images/campus-life.jpg','Campus'],
        ] as $i => [$title,$caption,$image,$album]) {
            $this->insert('gallery_items', ['title' => $title, 'caption' => $caption, 'image_path' => $image, 'album' => $album, 'status' => 'published', 'sort_order' => $i + 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
    }

    private function demoOperations(int $cycleId, int $cycleProgramId, int $configurationVersionId): void
    {
        $officerId = $this->user(['first_name' => 'Meera', 'last_name' => 'Dutta', 'email' => 'admissions@demo.test', 'mobile' => '9000000002', 'password' => 'DemoOfficer#2027', 'verified' => true], 'admission-officer');
        $accountsId = $this->user(['first_name' => 'Rajiv', 'last_name' => 'Paul', 'email' => 'accounts@demo.test', 'mobile' => '9000000003', 'password' => 'DemoAccounts#2027', 'verified' => true], 'accounts-officer');
        $names = [
            ['Ishita','Roy','ishita@demo.test','submitted','General','female','Kolkata'],
            ['Ayan','Chatterjee','ayan@demo.test','under_review','OBC-B','male','Howrah'],
            ['Nandini','Das','nandini@demo.test','correction_required','SC','female','North 24 Parganas'],
            ['Farhan','Ali','farhan@demo.test','selected','General','male','Murshidabad'],
            ['Diya','Sen','diya@demo.test','admitted','EWS','female','Kolkata'],
        ];
        foreach ($names as $index => [$first,$last,$email,$status,$category,$gender,$district]) {
            $userId = $this->user(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'mobile' => '9000001' . str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'password' => 'StudentDemo#2027', 'verified' => true], 'applicant');
            $this->insert('applicant_profiles', ['user_id' => $userId, 'date_of_birth' => '2008-0' . ($index + 1) . '-15', 'gender' => $gender, 'category' => $category, 'nationality' => 'Indian', 'blood_group' => 'B+', 'religion' => null, 'mother_tongue' => 'Bengali', 'disability_status' => null, 'disability_percentage' => null, 'government_id_type' => null, 'government_id_encrypted' => null, 'government_id_last4' => null, 'photo_path' => null, 'signature_path' => null, 'profile_completion' => 90, 'created_at' => $this->now, 'updated_at' => $this->now]);
            $appId = $this->insert('applications', ['application_number' => 'NCP-APP-2027-' . str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT), 'user_id' => $userId, 'admission_cycle_id' => $cycleId, 'status' => $status, 'current_step' => 7, 'completion_percentage' => 100, 'eligibility_status' => 'eligible', 'eligibility_flags' => null, 'configuration_version_id' => $configurationVersionId, 'selected_cycle_program_id' => in_array($status, ['selected','admitted'], true) ? $cycleProgramId : null, 'assigned_to' => $officerId, 'assigned_at' => $this->now, 'submitted_at' => date('Y-m-d H:i:s', strtotime('-' . (8 - $index) . ' days')), 'locked_at' => $status === 'correction_required' ? null : $this->now, 'admitted_at' => $status === 'admitted' ? $this->now : null, 'withdrawal_reason' => null, 'created_at' => $this->now, 'updated_at' => $this->now, 'deleted_at' => null]);
            $this->insert('applicant_addresses', ['application_id' => $appId, 'address_line1' => ($index + 11) . ' College Road', 'address_line2' => null, 'city' => $district, 'district' => $district, 'state' => 'West Bengal', 'postal_code' => '700' . str_pad((string) ($index + 101), 3, '0', STR_PAD_LEFT), 'country' => 'India', 'same_as_correspondence' => 1, 'correspondence_address' => null, 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->insert('guardians', ['application_id' => $appId, 'name' => 'Sample Guardian', 'relationship' => 'Parent', 'occupation' => 'Service', 'annual_income' => 480000, 'mobile' => '9000099999', 'email' => null, 'address' => null, 'created_at' => $this->now, 'updated_at' => $this->now]);
            foreach ([['class_10','WBBSE',2024,82],['class_12','WBCHSE',2026,78]] as [$level,$board,$year,$percentage]) $this->insert('education_records', ['application_id' => $appId, 'level' => $level, 'board' => $board, 'institution' => 'Demonstration Higher Secondary School', 'passing_year' => $year, 'roll_number' => 'DEMO' . $index, 'registration_number' => null, 'total_marks' => 500, 'obtained_marks' => $percentage * 5, 'percentage' => $percentage, 'grade_cgpa' => null, 'subjects' => $level === 'class_12' ? 'English, Physics, Chemistry, Biology' : 'General curriculum', 'result_status' => 'passed', 'created_at' => $this->now, 'updated_at' => $this->now]);
            $this->insert('application_preferences', ['application_id' => $appId, 'cycle_program_id' => $cycleProgramId, 'preference_order' => 1, 'allocation_status' => $status === 'admitted' ? 'accepted' : 'pending', 'created_at' => $this->now]);
            $this->insert('application_status_history', ['application_id' => $appId, 'from_status' => 'draft', 'to_status' => 'submitted', 'remarks' => 'Demonstration application submitted', 'changed_by' => $userId, 'created_at' => $this->now]);
            if ($status !== 'submitted') $this->insert('application_status_history', ['application_id' => $appId, 'from_status' => 'submitted', 'to_status' => $status, 'remarks' => $status === 'correction_required' ? 'Please upload a clearer marksheet.' : 'Demonstration workflow update', 'changed_by' => $officerId, 'created_at' => $this->now]);
            $snapshot = json_encode(['application_id'=>$appId,'user_id'=>$userId,'cycle_id'=>$cycleId,'category'=>$category,'status'=>$status,'cycle_program_ids'=>[$cycleProgramId]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->insert('application_submission_snapshots', ['application_id'=>$appId,'configuration_version_id'=>$configurationVersionId,'snapshot_json'=>$snapshot,'snapshot_hash'=>hash('sha256',$snapshot),'created_at'=>$this->now]);
            $this->insert('application_submission_snapshot_revisions', ['application_id'=>$appId,'configuration_version_id'=>$configurationVersionId,'revision_no'=>1,'event_type'=>'initial_submission','snapshot_json'=>$snapshot,'snapshot_hash'=>hash('sha256',$snapshot),'created_by'=>$userId,'created_at'=>$this->now]);
            $this->insert('application_fee_assessments', ['application_id'=>$appId,'cycle_program_id'=>$cycleProgramId,'fee_rule_id'=>null,'configuration_version_id'=>$configurationVersionId,'fee_type'=>'application_fee','category_code'=>$category,'base_amount'=>1000,'late_amount'=>0,'total_amount'=>1000,'currency'=>'INR','due_at'=>null,'calculation_json'=>json_encode(['strategy'=>'first_preference']),'status'=>'due','created_at'=>$this->now,'updated_at'=>$this->now]);
            if (in_array($status, ['selected','admitted'], true)) {
                $seatStatement = $this->db->prepare('SELECT id FROM seat_matrix WHERE cycle_program_id = :program AND category = :category ORDER BY id LIMIT 1');
                $seatStatement->execute(['program'=>$cycleProgramId,'category'=>$category]);
                $seatId = (int) $seatStatement->fetchColumn();
                if ($seatId) {
                    $this->insert('seat_allocations', ['application_id'=>$appId,'cycle_program_id'=>$cycleProgramId,'seat_matrix_id'=>$seatId,'category'=>$category,'quota'=>'state','status'=>$status==='admitted'?'confirmed':'reserved','is_active'=>1,'allocated_by'=>$officerId,'allocated_at'=>$this->now,'confirmed_at'=>$status==='admitted'?$this->now:null,'released_by'=>null,'released_at'=>null,'release_reason'=>null,'created_at'=>$this->now,'updated_at'=>$this->now]);
                    $this->db->prepare('UPDATE seat_matrix SET filled_seats = filled_seats + 1, updated_at = :updated WHERE id = :id')->execute(['updated'=>$this->now,'id'=>$seatId]);
                }
            }
            $this->insert('notifications', ['user_id' => $userId, 'type' => 'status', 'title' => 'Application status updated', 'message' => 'Your demonstration application is now ' . str_replace('_', ' ', $status) . '.', 'action_url' => '/student/dashboard', 'read_at' => null, 'created_at' => $this->now]);
            if ($status === 'admitted') $this->insert('student_enrollments', ['application_id' => $appId, 'user_id' => $userId, 'cycle_program_id' => $cycleProgramId, 'enrollment_number' => 'NCP-2027-' . str_pad((string) $appId, 5, '0', STR_PAD_LEFT), 'university_roll_number' => null, 'status' => 'active', 'enrolled_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
        $student = $this->id('users', 'email', 'ishita@demo.test');
        $ticketId = $this->insert('support_tickets', ['ticket_number' => 'TKT-DEMO-01', 'user_id' => $student, 'application_id' => null, 'assigned_to' => $officerId, 'subject' => 'Which domicile certificate is accepted?', 'category' => 'documents', 'priority' => 'normal', 'status' => 'open', 'closed_at' => null, 'created_at' => $this->now, 'updated_at' => $this->now]);
        $this->insert('ticket_messages', ['ticket_id' => $ticketId, 'user_id' => $student, 'message' => 'Please confirm which domicile certificate format I should upload.', 'attachment_path' => null, 'is_staff_reply' => 0, 'created_at' => $this->now]);
    }

    private function user(array $data, string $role): int
    {
        $id = $this->insert('users', ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => mb_strtolower($data['email']), 'mobile' => $data['mobile'] ?? null, 'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT), 'status' => 'active', 'preferred_locale' => 'en', 'avatar_path' => null, 'email_verified_at' => !empty($data['verified']) ? $this->now : null, 'email_verification_token' => null, 'email_verification_expires_at' => null, 'last_login_at' => null, 'last_login_ip' => null, 'password_changed_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now, 'deleted_at' => null]);
        $this->insert('user_roles', ['user_id' => $id, 'role_id' => $this->id('roles', 'slug', $role), 'assigned_by' => null, 'assigned_at' => $this->now]);
        return $id;
    }

    private function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (:' . implode(',:', $columns) . ')';
        $statement = $this->db->prepare($sql);
        $statement->execute($data);
        return (int) $this->db->lastInsertId();
    }

    private function id(string $table, string $column, string $value): int
    {
        $statement = $this->db->prepare('SELECT id FROM `' . $table . '` WHERE `' . $column . '` = :value LIMIT 1');
        $statement->execute(['value' => $value]);
        return (int) $statement->fetchColumn();
    }
}
