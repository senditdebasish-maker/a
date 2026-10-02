<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Encryption;
use App\Core\Flash;
use App\Core\Translator;
use App\Core\Validator;
use App\Services\AdmissionCycleService;
use App\Services\AdmissionFeeService;
use App\Services\ApplicationNumberService;
use App\Services\ApplicationSnapshotService;
use App\Services\AuditService;
use App\Services\CorrectionService;
use App\Services\EligibilityService;
use App\Services\ReapplicationService;
use App\Services\UploadService;
use RuntimeException;

final class ApplicantController extends Controller
{
    public function dashboard(): void
    {
        $db = Database::get();
        $application = $this->applicationRecord();
        $applications = $db->all("SELECT a.*,ac.name AS cycle_name,ac.slug AS cycle_slug FROM applications a JOIN admission_cycles ac ON ac.id=a.admission_cycle_id WHERE a.user_id=:user ORDER BY a.created_at DESC",['user'=>Auth::id()]);
        $documents = [];
        $timeline = [];
        $notifications = $db->all('SELECT * FROM notifications WHERE user_id = :user ORDER BY created_at DESC LIMIT 5', ['user' => Auth::id()]);
        $notices = $db->all("SELECT * FROM notices WHERE status = 'published' AND audience IN ('public','applicants','students') AND (expires_at IS NULL OR expires_at >= :today) ORDER BY is_pinned DESC, published_at DESC LIMIT 4", ['today' => date('Y-m-d')]);
        $notices = array_map(fn (array $notice): array => $this->localizeContent('notice', $notice), $notices);
        if ($application) {
            $documents = $db->all('SELECT ad.*, dt.name AS document_name FROM application_documents ad JOIN document_types dt ON dt.id = ad.document_type_id WHERE ad.application_id = :id ORDER BY dt.sort_order', ['id' => $application['id']]);
            $timeline = $db->all('SELECT ash.*, CONCAT(u.first_name, " ", u.last_name) AS changed_by_name FROM application_status_history ash LEFT JOIN users u ON u.id = ash.changed_by WHERE ash.application_id = :id ORDER BY ash.created_at DESC', ['id' => $application['id']]);
        }
        $profile = $db->fetch('SELECT * FROM applicant_profiles WHERE user_id = :user', ['user' => Auth::id()]);
        $canReapply=$application?$this->canReapply($application):false;
        $this->view('student/dashboard', compact('application', 'applications', 'documents', 'timeline', 'notifications', 'notices', 'profile', 'canReapply') + ['title' => 'My dashboard'], 'student');
    }

    public function startApplication(string $slug): never
    {
        $service=new AdmissionCycleService();
        $cycle=$service->publicCycle($slug);
        if (!$cycle||!$service->acceptsApplications($cycle)) {
            Flash::set('warning','This admission cycle is not currently accepting applications.');
            $this->redirect('admissions');
        }
        $programSlug=trim((string)($_GET['program']??$_SESSION['intended_program_slug']??''));
        $db=Database::get();
        $applicationId=$db->transaction(function (Database $db) use ($cycle,$programSlug): int {
            $existing=$db->fetch('SELECT * FROM applications WHERE user_id=:user AND admission_cycle_id=:cycle ORDER BY attempt_no DESC,id DESC LIMIT 1 FOR UPDATE',['user'=>Auth::id(),'cycle'=>$cycle['id']]);
            if ($existing) $id=(int)$existing['id'];
            else {
                $version=$db->fetch("SELECT id FROM admission_configuration_versions WHERE admission_cycle_id=:cycle AND status='published' ORDER BY version_no DESC LIMIT 1",['cycle'=>$cycle['id']]);
                if (!$version) throw new RuntimeException('Published admission configuration is unavailable. Contact Admissions.');
                $id=$db->insert('applications',['user_id'=>Auth::id(),'admission_cycle_id'=>$cycle['id'],'configuration_version_id'=>$version['id'],'status'=>'draft','current_step'=>1,'completion_percentage'=>10,'eligibility_status'=>'not_evaluated','status_version'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                $db->insert('application_status_history',['application_id'=>$id,'from_status'=>null,'to_status'=>'draft','remarks'=>'Application created for published cycle','changed_by'=>Auth::id(),'created_at'=>date('Y-m-d H:i:s')]);
            }
            if ($programSlug!=='') {
                $program=$db->fetch("SELECT cp.id FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle AND cp.status='active' AND p.status='active' AND p.slug=:slug",['cycle'=>$cycle['id'],'slug'=>$programSlug]);
                if (!$program) throw new RuntimeException('The selected programme is not available in this admission cycle.');
                if (($existing['status']??'draft')==='draft'&&!(int)$db->scalar('SELECT COUNT(*) FROM application_preferences WHERE application_id=:application',['application'=>$id])) {
                    $db->insert('application_preferences',['application_id'=>$id,'cycle_program_id'=>$program['id'],'preference_order'=>1,'allocation_status'=>'pending','created_at'=>date('Y-m-d H:i:s')]);
                }
            }
            return $id;
        });
        $_SESSION['active_application_id']=$applicationId;
        unset($_SESSION['intended_cycle_slug'],$_SESSION['intended_program_slug']);
        AuditService::log('application_started','application',$applicationId,[],['cycle_id'=>$cycle['id']]);
        $this->redirect('student/application');
    }

    public function reapply(string $id): never
    {
        try {
            $application=(new ReapplicationService())->createAttempt((int)$id,(int)Auth::id());
            $_SESSION['active_application_id']=(int)$application['id'];
            Flash::set('success','A new application attempt was created with your existing details and documents. Review each step, then submit it again.');
            $this->redirect('student/application#personal');
        } catch (RuntimeException $exception) {
            Flash::set('warning',$exception->getMessage());
            $this->redirect('student/dashboard');
        }
    }

    public function application(): void
    {
        $db = Database::get();
        $application = $this->applicationRecord();
        if (!$application) {
            Flash::set('warning','Choose a published admission cycle before starting an application.');
            $this->redirect('admissions');
        }
        $_SESSION['active_application_id']=(int)$application['id'];
        $profile = $db->fetch('SELECT * FROM applicant_profiles WHERE user_id = :id', ['id' => Auth::id()]);
        $address = $db->fetch('SELECT * FROM applicant_addresses WHERE application_id = :id LIMIT 1', ['id' => $application['id']]);
        $guardian = $db->fetch('SELECT * FROM guardians WHERE application_id = :id LIMIT 1', ['id' => $application['id']]);
        $education = $db->all('SELECT * FROM education_records WHERE application_id = :id ORDER BY level', ['id' => $application['id']]);
        $exam = $db->fetch('SELECT * FROM entrance_exams WHERE application_id = :id LIMIT 1', ['id' => $application['id']]);
        $programs = $db->all("SELECT p.*, cp.id AS cycle_program_id, cp.seat_capacity, cp.application_fee FROM cycle_programs cp JOIN programs p ON p.id = cp.program_id WHERE cp.admission_cycle_id = :cycle AND p.status = 'active' ORDER BY p.sort_order", ['cycle' => $application['admission_cycle_id']]);
        $preferences = $db->all('SELECT * FROM application_preferences WHERE application_id = :id ORDER BY preference_order', ['id' => $application['id']]);
        $requirements = $db->all("SELECT cdr.*,dt.name,dt.code,dt.description,dt.allowed_mimes,dt.max_size_mb FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id
            WHERE cdr.admission_cycle_id=:cycle AND dt.status='active'
            AND (cdr.program_id IS NULL OR EXISTS (SELECT 1 FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id WHERE pref.application_id=:application AND cp.program_id=cdr.program_id))
            AND (cdr.category IS NULL OR cdr.category=:category) ORDER BY cdr.sort_order,cdr.id", ['cycle'=>$application['admission_cycle_id'],'application'=>$application['id'],'category'=>$profile['category']??'']);
        $documents = $db->all('SELECT * FROM application_documents WHERE application_id = :id', ['id' => $application['id']]);
        $formSections=$db->all("SELECT * FROM admission_form_sections WHERE admission_cycle_id=:cycle AND status='active' ORDER BY sort_order,id",['cycle'=>$application['admission_cycle_id']]);
        $formFields=$db->all("SELECT aff.*,afs.section_key AS configured_section_key FROM admission_form_fields aff JOIN admission_form_sections afs ON afs.id=aff.section_id WHERE aff.admission_cycle_id=:cycle AND aff.status='active' ORDER BY aff.section_id,aff.sort_order,aff.id",['cycle'=>$application['admission_cycle_id']]);
        $customResponses=[]; foreach($db->all('SELECT * FROM application_field_responses WHERE application_id=:application',['application'=>$application['id']]) as $response) $customResponses[(int)$response['form_field_id']]=$response;
        $conditionContext=array_merge($application,$profile?:[]); foreach($formFields as $candidate) { $stored=$customResponses[(int)$candidate['id']]??null; if($stored)$conditionContext[$candidate['field_key']]=$stored['value_json']?json_decode($stored['value_json'],true):$stored['value_text']; }
        foreach($formFields as &$configuredField)$configuredField['is_visible']=$this->conditionMatches($configuredField['conditional_rules']??null,$conditionContext);unset($configuredField);
        $categories=$db->all("SELECT * FROM admission_categories WHERE status='active' ORDER BY sort_order,name");
        $correction=(new CorrectionService())->openForApplication((int)$application['id']);
        if($application['status']==='correction_required') { $fieldTargets=[];$sectionTargets=[];$allCustom=false;foreach($correction['items']??[] as $item){if($item['status']!=='open')continue;if($item['target_type']==='field')$fieldTargets[]=$item['target_key'];if($item['target_type']==='section'){if($item['target_key']==='custom')$allCustom=true;else $sectionTargets[]=$item['target_key'];}}foreach($formFields as &$configuredField){$configuredField['is_editable']=$allCustom||in_array($configuredField['field_key'],$fieldTargets,true)||in_array($configuredField['configured_section_key'],$sectionTargets,true);if($configuredField['is_editable'])$configuredField['is_visible']=true;}unset($configuredField); }
        $aadhaarPolicy = $this->aadhaarPolicy($application);
        $identityCollectionOpen = $this->identityCollectionOpen($aadhaarPolicy, (string) $application['status']);
        $canReapply=$this->canReapply($application);
        $this->view('student/application', compact('application', 'profile', 'address', 'guardian', 'education', 'exam', 'programs', 'preferences', 'requirements', 'documents', 'formSections', 'formFields', 'customResponses', 'categories', 'correction', 'aadhaarPolicy', 'identityCollectionOpen', 'canReapply') + ['title' => 'My application'], 'student');
    }

    public function saveApplication(): never
    {
        $application = $this->editableApplication();
        $db = Database::get();
        $section = (string) $this->input('section', 'personal');
        $sectionOrder=['personal','address','guardian','academic','preferences','custom','documents','review'];
        $hasCustomFields=(int)$db->scalar("SELECT COUNT(*) FROM admission_form_fields WHERE admission_cycle_id=:cycle AND status='active' AND (canonical_binding IS NULL OR field_type='file')",['cycle'=>$application['admission_cycle_id']])>0;
        $nextBySection=['personal'=>'address','address'=>'guardian','guardian'=>'academic','academic'=>'preferences','preferences'=>$hasCustomFields?'custom':'documents','custom'=>'documents'];
        $requestedNext=trim((string)$this->input('continue_to',$section));
        $redirectSection=($requestedNext===$section||$requestedNext===($nextBySection[$section]??null))?$requestedNext:$section;
        $saved=false;
        if ($application['status']==='correction_required') {
            $correction=(new CorrectionService())->openForApplication((int)$application['id']);
            $allowed=false;
            foreach ($correction['items']??[] as $item) {
                if ($item['status']==='open'&&$item['target_type']==='section'&&$item['target_key']===$section) $allowed=true;
                if ($section==='custom'&&$item['status']==='open'&&in_array($item['target_type'],['field','section'],true)) $allowed=true;
            }
            if (!$allowed) { Flash::set('warning','This section was not included in the active correction request.'); $this->redirect('student/application#'.$section); }
        }
        try {
            $db->transaction(function (Database $db) use ($section, $application, $redirectSection, $sectionOrder): void {
                switch ($section) {
                    case 'personal':
                        $this->savePersonal($db);
                        break;
                    case 'address':
                        $this->saveAddress($db, (int) $application['id']);
                        break;
                    case 'guardian':
                        $this->saveGuardian($db, (int) $application['id']);
                        break;
                    case 'academic':
                        $this->saveAcademic($db, (int) $application['id']);
                        break;
                    case 'preferences':
                        $this->savePreferences($db, (int) $application['id'], (int) $application['admission_cycle_id']);
                        break;
                    case 'custom':
                        $this->saveCustomFields($db,(int)$application['id'],(int)$application['admission_cycle_id'],(int)($application['configuration_version_id']??0));
                        break;
                    default:
                        throw new RuntimeException('Unknown form section.');
                }
                $completion = $this->completionScore((int) $application['id']);
                $currentStep=array_search($redirectSection,$sectionOrder,true);
                $db->update('applications', ['completion_percentage' => $completion, 'current_step'=>$currentStep===false?1:$currentStep+1, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $application['id']]);
            });
            if ($application['status']==='correction_required') {
                $corrections=new CorrectionService();
                $corrections->markResponded((int)$application['id'],(int)Auth::id(),'section',$section);
                if ($section==='custom') foreach(Database::get()->all('SELECT aff.id,aff.field_key,afs.section_key FROM admission_form_fields aff JOIN admission_form_sections afs ON afs.id=aff.section_id WHERE aff.admission_cycle_id=:cycle AND aff.id IN ('.implode(',',array_map('intval',array_keys((array)($_POST['custom']??[])))?:[0]).')',['cycle'=>$application['admission_cycle_id']]) as $field) {
                    $corrections->markResponded((int)$application['id'],(int)Auth::id(),'section',(string)$field['section_key']);
                    $corrections->markResponded((int)$application['id'],(int)Auth::id(),'field',(string)$field['field_key']);
                    $corrections->markResponded((int)$application['id'],(int)Auth::id(),'field',(string)$field['id']);
                }
            }
            AuditService::log('application_section_saved', 'application', $application['id'], [], ['section' => $section, 'continue_to'=>$redirectSection]);
            Flash::set('success', ucfirst($section) . ' details saved.'.($redirectSection!==$section?' Continue with the next step.':''));
            $saved=true;
        } catch (RuntimeException $exception) {
            Flash::withInput($_POST);
            Flash::set('warning', $exception->getMessage());
        }
        $this->redirect('student/application#' . ($saved?$redirectSection:$section));
    }

    public function submitApplication(): never
    {
        $application=$this->editableApplication();
        $db=Database::get();
        if ($application['status']==='correction_required') {
            try {
                (new CorrectionService())->submit((int)$application['id'],(int)Auth::id());
                (new EligibilityService())->evaluate((int)$application['id']);
                Flash::set('success','Corrections resubmitted successfully.');
            } catch (RuntimeException $exception) { Flash::set('warning',$exception->getMessage()); }
            $this->redirect('student/dashboard');
        }
        $cycle=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id',['id'=>$application['admission_cycle_id']]);
        if (!$cycle||!(new AdmissionCycleService())->acceptsApplications($cycle)) {
            Flash::set('warning','This admission cycle is no longer accepting submissions.');
            $this->redirect('student/application#review');
        }
        $profile=$db->fetch('SELECT * FROM applicant_profiles WHERE user_id=:id',['id'=>Auth::id()]);
        $preferenceCount=(int)$db->scalar('SELECT COUNT(*) FROM application_preferences WHERE application_id=:id',['id'=>$application['id']]);
        $educationCount=(int)$db->scalar('SELECT COUNT(*) FROM education_records WHERE application_id=:id',['id'=>$application['id']]);
        $addressCount=(int)$db->scalar('SELECT COUNT(*) FROM applicant_addresses WHERE application_id=:id',['id'=>$application['id']]);
        $guardianCount=(int)$db->scalar('SELECT COUNT(*) FROM guardians WHERE application_id=:id',['id'=>$application['id']]);
        $missingDocuments=(int)$db->scalar("SELECT COUNT(*) FROM cycle_document_requirements cdr WHERE cdr.admission_cycle_id=:cycle AND cdr.is_required=1 AND cdr.stage='application'
            AND (cdr.program_id IS NULL OR EXISTS (SELECT 1 FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id WHERE pref.application_id=:application AND cp.program_id=cdr.program_id))
            AND (cdr.category IS NULL OR cdr.category=:category)
            AND NOT EXISTS (SELECT 1 FROM application_documents ad WHERE ad.application_id=:application2 AND ad.document_type_id=cdr.document_type_id)",['cycle'=>$application['admission_cycle_id'],'application'=>$application['id'],'category'=>$profile['category']??'','application2'=>$application['id']]);
        $requiredCustomMissing=0;$responseValues=[];
        foreach($db->all('SELECT afr.*,aff.field_key FROM application_field_responses afr JOIN admission_form_fields aff ON aff.id=afr.form_field_id WHERE afr.application_id=:application',['application'=>$application['id']]) as $response){$value=$response['value_json']!==null?json_decode((string)$response['value_json'],true):$response['value_text'];$responseValues[(int)$response['form_field_id']]=$value;$responseValues[$response['field_key']]=$value;}
        $conditionContext=array_merge($application,$profile?:[],$responseValues);
        foreach($db->all("SELECT * FROM admission_form_fields WHERE admission_cycle_id=:cycle AND status='active' AND is_required=1 AND canonical_binding IS NULL",['cycle'=>$application['admission_cycle_id']]) as $requiredField){$value=$responseValues[(int)$requiredField['id']]??null;if($this->conditionMatches($requiredField['conditional_rules']??null,$conditionContext)&&($value===null||$value===''||$value===[]))$requiredCustomMissing++;}
        $errors=[];
        if (!$profile||!$profile['date_of_birth']||!$profile['gender']||!$profile['category']) $errors[]='Complete your personal details.';
        elseif (!(int)$db->scalar("SELECT COUNT(*) FROM admission_categories WHERE code=:category AND status='active'",['category'=>$profile['category']])) $errors[]='Select a currently configured applicant category.';
        if ($addressCount<1) $errors[]='Complete your address.';
        if ($guardianCount<1) $errors[]='Complete parent or guardian details.';
        if ($preferenceCount<1) $errors[]='Select at least one programme preference.';
        if ($preferenceCount>(int)$cycle['max_program_preferences']) $errors[]='Too many programme preferences were selected.';
        if ($educationCount<2) $errors[]='Add both Class 10 and Class 12 academic records.';
        if ($missingDocuments>0) $errors[]='Upload every required application-stage document.';
        if ($requiredCustomMissing>0) $errors[]='Complete every required configured field.';
        if (!$this->input('declaration')) $errors[]='Accept the applicant declaration.';
        if ($errors) { Flash::set('warning',implode(' ',$errors)); $this->redirect('student/application#review'); }

        try {
            $db->transaction(function (Database $db) use ($application,$cycle,$profile): void {
                $locked=$db->fetch("SELECT * FROM applications WHERE id=:id AND status='draft' FOR UPDATE",['id'=>$application['id']]);
                $lockedCycle=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id FOR UPDATE',['id'=>$application['admission_cycle_id']]);
                if (!$locked||!$lockedCycle||!(new AdmissionCycleService())->acceptsApplications($lockedCycle)) throw new RuntimeException('The application or admission window changed. Refresh and try again.');
                $primary=$db->fetch("SELECT cp.*,p.code,p.name FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE pref.application_id=:application AND cp.admission_cycle_id=:cycle AND cp.status='active' AND p.status='active' ORDER BY pref.preference_order LIMIT 1 FOR UPDATE",['application'=>$locked['id'],'cycle'=>$locked['admission_cycle_id']]);
                if (!$primary) throw new RuntimeException('The first programme preference is no longer available.');
                $versionId=(int)($locked['configuration_version_id']??0);
                if (!$versionId) {
                    $versionId=(int)$db->scalar("SELECT id FROM admission_configuration_versions WHERE admission_cycle_id=:cycle AND status='published' ORDER BY version_no DESC LIMIT 1",['cycle'=>$locked['admission_cycle_id']]);
                    if (!$versionId) throw new RuntimeException('Published configuration version is unavailable.');
                }
                $number=$locked['application_number']?: (new ApplicationNumberService())->generate($db,$locked,$lockedCycle,$primary);
                $db->update('applications',['application_number'=>$number,'configuration_version_id'=>$versionId,'status'=>'submitted','completion_percentage'=>100,'submitted_at'=>date('Y-m-d H:i:s'),'locked_at'=>date('Y-m-d H:i:s'),'status_version'=>(int)$locked['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$locked['id']]);
                $db->insert('application_declarations',['application_id'=>$locked['id'],'declaration_version'=>'config-'.$versionId,'accepted'=>1,'ip_address'=>mb_substr($_SERVER['REMOTE_ADDR']??'',0,45),'accepted_at'=>date('Y-m-d H:i:s')]);
                (new AdmissionFeeService())->assess($db,(int)$locked['id'],(int)$primary['id'],'application_fee',(string)$profile['category'],$versionId);
                (new ApplicationSnapshotService())->capture($db,(int)$locked['id'],$versionId);
                $db->insert('application_status_history',['application_id'=>$locked['id'],'from_status'=>'draft','to_status'=>'submitted','remarks'=>'Application submitted by applicant','changed_by'=>Auth::id(),'created_at'=>date('Y-m-d H:i:s')]);
                $db->insert('notifications',['user_id'=>Auth::id(),'type'=>'application','title'=>'Application submitted','message'=>'Your application '.$number.' has been received for review.','read_at'=>null,'created_at'=>date('Y-m-d H:i:s')]);
            });
        } catch (RuntimeException $exception) { Flash::set('warning',$exception->getMessage()); $this->redirect('student/application#review'); }
        try { $eligibility=(new EligibilityService())->evaluate((int)$application['id']); }
        catch (\Throwable) { $eligibility=['status'=>'needs_review']; $db->update('applications',['eligibility_status'=>'needs_review','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$application['id']]); }
        AuditService::log('application_submitted','application',$application['id'],[],['eligibility_status'=>$eligibility['status']]);
        Flash::set('success','Application submitted successfully. Your application number is now available.');
        $this->redirect('student/dashboard');
    }

    public function uploadDocument(): never
    {
        $async=strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest';
        $application=$this->editableApplication();
        $typeId=(int)$this->input('document_type_id');
        $db=Database::get();
        $profile=$db->fetch('SELECT category FROM applicant_profiles WHERE user_id=:user',['user'=>Auth::id()]);
        $requirement=$db->fetch("SELECT cdr.*,dt.code,dt.allowed_mimes,dt.max_size_mb FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id
            WHERE cdr.admission_cycle_id=:cycle AND cdr.document_type_id=:type AND dt.status='active'
            AND (cdr.program_id IS NULL OR EXISTS (SELECT 1 FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id WHERE pref.application_id=:application AND cp.program_id=cdr.program_id))
            AND (cdr.category IS NULL OR cdr.category=:category) ORDER BY cdr.id LIMIT 1",['cycle'=>$application['admission_cycle_id'],'type'=>$typeId,'application'=>$application['id'],'category'=>$profile['category']??'']);
        if (!$requirement||empty($_FILES['document'])) $this->documentUploadFailure('Select a valid configured document and file.',$async);
        if ($application['status']==='correction_required') {
            $correction=(new CorrectionService())->openForApplication((int)$application['id']);
            $permitted=false;
            foreach ($correction['items']??[] as $item) if ($item['status']==='open'&&$item['target_type']==='document'&&in_array((string)$item['target_key'],[(string)$typeId,(string)$requirement['code']],true)) $permitted=true;
            if (!$permitted) $this->documentUploadFailure('This document was not included in the active correction request.',$async);
        }
        $stored=null;
        $persisted=false;
        try {
            $allowed=array_values(array_filter(array_map('trim',explode(',',(string)$requirement['allowed_mimes']))));
            $stored=(new UploadService())->store($_FILES['document'],'applications/'.$application['id'],$allowed,(int)$requirement['max_size_mb']);
            $documentId=$db->transaction(function (Database $db) use ($application,$typeId,$stored): int {
                $existing=$db->fetch('SELECT * FROM application_documents WHERE application_id=:application AND document_type_id=:type FOR UPDATE',['application'=>$application['id'],'type'=>$typeId]);
                $revision=$existing?(int)$existing['revision_no']+1:1;
                $data=$stored+['revision_no'=>$revision,'uploaded_by'=>Auth::id(),'status'=>'pending','review_remarks'=>null,'reviewed_by'=>null,'reviewed_at'=>null,'uploaded_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')];
                if ($existing) { $db->update('application_documents',$data,'id=:id',['id'=>$existing['id']]); $id=(int)$existing['id']; }
                else $id=$db->insert('application_documents',$data+['application_id'=>$application['id'],'document_type_id'=>$typeId,'created_at'=>date('Y-m-d H:i:s')]);
                $db->insert('application_document_versions',['application_document_id'=>$id,'revision_no'=>$revision,'path'=>$stored['path'],'original_name'=>$stored['original_name'],'mime_type'=>$stored['mime_type'],'size_bytes'=>$stored['size_bytes'],'checksum_sha256'=>$stored['checksum_sha256'],'status'=>'pending','review_remarks'=>null,'reviewed_by'=>null,'reviewed_at'=>null,'uploaded_by'=>Auth::id(),'created_at'=>date('Y-m-d H:i:s')]);
                return $id;
            });
            $persisted=true;
            $db->update('applications',['completion_percentage'=>$this->completionScore((int)$application['id']),'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$application['id']]);
            if ($application['status']==='correction_required') {
                (new CorrectionService())->markResponded((int)$application['id'],(int)Auth::id(),'document',(string)$requirement['code']);
                (new CorrectionService())->markResponded((int)$application['id'],(int)Auth::id(),'document',(string)$typeId);
            }
            AuditService::log('document_uploaded','application_document',$documentId,[],['revision'=>$stored?'recorded':null]);
            $message='Document saved automatically and securely. Previous revisions remain preserved.';
            if($async){$document=$db->fetch('SELECT id,original_name,status,revision_no FROM application_documents WHERE id=:id',['id'=>$documentId]);$this->json(['ok'=>true,'message'=>$message,'document'=>$document,'view_url'=>url('files/document/'.$documentId)]);}
            Flash::set('success',$message);
        } catch (RuntimeException $exception) {
            if($persisted){
                $message='The document was saved securely, but its progress status could not be refreshed. Reload the page before trying again.';
                if($async){$document=$db->fetch('SELECT id,original_name,status,revision_no FROM application_documents WHERE id=:id',['id'=>$documentId]);$this->json(['ok'=>true,'message'=>$message,'document'=>$document,'view_url'=>url('files/document/'.$documentId)]);}
                Flash::set('warning',$message);
                $this->redirect('student/application#documents');
            }
            if ($stored&&is_file(BASE_PATH.'/storage/private/'.$stored['path'])) @unlink(BASE_PATH.'/storage/private/'.$stored['path']);
            if($async)$this->json(['ok'=>false,'message'=>$exception->getMessage()],422);
            Flash::set('warning',$exception->getMessage());
        }
        $this->redirect('student/application#documents');
    }

    public function continueDocuments(): never
    {
        $application=$this->editableApplication();
        $db=Database::get();
        if($application['status']==='correction_required'){
            $correction=(new CorrectionService())->openForApplication((int)$application['id']);
            $openDocuments=array_filter($correction['items']??[],static fn(array $item):bool=>$item['status']==='open'&&$item['target_type']==='document');
            if($openDocuments){Flash::set('warning','Upload every document requested in the active correction before continuing.');$this->redirect('student/application#documents');}
        }else{
            $category=(string)($db->scalar('SELECT category FROM applicant_profiles WHERE user_id=:user',['user'=>Auth::id()])?:'');
            $missing=(int)$db->scalar("SELECT COUNT(*) FROM cycle_document_requirements requirement WHERE requirement.admission_cycle_id=:cycle AND requirement.is_required=1 AND requirement.stage='application'
                AND (requirement.program_id IS NULL OR EXISTS (SELECT 1 FROM application_preferences preference JOIN cycle_programs cycle_program ON cycle_program.id=preference.cycle_program_id WHERE preference.application_id=:application AND cycle_program.program_id=requirement.program_id))
                AND (requirement.category IS NULL OR requirement.category=:category)
                AND NOT EXISTS (SELECT 1 FROM application_documents document WHERE document.application_id=:application_document AND document.document_type_id=requirement.document_type_id)",['cycle'=>$application['admission_cycle_id'],'application'=>$application['id'],'category'=>$category,'application_document'=>$application['id']]);
            if($missing>0){Flash::set('warning','Upload every required application-stage document before continuing.');$this->redirect('student/application#documents');}
        }
        $db->update('applications',['current_step'=>8,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$application['id']]);
        AuditService::log('application_section_saved','application',$application['id'],[],['section'=>'documents','continue_to'=>'review']);
        Flash::set('success','Documents saved. Review your application before final submission.');
        $this->redirect('student/application#review');
    }

    public function payments(): void
    {
        $db = Database::get();
        $application = $this->applicationRecord();
        $payments = $application ? $db->all('SELECT * FROM payments WHERE application_id = :id ORDER BY created_at DESC', ['id' => $application['id']]) : [];
        $paymentInfo = null;
        if ($application) {
            $paymentInfo = $db->fetch('SELECT cp.application_fee,cp.admission_fee,p.name AS program_name FROM applications a JOIN application_preferences pref ON pref.application_id=a.id JOIN cycle_programs cp ON cp.id=pref.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE a.id=:id ORDER BY CASE WHEN pref.cycle_program_id=a.selected_cycle_program_id THEN 0 ELSE 1 END,pref.preference_order LIMIT 1', ['id' => $application['id']]);
        }
        $paymentSettings = [];
        foreach ($db->all("SELECT key_name, value FROM settings WHERE key_name IN ('upi_id','bank_details')") as $setting) $paymentSettings[$setting['key_name']] = $setting['value'];
        $paymentType = $application && in_array($application['status'], ['selected','payment_pending','fee_verified','admitted'], true) ? 'admission_fee' : 'application_fee';
        $assessment=$application?$db->fetch('SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type=:type',['application'=>$application['id'],'type'=>$paymentType]):null;
        $expectedAmount = (float) ($assessment['total_amount'] ?? $paymentInfo[$paymentType] ?? 0);
        $allowedStatuses=$paymentType==='admission_fee'?['selected','payment_pending']:['submitted','resubmitted','eligibility_check','under_review','correction_required','approved'];
        $paymentOpen = $application && in_array($application['status'],$allowedStatuses,true) && (!$assessment||!in_array($assessment['status'],['paid','waived','refunded'],true));
        $this->view('student/payments', compact('application', 'payments', 'paymentInfo', 'paymentSettings', 'paymentType', 'assessment', 'expectedAmount', 'paymentOpen') + ['title' => 'Payments & receipts'], 'student');
    }

    public function submitPayment(): never
    {
        $application=$this->applicationRecord();
        if (!$application||empty($_FILES['proof'])) { Flash::set('warning','Upload a payment proof.'); $this->redirect('student/payments'); }
        $db=Database::get();
        $paymentType=in_array($application['status'],['selected','payment_pending','fee_verified','admitted'],true)?'admission_fee':'application_fee';
        $allowed=$paymentType==='admission_fee'?['selected','payment_pending']:['submitted','resubmitted','eligibility_check','under_review','correction_required','approved'];
        if (!in_array($application['status'],$allowed,true)) { Flash::set('warning','No payment is due at the current application stage.'); $this->redirect('student/payments'); }
        $assessment=$db->fetch('SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type=:type',['application'=>$application['id'],'type'=>$paymentType]);
        if (!$assessment) {
            $programId=$paymentType==='admission_fee'?(int)$application['selected_cycle_program_id']:(int)$db->scalar('SELECT cycle_program_id FROM application_preferences WHERE application_id=:id ORDER BY preference_order LIMIT 1',['id'=>$application['id']]);
            $category=(string)($db->scalar('SELECT category FROM applicant_profiles WHERE user_id=:user',['user'=>Auth::id()])?:'');
            try { $assessment=$db->transaction(fn(Database $db): array=>(new AdmissionFeeService())->assess($db,(int)$application['id'],$programId,$paymentType,$category,$application['configuration_version_id']?(int)$application['configuration_version_id']:null)); }
            catch (RuntimeException $exception) { Flash::set('warning',$exception->getMessage()); $this->redirect('student/payments'); }
        }
        $expectedFee=(float)$assessment['total_amount'];
        $validator=new Validator();
        $errors=$validator->validate($_POST,['amount'=>'required|numeric','reference_number'=>'required|max:100','paid_at'=>'required|date','method'=>'required|in:upi,bank_transfer,cash']);
        if ($expectedFee<=0) $errors['amount'][]='No positive fee is due. Contact Admissions.';
        if (abs((float)($_POST['amount']??0)-$expectedFee)>0.01) $errors['amount'][]='The amount must match the assessed fee of '.money($expectedFee).'.';
        if (!empty($_POST['paid_at'])&&strtotime((string)$_POST['paid_at'])>strtotime(date('Y-m-d'))) $errors['paid_at'][]='Payment date cannot be in the future.';
        if ($db->fetch("SELECT id FROM payments WHERE application_id=:id AND type=:type AND status IN ('pending','verified') LIMIT 1",['id'=>$application['id'],'type'=>$paymentType])) $errors['amount'][]='A payment for this fee is already pending or verified.';
        if ($errors) { Flash::withErrors($errors); Flash::withInput($_POST); $this->redirect('student/payments'); }
        $stored=null;
        try {
            $stored=(new UploadService())->store($_FILES['proof'],'payments/'.$application['id'],['application/pdf','image/jpeg','image/png'],5);
            $id=$db->transaction(function (Database $db) use ($application,$paymentType,$expectedFee,$stored,$assessment): int {
                $locked=$db->fetch('SELECT * FROM application_fee_assessments WHERE id=:id AND application_id=:application FOR UPDATE',['id'=>$assessment['id'],'application'=>$application['id']]);
                if(!$locked||in_array($locked['status'],['paid','waived','refunded'],true)) throw new RuntimeException('This fee is no longer payable. Refresh the payment page.');
                if($db->fetch("SELECT id FROM payments WHERE application_id=:id AND type=:type AND status IN ('pending','verified') LIMIT 1",['id'=>$application['id'],'type'=>$paymentType])) throw new RuntimeException('A payment for this fee is already pending or verified.');
                if($db->fetch("SELECT id FROM payments WHERE method=:method AND reference_number=:reference AND status IN ('pending','verified') LIMIT 1",['method'=>(string)$_POST['method'],'reference'=>trim((string)$_POST['reference_number'])])) throw new RuntimeException('This payment reference is already in use.');
                $id=$db->insert('payments',['application_id'=>$application['id'],'user_id'=>Auth::id(),'fee_assessment_id'=>$assessment['id'],'type'=>$paymentType,'amount'=>$expectedFee,'currency'=>$assessment['currency'],'method'=>(string)$_POST['method'],'reference_number'=>trim((string)$_POST['reference_number']),'proof_path'=>$stored['path'],'proof_original_name'=>$stored['original_name'],'status'=>'pending','paid_at'=>(string)$_POST['paid_at'],'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                $db->update('application_fee_assessments',['status'=>'payment_submitted','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$assessment['id']]);
                if ($paymentType==='admission_fee'&&$application['status']==='selected') {
                    $db->update('applications',['status'=>'payment_pending','status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$application['id']]);
                    $db->insert('application_status_history',['application_id'=>$application['id'],'from_status'=>'selected','to_status'=>'payment_pending','remarks'=>'Admission fee proof submitted','changed_by'=>Auth::id(),'created_at'=>date('Y-m-d H:i:s')]);
                }
                return $id;
            });
            AuditService::log('payment_proof_submitted','payment',$id,[],['assessment_id'=>$assessment['id'],'type'=>$paymentType]);
            Flash::set('success','Payment proof submitted for Accounts verification.');
        } catch (RuntimeException $exception) {
            if ($stored&&is_file(BASE_PATH.'/storage/private/'.$stored['path'])) @unlink(BASE_PATH.'/storage/private/'.$stored['path']);
            Flash::set('warning',$exception->getMessage());
        }
        $this->redirect('student/payments');
    }

    public function messages(): void
    {
        $notifications = Database::get()->all('SELECT * FROM notifications WHERE user_id = :user ORDER BY created_at DESC LIMIT 100', ['user' => Auth::id()]);
        Database::get()->query('UPDATE notifications SET read_at = COALESCE(read_at, :now) WHERE user_id = :user', ['now' => date('Y-m-d H:i:s'), 'user' => Auth::id()]);
        $this->view('student/messages', ['notifications' => $notifications, 'title' => 'Messages & notifications'], 'student');
    }

    public function tickets(): void
    {
        $tickets = Database::get()->all('SELECT * FROM support_tickets WHERE user_id = :user ORDER BY updated_at DESC', ['user' => Auth::id()]);
        $this->view('student/tickets', ['tickets' => $tickets, 'title' => 'Help & support'], 'student');
    }

    public function createTicket(): never
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['subject' => 'required|max:180', 'category' => 'required|in:admission,documents,payment,technical,other', 'message' => 'required|min:10|max:3000']);
        if ($errors) {
            Flash::withErrors($errors); Flash::withInput($_POST); $this->redirect('student/support');
        }
        $db = Database::get();
        $ticketId = $db->transaction(function (Database $db): int {
            $id = $db->insert('support_tickets', [
                'ticket_number' => 'TKT-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                'user_id' => Auth::id(), 'subject' => trim((string) $_POST['subject']), 'category' => $_POST['category'],
                'priority' => 'normal', 'status' => 'open', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $db->insert('ticket_messages', ['ticket_id' => $id, 'user_id' => Auth::id(), 'message' => trim((string) $_POST['message']), 'is_staff_reply' => 0, 'created_at' => date('Y-m-d H:i:s')]);
            return $id;
        });
        AuditService::log('ticket_created', 'support_ticket', $ticketId);
        Flash::set('success', 'Support ticket created.');
        $this->redirect('student/support');
    }

    public function showTicket(string $id): void
    {
        $db = Database::get();
        $ticket = $db->fetch('SELECT * FROM support_tickets WHERE id = :id AND user_id = :user', ['id' => (int) $id, 'user' => Auth::id()]);
        if (!$ticket) { http_response_code(404); $this->view('errors/404', ['title' => 'Ticket not found'], 'student'); return; }
        $messages = $db->all("SELECT tm.*, CONCAT(u.first_name, ' ', u.last_name) AS sender_name FROM ticket_messages tm JOIN users u ON u.id = tm.user_id WHERE tm.ticket_id = :id ORDER BY tm.created_at", ['id' => (int) $id]);
        $this->view('student/ticket', compact('ticket', 'messages') + ['title' => $ticket['ticket_number']], 'student');
    }

    public function replyTicket(string $id): never
    {
        $db = Database::get();
        $ticket = $db->fetch('SELECT * FROM support_tickets WHERE id = :id AND user_id = :user', ['id' => (int) $id, 'user' => Auth::id()]);
        $message = trim((string) ($_POST['message'] ?? ''));
        if (!$ticket || in_array($ticket['status'], ['closed'], true) || mb_strlen($message) < 2 || mb_strlen($message) > 3000) {
            Flash::set('warning', 'The ticket is closed or the reply is invalid.');
            $this->redirect('student/support/' . $id);
        }
        $db->transaction(function (Database $db) use ($ticket, $message): void {
            $db->insert('ticket_messages', ['ticket_id' => $ticket['id'], 'user_id' => Auth::id(), 'message' => $message, 'attachment_path' => null, 'is_staff_reply' => 0, 'created_at' => date('Y-m-d H:i:s')]);
            $db->update('support_tickets', ['status' => 'open', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $ticket['id']]);
        });
        AuditService::log('applicant_ticket_replied', 'support_ticket', $ticket['id']);
        Flash::set('success', 'Your reply was added to the ticket.');
        $this->redirect('student/support/' . $id);
    }

    public function saveIdentity(): never
    {
        $application = $this->applicationRecord();
        $policy = $this->aadhaarPolicy($application);
        if (!$application || !$this->identityCollectionOpen($policy, (string) $application['status'])) {
            Flash::set('warning', 'Identity collection is not open at this application stage.');
            $this->redirect('student/application');
        }
        $type = (string) ($_POST['government_id_type'] ?? '');
        $identifier = preg_replace('/\s+/', '', trim((string) ($_POST['government_id'] ?? '')));
        if (!in_array($type, ['aadhaar','passport','voter_id'], true) || $identifier === '' || empty($_POST['identity_consent'])) {
            Flash::set('warning', 'Choose an identity type, enter its number, and provide the specific collection consent.');
            $this->redirect('student/application#identity-stage');
        }
        if ($type === 'aadhaar' && !preg_match('/^[0-9]{12}$/', $identifier)) {
            Flash::set('warning', 'Aadhaar numbers must contain exactly 12 digits.');
            $this->redirect('student/application#identity-stage');
        }
        $db = Database::get();
        $db->transaction(function (Database $db) use ($application, $type, $identifier, $policy): void {
            $db->update('applicant_profiles', [
                'government_id_type' => $type, 'government_id_encrypted' => Encryption::encrypt($identifier),
                'government_id_last4' => substr(preg_replace('/\D+/', '', $identifier) ?: $identifier, -4), 'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :user', ['user' => Auth::id()]);
            $db->insert('consent_records', [
                'user_id' => Auth::id(), 'application_id' => $application['id'], 'consent_type' => 'identity_collection',
                'purpose' => 'Identity verification for admission at the configured ' . $policy . ' stage', 'version' => '1.0',
                'granted' => 1, 'ip_address' => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), 'withdrawn_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        });
        AuditService::log('sensitive_identity_collected', 'application', $application['id'], [], ['type' => $type, 'stage' => $policy, 'last4' => substr($identifier, -4)]);
        Flash::set('success', 'Identity details were encrypted and your consent was recorded.');
        $this->redirect('student/application#identity-stage');
    }

    public function printApplication(): void
    {
        $application = $this->applicationRecord();
        if (!$application) {
            $this->redirect('student/dashboard');
        }
        $db = Database::get();
        $profile = $db->fetch('SELECT ap.*, u.first_name, u.last_name, u.email, u.mobile FROM applicant_profiles ap JOIN users u ON u.id = ap.user_id WHERE ap.user_id = :user', ['user' => Auth::id()]);
        $education = $db->all('SELECT * FROM education_records WHERE application_id = :id ORDER BY level', ['id' => $application['id']]);
        $preferences = $db->all('SELECT p.name, pref.preference_order FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id JOIN programs p ON p.id = cp.program_id WHERE pref.application_id = :id ORDER BY pref.preference_order', ['id' => $application['id']]);
        $this->view('documents/application', compact('application', 'profile', 'education', 'preferences') + ['title' => 'Application ' . ($application['application_number'] ?: 'Draft')], 'document');
    }

    private function localizeContent(string $entity, array $record): array
    {
        $locale = Translator::locale();
        if ($locale === 'en') return $record;
        $row = Database::get()->fetch('SELECT fields_json FROM content_translations WHERE entity_type = :type AND entity_id = :id AND locale = :locale', ['type' => $entity, 'id' => $record['id'], 'locale' => $locale]);
        $fields = $row ? json_decode((string) $row['fields_json'], true) : null;
        if (is_array($fields)) foreach ($fields as $key => $value) if ($value !== null && $value !== '') $record[$key] = $value;
        return $record;
    }

    private function identityCollectionOpen(string $policy, string $status): bool
    {
        if ($policy === 'application') return in_array($status, ['draft','correction_required'], true);
        if ($policy === 'post_selection') return in_array($status, ['selected','fee_verified','admitted'], true);
        if ($policy === 'admission') return in_array($status, ['fee_verified','admitted'], true);
        return false;
    }

    private function aadhaarPolicy(?array $application): string
    {
        $db = Database::get();
        $policy = (string) ($db->scalar("SELECT value FROM settings WHERE key_name = 'aadhaar_collection_stage'") ?: 'disabled');
        if ($policy === 'configurable' && !empty($application['admission_cycle_id'])) {
            $cyclePolicy = (string) ($db->scalar("SELECT value FROM settings WHERE key_name = :key", ['key' => 'aadhaar_collection_stage_cycle_' . $application['admission_cycle_id']]) ?: 'disabled');
            return in_array($cyclePolicy, ['disabled','application','post_selection','admission'], true) ? $cyclePolicy : 'disabled';
        }
        return in_array($policy, ['disabled','application','post_selection','admission'], true) ? $policy : 'disabled';
    }

    private function canReapply(array $application): bool
    {
        if(($application['status']??'')!=='rejected')return false;
        $latestId=(int)Database::get()->scalar('SELECT id FROM applications WHERE user_id=:user AND admission_cycle_id=:cycle ORDER BY attempt_no DESC,id DESC LIMIT 1',['user'=>Auth::id(),'cycle'=>$application['admission_cycle_id']]);
        if($latestId!==(int)$application['id'])return false;
        return (new AdmissionCycleService())->acceptsApplications([
            'status'=>$application['cycle_status']??'',
            'starts_at'=>$application['cycle_starts_at']??'',
            'ends_at'=>$application['cycle_ends_at']??'',
            'closing_soon_hours'=>72,
        ]);
    }

    private function applicationRecord(): ?array
    {
        $db=Database::get();
        $requested=(int)($_GET['application_id']??$_SESSION['active_application_id']??0);
        if ($requested>0) {
            $record=$db->fetch('SELECT a.*,ac.name AS cycle_name,ac.slug AS cycle_slug,ac.status AS cycle_status,ac.starts_at AS cycle_starts_at,ac.ends_at AS cycle_ends_at,ac.correction_deadline,ac.declaration_text,ac.max_program_preferences FROM applications a JOIN admission_cycles ac ON ac.id=a.admission_cycle_id WHERE a.id=:id AND a.user_id=:user',['id'=>$requested,'user'=>Auth::id()]);
            if ($record) return $record;
        }
        return $db->fetch('SELECT a.*,ac.name AS cycle_name,ac.slug AS cycle_slug,ac.status AS cycle_status,ac.starts_at AS cycle_starts_at,ac.ends_at AS cycle_ends_at,ac.correction_deadline,ac.declaration_text,ac.max_program_preferences FROM applications a JOIN admission_cycles ac ON ac.id=a.admission_cycle_id WHERE a.user_id=:user ORDER BY a.created_at DESC,a.attempt_no DESC,a.id DESC LIMIT 1',['user'=>Auth::id()]);
    }

    private function editableApplication(): array
    {
        $application=$this->applicationRecord();
        if (!$application||!in_array($application['status'],['draft','correction_required'],true)) {
            Flash::set('warning','This application is read-only at its current stage.');
            $this->redirect('student/dashboard');
        }
        if ($application['status']==='draft') {
            $cycle=Database::get()->fetch('SELECT * FROM admission_cycles WHERE id=:id',['id'=>$application['admission_cycle_id']]);
            if (!$cycle||!(new AdmissionCycleService())->acceptsApplications($cycle)) { Flash::set('warning','The application window is closed. Your draft is retained but cannot be changed or submitted.'); $this->redirect('student/dashboard'); }
        } else {
            $correction=(new CorrectionService())->openForApplication((int)$application['id']);
            if (!$correction||($correction['due_at']&&strtotime((string)$correction['due_at'])<time())) { Flash::set('warning','The correction window is not available. Contact Admissions.'); $this->redirect('student/dashboard'); }
        }
        return $application;
    }

    private function savePersonal(Database $db): void
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['date_of_birth' => 'required|date', 'gender' => 'required|in:male,female,other,prefer_not_to_say', 'category' => 'required|max:50', 'nationality' => 'required|max:80']);
        if (!(int)$db->scalar("SELECT COUNT(*) FROM admission_categories WHERE code=:category AND status='active'",['category'=>trim((string)($_POST['category']??''))])) $errors['category'][]='Select a valid configured category.';
        if ($errors) throw new RuntimeException(implode(' ', array_map(fn($e) => $e[0], $errors)));
        $existingProfile = $db->fetch('SELECT government_id_type, government_id_encrypted, government_id_last4 FROM applicant_profiles WHERE user_id = :user', ['user' => Auth::id()]) ?: [];
        $application = $this->applicationRecord();
        $identityPolicy = $this->aadhaarPolicy($application);
        $identityAllowed = $application && $this->identityCollectionOpen($identityPolicy, (string) $application['status']);
        $newIdentifier = $identityAllowed ? trim((string) ($_POST['government_id'] ?? '')) : '';
        $identifierDigits = preg_replace('/\s+/', '', $newIdentifier);
        $identityType = trim((string) ($_POST['government_id_type'] ?? ($existingProfile['government_id_type'] ?? '')));
        if ($newIdentifier !== '' && empty($_POST['identity_consent'])) throw new RuntimeException('Consent is required before collecting an identity number.');
        if ($newIdentifier !== '' && $identityType === 'aadhaar' && !preg_match('/^[0-9]{12}$/', $identifierDigits)) throw new RuntimeException('Aadhaar numbers must contain exactly 12 digits.');
        $data = [
            'date_of_birth' => $_POST['date_of_birth'], 'gender' => $_POST['gender'], 'category' => trim((string) $_POST['category']),
            'nationality' => trim((string) $_POST['nationality']), 'blood_group' => trim((string) ($_POST['blood_group'] ?? '')),
            'religion' => trim((string) ($_POST['religion'] ?? '')), 'mother_tongue' => trim((string) ($_POST['mother_tongue'] ?? '')),
            'government_id_type' => $identityAllowed ? $identityType : ($existingProfile['government_id_type'] ?? null),
            'government_id_encrypted' => $newIdentifier !== '' ? Encryption::encrypt($identifierDigits) : ($existingProfile['government_id_encrypted'] ?? null),
            'government_id_last4' => $newIdentifier !== '' ? substr(preg_replace('/\D+/', '', $newIdentifier), -4) : ($existingProfile['government_id_last4'] ?? null),
            'profile_completion' => 35, 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $db->update('applicant_profiles', $data, 'user_id = :user', ['user' => Auth::id()]);
        if ($newIdentifier !== '' && $application) {
            $db->insert('consent_records', [
                'user_id' => Auth::id(), 'application_id' => $application['id'], 'consent_type' => 'identity_collection',
                'purpose' => 'Identity verification for admission at the configured ' . $identityPolicy . ' stage', 'version' => '1.0',
                'granted' => 1, 'ip_address' => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), 'withdrawn_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function saveAddress(Database $db, int $applicationId): void
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['address_line1' => 'required|max:200', 'city' => 'required|max:100', 'state' => 'required|max:100', 'postal_code' => 'required|min:6|max:10', 'country' => 'required|max:80']);
        if ($errors) throw new RuntimeException(implode(' ', array_map(fn($e) => $e[0], $errors)));
        $data = [
            'application_id' => $applicationId, 'address_line1' => trim((string) $_POST['address_line1']), 'address_line2' => trim((string) ($_POST['address_line2'] ?? '')),
            'city' => trim((string) $_POST['city']), 'district' => trim((string) ($_POST['district'] ?? '')), 'state' => trim((string) $_POST['state']),
            'postal_code' => trim((string) $_POST['postal_code']), 'country' => trim((string) $_POST['country']), 'same_as_correspondence' => isset($_POST['same_as_correspondence']) ? 1 : 0,
            'correspondence_address' => trim((string) ($_POST['correspondence_address'] ?? '')), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $existing = $db->fetch('SELECT id FROM applicant_addresses WHERE application_id = :id', ['id' => $applicationId]);
        $existing ? $db->update('applicant_addresses', $data, 'id = :id', ['id' => $existing['id']]) : $db->insert('applicant_addresses', $data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    private function saveGuardian(Database $db, int $applicationId): void
    {
        $validator = new Validator();
        $errors = $validator->validate($_POST, ['guardian_name' => 'required|max:160', 'relationship' => 'required|max:50', 'guardian_mobile' => 'required|min:10|max:15']);
        if ($errors) throw new RuntimeException(implode(' ', array_map(fn($e) => $e[0], $errors)));
        $data = [
            'application_id' => $applicationId, 'name' => trim((string) $_POST['guardian_name']), 'relationship' => trim((string) $_POST['relationship']),
            'occupation' => trim((string) ($_POST['occupation'] ?? '')), 'annual_income' => (float) ($_POST['annual_income'] ?? 0),
            'mobile' => trim((string) $_POST['guardian_mobile']), 'email' => mb_strtolower(trim((string) ($_POST['guardian_email'] ?? ''))), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $existing = $db->fetch('SELECT id FROM guardians WHERE application_id = :id', ['id' => $applicationId]);
        $existing ? $db->update('guardians', $data, 'id = :id', ['id' => $existing['id']]) : $db->insert('guardians', $data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    private function saveAcademic(Database $db, int $applicationId): void
    {
        foreach (['10', '12'] as $level) {
            $prefix = 'class_' . $level . '_';
            $data = [
                'application_id' => $applicationId, 'level' => 'class_' . $level, 'board' => trim((string) ($_POST[$prefix . 'board'] ?? '')),
                'institution' => trim((string) ($_POST[$prefix . 'institution'] ?? '')), 'passing_year' => (int) ($_POST[$prefix . 'year'] ?? 0),
                'roll_number' => trim((string) ($_POST[$prefix . 'roll'] ?? '')), 'total_marks' => (float) ($_POST[$prefix . 'total'] ?? 0),
                'obtained_marks' => (float) ($_POST[$prefix . 'obtained'] ?? 0), 'percentage' => (float) ($_POST[$prefix . 'percentage'] ?? 0),
                'subjects' => trim((string) ($_POST[$prefix . 'subjects'] ?? '')), 'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (!$data['board'] || !$data['institution'] || $data['passing_year']<1900 || $data['passing_year']>(int)date('Y')+2) throw new RuntimeException('Complete both Class 10 and Class 12 details with a valid passing year.');
            if ($data['total_marks']<=0||$data['obtained_marks']<0||$data['obtained_marks']>$data['total_marks']) throw new RuntimeException('Enter valid total and obtained marks for both academic records.');
            $data['percentage']=round(($data['obtained_marks']/$data['total_marks'])*100,2);
            $existing = $db->fetch('SELECT id FROM education_records WHERE application_id = :id AND level = :level', ['id' => $applicationId, 'level' => 'class_' . $level]);
            $existing ? $db->update('education_records', $data, 'id = :id', ['id' => $existing['id']]) : $db->insert('education_records', $data + ['created_at' => date('Y-m-d H:i:s')]);
        }
        $examData = [
            'application_id' => $applicationId, 'exam_name' => trim((string) ($_POST['exam_name'] ?? '')), 'roll_number' => trim((string) ($_POST['exam_roll'] ?? '')),
            'rank_score' => trim((string) ($_POST['exam_rank'] ?? '')), 'exam_year' => (int) ($_POST['exam_year'] ?? 0), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($examData['exam_name']) {
            $existing = $db->fetch('SELECT id FROM entrance_exams WHERE application_id = :id', ['id' => $applicationId]);
            $existing ? $db->update('entrance_exams', $examData, 'id = :id', ['id' => $existing['id']]) : $db->insert('entrance_exams', $examData + ['created_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function savePreferences(Database $db, int $applicationId, int $cycleId): void
    {
        $choices = array_values(array_unique(array_map('intval', (array) ($_POST['program_preferences'] ?? []))));
        if (!$choices) throw new RuntimeException('Select at least one programme.');
        $maximum=(int)($db->scalar('SELECT max_program_preferences FROM admission_cycles WHERE id=:cycle',['cycle'=>$cycleId])?:1);
        if (count($choices)>$maximum) throw new RuntimeException("Select no more than {$maximum} programme preferences.");
        $valid = $db->all("SELECT cp.id FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle AND cp.status='active' AND p.status='active'", ['cycle' => $cycleId]);
        $validIds = array_map(fn($row) => (int) $row['id'], $valid);
        if (count(array_intersect($choices,$validIds))!==count($choices)) throw new RuntimeException('One or more programme choices are no longer available.');
        $db->query('DELETE FROM application_preferences WHERE application_id = :id', ['id' => $applicationId]);
        foreach ($choices as $index => $choice) $db->insert('application_preferences', ['application_id' => $applicationId, 'cycle_program_id' => $choice, 'preference_order' => $index + 1, 'allocation_status'=>'pending','created_at' => date('Y-m-d H:i:s')]);
    }

    private function saveCustomFields(Database $db, int $applicationId, int $cycleId, int $configurationVersionId): void
    {
        $fields=$db->all("SELECT * FROM admission_form_fields WHERE admission_cycle_id=:cycle AND status='active' AND canonical_binding IS NULL ORDER BY sort_order,id",['cycle'=>$cycleId]);
        $submitted=(array)($_POST['custom']??[]);
        $context=$db->fetch('SELECT a.*,ap.* FROM applications a LEFT JOIN applicant_profiles ap ON ap.user_id=a.user_id WHERE a.id=:id',['id'=>$applicationId])?:[];
        foreach($db->all('SELECT afr.form_field_id,afr.value_text,afr.value_json,aff.field_key FROM application_field_responses afr JOIN admission_form_fields aff ON aff.id=afr.form_field_id WHERE afr.application_id=:id',['id'=>$applicationId]) as $stored)$context[$stored['field_key']]=$stored['value_json']?json_decode($stored['value_json'],true):$stored['value_text'];
        foreach($fields as $field)if(array_key_exists((int)$field['id'],$submitted))$context[$field['field_key']]=$submitted[(int)$field['id']];
        $record=$db->fetch('SELECT status FROM applications WHERE id=:id',['id'=>$applicationId]);$restricted=null;
        if(($record['status']??'')==='correction_required') { $open=(new CorrectionService())->openForApplication($applicationId);$restricted=[];$allCustom=false;foreach($open['items']??[] as $item){if($item['status']!=='open')continue;if($item['target_type']==='section'){if($item['target_key']==='custom')$allCustom=true;else foreach($db->all('SELECT aff.field_key FROM admission_form_fields aff JOIN admission_form_sections afs ON afs.id=aff.section_id WHERE aff.admission_cycle_id=:cycle AND afs.section_key=:section AND aff.canonical_binding IS NULL',['cycle'=>$cycleId,'section'=>$item['target_key']]) as $sectionField)$restricted[]=$sectionField['field_key'];}if($item['target_type']==='field')$restricted[]=$item['target_key'];}if($allCustom)$restricted=null; }
        foreach($fields as $field) {
            if($restricted!==null&&!in_array($field['field_key'],$restricted,true)){if(array_key_exists((int)$field['id'],$submitted))throw new RuntimeException('Only specifically requested fields may be corrected.');continue;}
            if(!$this->conditionMatches($field['conditional_rules']??null,$context)) continue;
            $id=(int)$field['id']; $value=$submitted[$id]??null; $type=(string)$field['field_type'];
            if (in_array($type,['checkbox','multiselect'],true)) $value=array_values(array_filter(array_map('strval',(array)$value),static fn(string $item): bool=>$item!==''));
            else $value=is_array($value)?'':trim((string)$value);
            if ($field['is_required']&&($value===''||$value===null||$value===[])) throw new RuntimeException($field['label'].' is required.');
            if ($value===''||$value===null||$value===[]) continue;
            $options=json_decode((string)($field['options_json']??''),true)?:[]; $allowed=array_map('strval',array_keys($options)===range(0,count($options)-1)?$options:array_keys($options));
            if (in_array($type,['select','radio'],true)&&$allowed&&!in_array((string)$value,$allowed,true)) throw new RuntimeException('Select a valid '.$field['label'].'.');
            if (in_array($type,['checkbox','multiselect'],true)&&$allowed&&array_diff($value,$allowed)) throw new RuntimeException('Select valid values for '.$field['label'].'.');
            if ($type==='email'&&filter_var((string)$value,FILTER_VALIDATE_EMAIL)===false) throw new RuntimeException('Enter a valid '.$field['label'].'.');
            if ($type==='number'&&!is_numeric($value)) throw new RuntimeException($field['label'].' must be numeric.');
            if ($type==='date'&&strtotime((string)$value)===false) throw new RuntimeException('Enter a valid '.$field['label'].'.');
            if ($type==='file') throw new RuntimeException($field['label'].' must be configured through the protected document builder.');
            $data=['configuration_version_id'=>$configurationVersionId?:null,'value_text'=>is_array($value)?null:mb_substr((string)$value,0,10000),'value_json'=>is_array($value)?json_encode($value,JSON_UNESCAPED_UNICODE):null,'updated_at'=>date('Y-m-d H:i:s')];
            $existing=$db->fetch('SELECT id FROM application_field_responses WHERE application_id=:application AND form_field_id=:field',['application'=>$applicationId,'field'=>$id]);
            if ($existing) $db->update('application_field_responses',$data,'id=:id',['id'=>$existing['id']]);
            else $db->insert('application_field_responses',$data+['application_id'=>$applicationId,'form_field_id'=>$id,'created_at'=>date('Y-m-d H:i:s')]);
        }
    }

    private function conditionMatches(mixed $encoded, array $context): bool
    {
        if($encoded===null||$encoded==='') return true;
        $rule=is_array($encoded)?$encoded:json_decode((string)$encoded,true);
        if(!is_array($rule)) return false;
        if(isset($rule['all'])&&is_array($rule['all'])) return !in_array(false,array_map(fn($item)=>$this->conditionMatches($item,$context),$rule['all']),true);
        if(isset($rule['any'])&&is_array($rule['any'])) return in_array(true,array_map(fn($item)=>$this->conditionMatches($item,$context),$rule['any']),true);
        $key=(string)($rule['field']??''); if($key==='') return true; $actual=$context[$key]??null;$expected=$rule['value']??null;$operator=(string)($rule['operator']??'eq');
        return match($operator){'eq'=>(string)$actual===(string)$expected,'neq'=>(string)$actual!==(string)$expected,'in'=>in_array((string)$actual,array_map('strval',(array)$expected),true),'not_in'=>!in_array((string)$actual,array_map('strval',(array)$expected),true),'contains'=>is_array($actual)?in_array((string)$expected,array_map('strval',$actual),true):str_contains((string)$actual,(string)$expected),'filled'=>!($actual===null||$actual===''||$actual===[]),'empty'=>$actual===null||$actual===''||$actual===[],default=>false};
    }

    private function documentUploadFailure(string $message,bool $async): never
    {
        if($async)$this->json(['ok'=>false,'message'=>$message],422);
        Flash::set('warning',$message);
        $this->redirect('student/application#documents');
    }

    private function completionScore(int $applicationId): int
    {
        $db = Database::get();
        $score = 10;
        $profile = $db->fetch('SELECT date_of_birth, gender, category FROM applicant_profiles WHERE user_id = :user', ['user' => Auth::id()]);
        if ($profile && $profile['date_of_birth'] && $profile['gender'] && $profile['category']) $score += 20;
        if ($db->scalar('SELECT COUNT(*) FROM applicant_addresses WHERE application_id = :id', ['id' => $applicationId])) $score += 15;
        if ($db->scalar('SELECT COUNT(*) FROM guardians WHERE application_id = :id', ['id' => $applicationId])) $score += 15;
        if ((int) $db->scalar('SELECT COUNT(*) FROM education_records WHERE application_id = :id', ['id' => $applicationId]) >= 2) $score += 20;
        if ($db->scalar('SELECT COUNT(*) FROM application_preferences WHERE application_id = :id', ['id' => $applicationId])) $score += 10;
        if ($db->scalar('SELECT COUNT(*) FROM application_documents WHERE application_id = :id', ['id' => $applicationId])) $score += 10;
        return min(100, $score);
    }
}
