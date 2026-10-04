<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\AdmissionFeeService;
use App\Services\AdmissionNotificationService;
use App\Services\ApplicationWorkflowService;
use App\Services\AuditService;
use App\Services\CorrectionService;
use App\Services\EligibilityService;
use RuntimeException;

final class ApplicationController extends Controller
{
    public function index(): void
    {
        $db=Database::get();
        $filters=[
            'status'=>trim((string)($_GET['status']??'')),'q'=>trim((string)($_GET['q']??'')),
            'cycle'=>(int)($_GET['cycle']??0),'program'=>(int)($_GET['program']??0),
            'category'=>trim((string)($_GET['category']??'')),'eligibility'=>trim((string)($_GET['eligibility']??'')),
            'payment'=>trim((string)($_GET['payment']??'')),'reviewer'=>trim((string)($_GET['reviewer']??'')),
        ];
        $where=['a.deleted_at IS NULL']; $params=[];
        if ($filters['status']!=='') { $where[]='a.status=:status'; $params['status']=$filters['status']; }
        if ($filters['cycle']>0) { $where[]='a.admission_cycle_id=:cycle'; $params['cycle']=$filters['cycle']; }
        if ($filters['program']>0) { $where[]='EXISTS (SELECT 1 FROM application_preferences fp WHERE fp.application_id=a.id AND fp.cycle_program_id=:program)'; $params['program']=$filters['program']; }
        if ($filters['category']!=='') { $where[]='ap.category=:category'; $params['category']=$filters['category']; }
        if ($filters['eligibility']!=='') { $where[]='a.eligibility_status=:eligibility'; $params['eligibility']=$filters['eligibility']; }
        if ($filters['payment']!=='') { $where[]='EXISTS (SELECT 1 FROM payments pay WHERE pay.application_id=a.id AND pay.status=:payment)'; $params['payment']=$filters['payment']; }
        if ($filters['reviewer']==='unassigned') $where[]='a.assigned_to IS NULL';
        elseif ((int)$filters['reviewer']>0) { $where[]='a.assigned_to=:assigned_reviewer'; $params['assigned_reviewer']=(int)$filters['reviewer']; }
        if ($filters['q']!=='') {
            $where[]='(a.application_number LIKE :search_number OR u.first_name LIKE :search_first OR u.last_name LIKE :search_last OR u.email LIKE :search_email OR u.mobile LIKE :search_mobile)';
            $term='%'.$filters['q'].'%'; $params+=['search_number'=>$term,'search_first'=>$term,'search_last'=>$term,'search_email'=>$term,'search_mobile'=>$term];
        }
        if (Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])) { $where[]='a.assigned_to=:reviewer_scope'; $params['reviewer_scope']=Auth::id(); }
        $from=" FROM applications a JOIN users u ON u.id=a.user_id JOIN admission_cycles ac ON ac.id=a.admission_cycle_id LEFT JOIN applicant_profiles ap ON ap.user_id=a.user_id LEFT JOIN users reviewer ON reviewer.id=a.assigned_to LEFT JOIN cycle_programs selected_cp ON selected_cp.id=a.selected_cycle_program_id LEFT JOIN programs selected_program ON selected_program.id=selected_cp.program_id WHERE ".implode(' AND ',$where);
        $total=(int)$db->scalar('SELECT COUNT(DISTINCT a.id)'.$from,$params);
        $viewMode=in_array($_GET['view']??'', ['board','table'], true)?(string)$_GET['view']:'board';
        $perPage=$viewMode==='board'?300:25; $pages=max(1,(int)ceil($total/$perPage)); $page=max(1,min($pages,(int)($_GET['page']??1))); $offset=($page-1)*$perPage;
        $applications=$db->all("SELECT a.*,CONCAT(u.first_name,' ',u.last_name) AS applicant_name,u.email,u.mobile,ap.category,ac.name AS cycle_name,selected_program.name AS selected_program_name,CONCAT(reviewer.first_name,' ',reviewer.last_name) AS reviewer_name,
            (SELECT pay.status FROM payments pay WHERE pay.application_id=a.id ORDER BY pay.id DESC LIMIT 1) AS payment_status,
            (SELECT COUNT(*) FROM application_documents ad WHERE ad.application_id=a.id) AS document_total,
            (SELECT COUNT(*) FROM application_documents ad WHERE ad.application_id=a.id AND ad.status='verified') AS document_verified,
            (SELECT cp.id FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id WHERE pref.application_id=a.id ORDER BY pref.preference_order LIMIT 1) AS first_preference_id,
            (SELECT p.name FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE pref.application_id=a.id ORDER BY pref.preference_order LIMIT 1) AS first_preference_name,
            (SELECT COUNT(*) FROM application_corrections correction WHERE correction.application_id=a.id AND correction.status='open') AS open_corrections,
            TIMESTAMPDIFF(HOUR,COALESCE(a.submitted_at,a.created_at),NOW()) AS age_hours".$from." ORDER BY COALESCE(a.submitted_at,a.created_at) DESC,a.id DESC LIMIT {$perPage} OFFSET {$offset}",$params);
        $workflow=new ApplicationWorkflowService();
        foreach($applications as &$application)$application['allowed_transitions']=$workflow->allowedTransitions((string)$application['status']);unset($application);
        $countParams=[];$countWhere='deleted_at IS NULL';if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])){$countWhere.=' AND assigned_to=:reviewer';$countParams['reviewer']=Auth::id();}
        $summary=$db->fetch("SELECT COUNT(*) AS total,
            SUM(CASE WHEN status IN ('submitted','resubmitted','eligibility_check','under_review','correction_required','approved','verified','waitlisted','selected','payment_pending','fee_verified') THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN assigned_to IS NULL AND status NOT IN ('draft','admitted','rejected','withdrawn') THEN 1 ELSE 0 END) AS unassigned,
            SUM(CASE WHEN status IN ('submitted','resubmitted','eligibility_check','under_review') AND TIMESTAMPDIFF(DAY,COALESCE(submitted_at,created_at),NOW())>=3 THEN 1 ELSE 0 END) AS ageing,
            SUM(CASE WHEN status='correction_required' THEN 1 ELSE 0 END) AS corrections,
            SUM(CASE WHEN status='admitted' THEN 1 ELSE 0 END) AS admitted FROM applications WHERE {$countWhere}",$countParams)??[];
        $cycles=$db->all('SELECT id,name FROM admission_cycles ORDER BY starts_at DESC');
        $programs=$db->all('SELECT cp.id,cp.admission_cycle_id,p.name,p.code FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id ORDER BY p.name');
        $categories=$db->all("SELECT code,name FROM admission_categories WHERE status='active' ORDER BY sort_order,name");
        $reviewers=$db->all("SELECT DISTINCT u.id,CONCAT(u.first_name,' ',u.last_name) AS name FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.status='active' AND r.slug IN ('reviewer','admission-officer','super-admin') ORDER BY name");
        $boardColumns=[
            ['key'=>'intake','label'=>'Intake','hint'=>'Submitted and resubmitted','statuses'=>['draft','submitted','resubmitted'],'target'=>'eligibility_check'],
            ['key'=>'review','label'=>'Review','hint'=>'Checks and officer review','statuses'=>['eligibility_check','under_review'],'target'=>'under_review'],
            ['key'=>'corrections','label'=>'Corrections','hint'=>'Waiting for applicant','statuses'=>['correction_required'],'target'=>'correction_required'],
            ['key'=>'approved','label'=>'Verified','hint'=>'Eligible for next merit run','statuses'=>['approved','verified'],'target'=>'verified'],
            ['key'=>'selected','label'=>'Merit & offers','hint'=>'Waitlisted or selected','statuses'=>['waitlisted','selected'],'target'=>'selected'],
            ['key'=>'payment','label'=>'Payment','hint'=>'Admission fee due','statuses'=>['payment_pending'],'target'=>'payment_pending'],
            ['key'=>'verified','label'=>'Fee verified','hint'=>'Ready to admit','statuses'=>['fee_verified'],'target'=>'fee_verified'],
            ['key'=>'admitted','label'=>'Admitted','hint'=>'Enrolment created','statuses'=>['admitted'],'target'=>'admitted'],
            ['key'=>'closed','label'=>'Closed','hint'=>'Rejected, expired or not selected','statuses'=>['rejected','withdrawn','offer_expired','not_selected'],'target'=>null],
        ];
        $status=$filters['status']; $search=$filters['q'];
        $this->view('admin/applications/index',compact('applications','summary','boardColumns','viewMode','status','search','filters','cycles','programs','categories','reviewers','total','page','pages')+['title'=>'Applications'],'admin');
    }

    public function show(string $id): void
    {
        $db = Database::get();
        $application = $db->fetch("SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email, u.mobile,
            ac.name AS cycle_name, ap.*, a.id AS id, a.status AS status,CONCAT(reviewer.first_name,' ',reviewer.last_name) AS assigned_reviewer_name FROM applications a JOIN users u ON u.id = a.user_id
            JOIN admission_cycles ac ON ac.id = a.admission_cycle_id LEFT JOIN applicant_profiles ap ON ap.user_id = a.user_id LEFT JOIN users reviewer ON reviewer.id=a.assigned_to WHERE a.id = :id", ['id' => (int) $id]);
        if (!$application) { http_response_code(404); $this->view('errors/404', ['title' => 'Application not found'], 'admin'); return; }
        if (Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])&&(int)$application['assigned_to']!==Auth::id()) { http_response_code(403); $this->view('errors/403',['title'=>'Access denied'],'admin'); return; }
        $decodedEligibility = json_decode((string) ($application['eligibility_flags'] ?? ''), true) ?: [];
        $eligibilityFlags = $decodedEligibility['programs']??$decodedEligibility;
        $address = $db->fetch('SELECT * FROM applicant_addresses WHERE application_id = :id', ['id' => $id]);
        $guardian = $db->fetch('SELECT * FROM guardians WHERE application_id = :id', ['id' => $id]);
        $education = $db->all('SELECT * FROM education_records WHERE application_id = :id ORDER BY level', ['id' => $id]);
        $preferences = $db->all('SELECT pref.*, p.name, p.code FROM application_preferences pref JOIN cycle_programs cp ON cp.id = pref.cycle_program_id JOIN programs p ON p.id = cp.program_id WHERE pref.application_id = :id ORDER BY pref.preference_order', ['id' => $id]);
        $documents = $db->all('SELECT ad.*, dt.name AS document_name, CONCAT(u.first_name, " ", u.last_name) AS reviewer_name FROM application_documents ad JOIN document_types dt ON dt.id = ad.document_type_id LEFT JOIN users u ON u.id = ad.reviewed_by WHERE ad.application_id = :id ORDER BY dt.sort_order', ['id' => $id]);
        foreach($documents as &$document)$document['versions']=$db->all('SELECT adv.*,CONCAT(u.first_name," ",u.last_name) AS uploader_name FROM application_document_versions adv LEFT JOIN users u ON u.id=adv.uploaded_by WHERE adv.application_document_id=:document ORDER BY adv.revision_no DESC',['document'=>$document['id']]);unset($document);
        $payments = $db->all('SELECT p.*, CONCAT(u.first_name, " ", u.last_name) AS verifier_name,afa.base_amount,afa.late_amount AS late_fee_amount,afa.total_amount,afa.due_at AS assessment_due_at,afa.status AS assessment_status FROM payments p LEFT JOIN users u ON u.id = p.verified_by LEFT JOIN application_fee_assessments afa ON afa.id=p.fee_assessment_id WHERE p.application_id = :id ORDER BY p.created_at DESC', ['id' => $id]);
        $timeline = $db->all('SELECT ash.*, CONCAT(u.first_name, " ", u.last_name) AS changed_by_name FROM application_status_history ash LEFT JOIN users u ON u.id = ash.changed_by WHERE ash.application_id = :id ORDER BY ash.created_at DESC', ['id' => $id]);
        $notes = $db->all('SELECT n.*, CONCAT(u.first_name, " ", u.last_name) AS author_name FROM staff_notes n JOIN users u ON u.id = n.user_id WHERE n.application_id = :id ORDER BY n.created_at DESC', ['id' => $id]);
        $reviewers = $db->all("SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug IN ('super-admin','admission-officer','reviewer') AND u.status = 'active' ORDER BY name");
        $cyclePrograms=$db->all("SELECT cp.id,p.name,p.code FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle AND cp.status='active' AND EXISTS (SELECT 1 FROM application_preferences pref WHERE pref.application_id=:application AND pref.cycle_program_id=cp.id) ORDER BY p.name",['cycle'=>$application['admission_cycle_id'],'application'=>$application['id']]);
        $categories=$db->all("SELECT code,name FROM admission_categories WHERE status='active' ORDER BY sort_order,name");
        $formSections=$db->all("SELECT * FROM admission_form_sections WHERE admission_cycle_id=:cycle AND status='active' ORDER BY sort_order,id",['cycle'=>$application['admission_cycle_id']]);
        $formFields=$db->all("SELECT * FROM admission_form_fields WHERE admission_cycle_id=:cycle AND status='active' ORDER BY section_id,sort_order,id",['cycle'=>$application['admission_cycle_id']]);
        $documentRequirements=$db->all("SELECT cdr.document_type_id,dt.name,dt.code FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id WHERE cdr.admission_cycle_id=:cycle ORDER BY cdr.sort_order",['cycle'=>$application['admission_cycle_id']]);
        $corrections=$db->all("SELECT ac.*,CONCAT(u.first_name,' ',u.last_name) AS requester_name FROM application_corrections ac JOIN users u ON u.id=ac.requested_by WHERE ac.application_id=:application ORDER BY ac.id DESC",['application'=>$application['id']]);
        foreach($corrections as &$correction) $correction['items']=$db->all('SELECT * FROM application_correction_items WHERE correction_id=:id ORDER BY id',['id'=>$correction['id']]); unset($correction);
        $allocation=$db->fetch("SELECT sa.*,p.name AS program_name FROM seat_allocations sa JOIN cycle_programs cp ON cp.id=sa.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE sa.application_id=:application AND sa.is_active=1",['application'=>$application['id']]);
        $allowedTransitions=(new ApplicationWorkflowService())->allowedTransitions((string)$application['status']);
        $snapshotRevisions=$db->all('SELECT revision.*,acv.version_no AS configuration_version,CONCAT(u.first_name," ",u.last_name) AS creator_name FROM application_submission_snapshot_revisions revision JOIN admission_configuration_versions acv ON acv.id=revision.configuration_version_id LEFT JOIN users u ON u.id=revision.created_by WHERE revision.application_id=:application ORDER BY revision.revision_no DESC',['application'=>$application['id']]);
        AuditService::log('application_viewed','application',$application['id']);
        $this->view('admin/applications/show', compact('application', 'eligibilityFlags', 'address', 'guardian', 'education', 'preferences', 'documents', 'payments', 'timeline', 'notes', 'reviewers', 'cyclePrograms', 'categories', 'formSections', 'formFields', 'documentRequirements', 'corrections', 'allocation', 'allowedTransitions', 'snapshotRevisions') + ['title' => $application['application_number'] ?: 'Draft application'], 'admin');
    }

    public function evaluateEligibility(string $id): never
    {
        $this->assertApplicationAccess((int)$id);
        try {
            $result = (new EligibilityService())->evaluate((int) $id);
            AuditService::log('eligibility_evaluated', 'application', $id, [], $result);
            Flash::set('success', 'Eligibility checks completed: ' . ucwords(str_replace('_', ' ', $result['status'])) . '. Final decisions remain with authorised officers.');
        } catch (\Throwable $exception) {
            Flash::set('warning', 'Eligibility could not be evaluated: ' . $exception->getMessage());
        }
        $this->redirect('admin/applications/' . $id . '#academic');
    }

    public function status(string $id): never
    {
        $status=trim((string)($_POST['status']??''));
        $remarks=trim((string)($_POST['remarks']??''));
        if ($status==='correction_required') { Flash::set('warning','Use the targeted correction request form.'); if(($_POST['return_to']??'')==='board')$this->redirect($this->applicationIndexTarget('board',(string)($_POST['return_query']??''))); $this->redirect('admin/applications/'.$id); }
        if (in_array($status,['rejected','withdrawn'],true)&&$remarks==='') { Flash::set('warning','Remarks are required for this decision.'); if(($_POST['return_to']??'')==='board')$this->redirect($this->applicationIndexTarget('board',(string)($_POST['return_query']??''))); $this->redirect('admin/applications/'.$id); }
        try {
            (new ApplicationWorkflowService())->transition((int)$id,$status,$remarks,(int)Auth::id(),[
                'cycle_program_id'=>(int)($_POST['cycle_program_id']??0),
                'category'=>trim((string)($_POST['seat_category']??'')),
                'quota'=>trim((string)($_POST['seat_quota']??'state')),
                'status_version'=>(int)($_POST['status_version']??-1),
            ]);
            Flash::set('success','Application status updated with state and seat checks.');
        } catch (RuntimeException $exception) { Flash::set('warning',$exception->getMessage()); }
        if (($_POST['return_to']??'')==='board') $this->redirect($this->applicationIndexTarget('board', (string)($_POST['return_query']??'')));
        $this->redirect('admin/applications/'.$id);
    }

    public function requestCorrection(string $id): never
    {
        $this->assertApplicationAccess((int)$id);
        $targetTypes=(array)($_POST['target_type']??[]);
        $targetKeys=(array)($_POST['target_key']??[]);
        $instructions=(array)($_POST['target_instructions']??[]);
        $fieldIds=(array)($_POST['form_field_id']??[]);
        $documentIds=(array)($_POST['document_type_id']??[]);
        $items=[];
        foreach ($targetKeys as $index=>$key) if (trim((string)$key)!=='') $items[]=['target_type'=>$targetTypes[$index]??'section','target_key'=>$key,'instructions'=>$instructions[$index]??($_POST['reason']??''),'form_field_id'=>$fieldIds[$index]??null,'document_type_id'=>$documentIds[$index]??null];
        try {
            (new CorrectionService())->request((int)$id,trim((string)($_POST['reason']??'')),($_POST['due_at']??'')?:null,$items,(int)Auth::id());
            (new AdmissionNotificationService())->status((int)$id,'correction_required');
            Flash::set('success','Targeted correction request sent to the applicant.');
        } catch (RuntimeException $exception) { Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/applications/'.$id.'#decision');
    }

    public function assign(string $id): never
    {
        $reviewerId = (int) ($_POST['assigned_to'] ?? 0);
        $reviewer = Database::get()->fetch("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=:id AND u.status=:status AND r.slug IN ('super-admin','admission-officer','reviewer')", ['id' => $reviewerId, 'status' => 'active']);
        if (!$reviewer) { Flash::set('warning', 'Select a valid reviewer.'); $this->redirect('admin/applications/' . $id); }
        Database::get()->update('applications', ['assigned_to' => $reviewerId, 'assigned_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $id]);
        AuditService::log('application_assigned', 'application', $id, [], ['assigned_to' => $reviewerId]);
        Flash::set('success', 'Application assigned to reviewer.');
        $this->redirect('admin/applications/' . $id);
    }

    public function bulkAssign(): never
    {
        $ids=$this->bulkApplicationIds();
        $reviewerId=(int)($_POST['assigned_to']??0);
        $db=Database::get();
        $reviewer=$db->fetch("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.id=:id AND u.status='active' AND r.slug IN ('super-admin','admission-officer','reviewer')",['id'=>$reviewerId]);
        if(!$ids||!$reviewer){Flash::set('warning',$ids?'Choose a valid active reviewer.':'Select at least one application.');$this->redirect($this->applicationIndexTarget((string)($_POST['return_to']??''),(string)($_POST['return_query']??'')));}
        [$where,$params]=$this->bulkPlaceholders($ids);
        $records=$db->all("SELECT id FROM applications WHERE deleted_at IS NULL AND id IN ({$where})",$params);
        $validIds=array_map('intval',array_column($records,'id'));
        if(!$validIds){Flash::set('warning','No accessible application was selected.');$this->redirect($this->applicationIndexTarget((string)($_POST['return_to']??''),(string)($_POST['return_query']??'')));}
        $now=date('Y-m-d H:i:s');
        $db->transaction(function(Database $db)use($validIds,$reviewerId,$now):void{foreach($validIds as $applicationId)$db->update('applications',['assigned_to'=>$reviewerId,'assigned_at'=>$now,'updated_at'=>$now],'id=:id',['id'=>$applicationId]);});
        $skipped=count($ids)-count($validIds);
        AuditService::log('applications_bulk_assigned','application',null,[],['application_ids'=>$validIds,'assigned_to'=>$reviewerId,'requested_count'=>count($ids),'assigned_count'=>count($validIds),'skipped_count'=>$skipped]);
        $assignmentMessage=count($validIds).' application'.(count($validIds)===1?'':'s').' assigned.';
        if($skipped>0)Flash::set('warning',$assignmentMessage." {$skipped} skipped because the record no longer exists.");
        else Flash::set('success',$assignmentMessage.' Decisions remain individual unless a separate batch transition is explicitly submitted.');
        $this->redirect($this->applicationIndexTarget((string)($_POST['return_to']??''),(string)($_POST['return_query']??'')));
    }

    public function bulkStatus(): never
    {
        $ids=$this->bulkApplicationIds();
        $target=trim((string)($_POST['bulk_status']??''));
        $remarks=trim((string)($_POST['bulk_remarks']??''));
        $allowedTargets=['eligibility_check','under_review','verified','payment_pending','fee_verified','admitted','rejected','withdrawn'];
        if(!$ids||!in_array($target,$allowedTargets,true)){Flash::set('warning',$ids?'Choose a supported batch transition.':'Select at least one application.');$this->redirect($this->applicationIndexTarget((string)($_POST['return_to']??''),(string)($_POST['return_query']??'')));}
        if(in_array($target,['rejected','withdrawn'],true)&&$remarks===''){Flash::set('warning','Batch rejection or withdrawal requires an auditable reason.');$this->redirect($this->applicationIndexTarget((string)($_POST['return_to']??''),(string)($_POST['return_query']??'')));}
        $workflow=new ApplicationWorkflowService();$updated=0;$failures=[];
        foreach($ids as $applicationId){
            try{
                $current=Database::get()->fetch('SELECT status,status_version,assigned_to FROM applications WHERE id=:id AND deleted_at IS NULL',['id'=>$applicationId]);
                if(!$current)throw new RuntimeException('application not found');
                if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])&&(int)$current['assigned_to']!==Auth::id())throw new RuntimeException('application is outside your assignment scope');
                if(!in_array($target,$workflow->allowedTransitions((string)$current['status']),true))throw new RuntimeException('transition not available from '.str_replace('_',' ',(string)$current['status']));
                $workflow->transition($applicationId,$target,$remarks,(int)Auth::id(),['status_version'=>(int)$current['status_version']]);
                $updated++;
            }catch(RuntimeException $exception){$failures[]='#'.$applicationId.' '.$exception->getMessage();}
        }
        AuditService::log('applications_bulk_transitioned','application',null,[],['target_status'=>$target,'requested_count'=>count($ids),'updated_count'=>$updated,'failure_count'=>count($failures)]);
        if($failures)Flash::set('warning',"{$updated} application".($updated===1?'':'s')." changed; ".count($failures).' skipped after server validation: '.implode(' · ',array_slice($failures,0,3)).(count($failures)>3?' · Additional failures omitted from this notice.':''));
        elseif($updated>0)Flash::set('success',"{$updated} application".($updated===1?'':'s')." moved to ".str_replace('_',' ',$target).'.');
        else Flash::set('warning','No application changed.');
        $this->redirect($this->applicationIndexTarget((string)($_POST['return_to']??''),(string)($_POST['return_query']??'')));
    }

    public function note(string $id): never
    {
        $this->assertApplicationAccess((int)$id);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') { Flash::set('warning', 'Enter a note.'); $this->redirect('admin/applications/' . $id); }
        Database::get()->insert('staff_notes', ['application_id' => (int) $id, 'user_id' => Auth::id(), 'note' => $note, 'visibility' => 'staff', 'created_at' => date('Y-m-d H:i:s')]);
        AuditService::log('staff_note_added', 'application', $id);
        Flash::set('success', 'Internal note added.');
        $this->redirect('admin/applications/' . $id . '#notes');
    }

    public function reviewDocument(string $id, string $documentId): never
    {
        $this->assertApplicationAccess((int)$id);
        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['verified','rejected','resubmission_required'], true)) { Flash::set('warning', 'Invalid document decision.'); $this->redirect('admin/applications/' . $id); }
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if ($status !== 'verified' && $remarks === '') { Flash::set('warning', 'Add a reason for rejecting or requesting a new document.'); $this->redirect('admin/applications/' . $id); }
        $db = Database::get();
        $doc = $db->fetch('SELECT * FROM application_documents WHERE id = :doc AND application_id = :app', ['doc' => (int) $documentId, 'app' => (int) $id]);
        if (!$doc) { Flash::set('warning', 'Document not found.'); $this->redirect('admin/applications/' . $id); }
        $db->transaction(function(Database $db) use($doc,$status,$remarks): void {
            $reviewedAt=date('Y-m-d H:i:s');
            $db->update('application_documents', ['status'=>$status,'review_remarks'=>$remarks,'reviewed_by'=>Auth::id(),'reviewed_at'=>$reviewedAt,'updated_at'=>$reviewedAt], 'id=:id', ['id'=>$doc['id']]);
            $db->update('application_document_versions',['status'=>$status,'review_remarks'=>$remarks,'reviewed_by'=>Auth::id(),'reviewed_at'=>$reviewedAt],'application_document_id=:document AND revision_no=:revision',['document'=>$doc['id'],'revision'=>$doc['revision_no']]);
        });
        AuditService::log('document_reviewed', 'application_document', $doc['id'], ['status' => $doc['status']], ['status' => $status,'revision'=>$doc['revision_no']]);
        Flash::set('success', 'Document review saved.');
        $this->redirect('admin/applications/' . $id . '#documents');
    }

    public function verifyPayment(string $id, string $paymentId): never
    {
        $status=(string)($_POST['status']??'');
        if (!in_array($status,['verified','rejected'],true)) { Flash::set('warning','Invalid payment decision.'); $this->redirect('admin/applications/'.$id); }
        $remarks=trim((string)($_POST['remarks']??''));
        if ($status==='rejected'&&$remarks==='') { Flash::set('warning','Add a reason when rejecting a payment.'); $this->redirect('admin/applications/'.$id.'#payments'); }
        $db=Database::get();
        try {
            $payment=$db->transaction(function(Database $db) use($id,$paymentId,$status,$remarks): array {
                $payment=$db->fetch('SELECT * FROM payments WHERE id=:payment AND application_id=:application FOR UPDATE',['payment'=>(int)$paymentId,'application'=>(int)$id]);
                if (!$payment) throw new RuntimeException('Payment not found.');
                if ($payment['status']!=='pending') throw new RuntimeException('Only a pending payment can be reviewed.');
                $assessment=$payment['fee_assessment_id']?$db->fetch('SELECT * FROM application_fee_assessments WHERE id=:id FOR UPDATE',['id'=>$payment['fee_assessment_id']]):null;
                if ($assessment&&abs((float)$assessment['total_amount']-(float)$payment['amount'])>0.01) throw new RuntimeException('Payment amount no longer matches its immutable fee assessment.');
                $receipt=$status==='verified'?'NCP-RCT-'.date('Y').'-'.str_pad((string)$payment['id'],6,'0',STR_PAD_LEFT):null;
                $db->update('payments',['status'=>$status,'verification_remarks'=>$remarks,'verified_by'=>Auth::id(),'verified_at'=>date('Y-m-d H:i:s'),'receipt_number'=>$receipt,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$payment['id']]);
                if ($assessment) {
                    if ($status==='verified') (new AdmissionFeeService())->markPaid($db,(int)$assessment['id']);
                    else $db->update('application_fee_assessments',['status'=>'due','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$assessment['id']]);
                }
                if ($status==='verified'&&$payment['type']==='admission_fee') {
                    $application=$db->fetch('SELECT * FROM applications WHERE id=:id FOR UPDATE',['id'=>(int)$id]);
                    if ($application&&in_array($application['status'],['selected','payment_pending'],true)) {
                        $db->update('applications',['status'=>'fee_verified','status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>(int)$id]);
                        $db->insert('application_status_history',['application_id'=>(int)$id,'from_status'=>$application['status'],'to_status'=>'fee_verified','remarks'=>'Admission fee verified by Accounts','changed_by'=>Auth::id(),'created_at'=>date('Y-m-d H:i:s')]);
                        $db->query("UPDATE selection_offers SET status='payment_verified',payment_verified_at=NOW(),updated_at=NOW() WHERE application_id=:application AND status IN ('payment_due','payment_received')",['application'=>(int)$id]);
                    }
                }
                return $payment;
            });
            AuditService::log('payment_reviewed','payment',$payment['id'],['status'=>$payment['status']],['status'=>$status]);
            if($status==='verified')(new AdmissionNotificationService())->status((int)$id,'fee_verified');
            Flash::set('success','Payment verification saved.');
        } catch (RuntimeException $exception) { Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/applications/'.$id.'#payments');
    }

    public function export(): never
    {
        $db=Database::get(); $where=['a.deleted_at IS NULL']; $params=[];
        foreach(['status'=>'a.status','category'=>'ap.category','eligibility'=>'a.eligibility_status'] as $key=>$column) if(trim((string)($_GET[$key]??''))!==''){ $where[]="{$column}=:{$key}"; $params[$key]=trim((string)$_GET[$key]); }
        if((int)($_GET['cycle']??0)>0){$where[]='a.admission_cycle_id=:cycle';$params['cycle']=(int)$_GET['cycle'];}
        if((int)($_GET['program']??0)>0){$where[]='EXISTS (SELECT 1 FROM application_preferences fp WHERE fp.application_id=a.id AND fp.cycle_program_id=:program)';$params['program']=(int)$_GET['program'];}
        if(trim((string)($_GET['payment']??''))!==''){$where[]='EXISTS (SELECT 1 FROM payments py WHERE py.application_id=a.id AND py.status=:payment)';$params['payment']=trim((string)$_GET['payment']);}
        $reviewerFilter=trim((string)($_GET['reviewer']??''));if($reviewerFilter==='unassigned')$where[]='a.assigned_to IS NULL';elseif((int)$reviewerFilter>0){$where[]='a.assigned_to=:assigned_reviewer';$params['assigned_reviewer']=(int)$reviewerFilter;}
        $search=trim((string)($_GET['q']??'')); if($search!==''){ $where[]="(a.application_number LIKE :q1 OR CONCAT(u.first_name,' ',u.last_name) LIKE :q2 OR u.email LIKE :q3 OR u.mobile LIKE :q4)"; $term='%'.$search.'%';$params+=['q1'=>$term,'q2'=>$term,'q3'=>$term,'q4'=>$term]; }
        if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])){$where[]='a.assigned_to=:reviewer';$params['reviewer']=Auth::id();}
        $rows=$db->all("SELECT a.application_number,CONCAT(u.first_name,' ',u.last_name) AS applicant,u.email,u.mobile,ac.name AS cycle,ap.category,a.eligibility_status,a.status,p.name AS selected_program,(SELECT py.status FROM payments py WHERE py.application_id=a.id ORDER BY py.id DESC LIMIT 1) AS payment_status,a.submitted_at FROM applications a JOIN users u ON u.id=a.user_id JOIN admission_cycles ac ON ac.id=a.admission_cycle_id LEFT JOIN applicant_profiles ap ON ap.user_id=a.user_id LEFT JOIN cycle_programs cp ON cp.id=a.selected_cycle_program_id LEFT JOIN programs p ON p.id=cp.program_id WHERE ".implode(' AND ',$where).' ORDER BY COALESCE(a.submitted_at,a.created_at) DESC',$params);
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="applications-'.date('Y-m-d').'.csv"');
        $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['Application No.','Applicant','Email','Mobile','Cycle','Category','Eligibility','Status','Selected programme','Payment','Submitted']);
        $safe=static function(mixed $value): string { $value=(string)$value; return preg_match('/^[=+\-@\t\r]/',$value)?"'".$value:$value; };
        foreach($rows as $row) fputcsv($out,array_map($safe,array_values($row))); fclose($out); exit;
    }

    /** @return array<int,int> */
    private function bulkApplicationIds(): array
    {
        $submitted=$_POST['application_ids']??[];
        if(!is_array($submitted))$submitted=[$submitted];
        $ids=[];
        foreach($submitted as $id){$id=filter_var($id,FILTER_VALIDATE_INT);if($id!==false&&(int)$id>0)$ids[(int)$id]=(int)$id;}
        return array_slice(array_values($ids),0,100);
    }

    /** @param array<int,int> $ids @return array{0:string,1:array<string,int>} */
    private function bulkPlaceholders(array $ids): array
    {
        $params=[];$placeholders=[];
        foreach($ids as $index=>$id){$key='application_'.$index;$placeholders[]=':'.$key;$params[$key]=$id;}
        return [implode(',',$placeholders),$params];
    }

    private function applicationIndexTarget(string $view, string $query): string
    {
        parse_str(ltrim($query,'?'),$submitted);
        $allowed=[];
        foreach(['status','q','cycle','program','category','eligibility','payment','reviewer'] as $key)if(isset($submitted[$key])&&is_scalar($submitted[$key])&&(string)$submitted[$key]!=='')$allowed[$key]=(string)$submitted[$key];
        $allowed['view']=$view==='board'?'board':'table';
        return 'admin/applications?'.http_build_query($allowed);
    }

    private function assertApplicationAccess(int $applicationId): array
    {
        $application=Database::get()->fetch('SELECT id,assigned_to FROM applications WHERE id=:id AND deleted_at IS NULL',['id'=>$applicationId]);
        if(!$application){http_response_code(404);exit('Application not found.');}
        if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])&&(int)$application['assigned_to']!==Auth::id()){http_response_code(403);exit('Access denied.');}
        return $application;
    }

}
