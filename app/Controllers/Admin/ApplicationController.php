<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\AdmissionFeeService;
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
            'payment'=>trim((string)($_GET['payment']??'')),'reviewer'=>(int)($_GET['reviewer']??0),
        ];
        $where=['a.deleted_at IS NULL']; $params=[];
        if ($filters['status']!=='') { $where[]='a.status=:status'; $params['status']=$filters['status']; }
        if ($filters['cycle']>0) { $where[]='a.admission_cycle_id=:cycle'; $params['cycle']=$filters['cycle']; }
        if ($filters['program']>0) { $where[]='EXISTS (SELECT 1 FROM application_preferences fp WHERE fp.application_id=a.id AND fp.cycle_program_id=:program)'; $params['program']=$filters['program']; }
        if ($filters['category']!=='') { $where[]='ap.category=:category'; $params['category']=$filters['category']; }
        if ($filters['eligibility']!=='') { $where[]='a.eligibility_status=:eligibility'; $params['eligibility']=$filters['eligibility']; }
        if ($filters['payment']!=='') { $where[]='EXISTS (SELECT 1 FROM payments pay WHERE pay.application_id=a.id AND pay.status=:payment)'; $params['payment']=$filters['payment']; }
        if ($filters['reviewer']>0) { $where[]='a.assigned_to=:assigned_reviewer'; $params['assigned_reviewer']=$filters['reviewer']; }
        if ($filters['q']!=='') {
            $where[]='(a.application_number LIKE :search_number OR u.first_name LIKE :search_first OR u.last_name LIKE :search_last OR u.email LIKE :search_email OR u.mobile LIKE :search_mobile)';
            $term='%'.$filters['q'].'%'; $params+=['search_number'=>$term,'search_first'=>$term,'search_last'=>$term,'search_email'=>$term,'search_mobile'=>$term];
        }
        if (Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])) { $where[]='a.assigned_to=:reviewer_scope'; $params['reviewer_scope']=Auth::id(); }
        $from=" FROM applications a JOIN users u ON u.id=a.user_id JOIN admission_cycles ac ON ac.id=a.admission_cycle_id LEFT JOIN applicant_profiles ap ON ap.user_id=a.user_id LEFT JOIN users reviewer ON reviewer.id=a.assigned_to LEFT JOIN cycle_programs selected_cp ON selected_cp.id=a.selected_cycle_program_id LEFT JOIN programs selected_program ON selected_program.id=selected_cp.program_id WHERE ".implode(' AND ',$where);
        $total=(int)$db->scalar('SELECT COUNT(DISTINCT a.id)'.$from,$params);
        $perPage=25; $pages=max(1,(int)ceil($total/$perPage)); $page=max(1,min($pages,(int)($_GET['page']??1))); $offset=($page-1)*$perPage;
        $applications=$db->all("SELECT a.*,CONCAT(u.first_name,' ',u.last_name) AS applicant_name,u.email,u.mobile,ap.category,ac.name AS cycle_name,selected_program.name AS selected_program_name,CONCAT(reviewer.first_name,' ',reviewer.last_name) AS reviewer_name,(SELECT pay.status FROM payments pay WHERE pay.application_id=a.id ORDER BY pay.id DESC LIMIT 1) AS payment_status".$from." ORDER BY COALESCE(a.submitted_at,a.created_at) DESC,a.id DESC LIMIT {$perPage} OFFSET {$offset}",$params);
        $countParams=[];$countWhere='deleted_at IS NULL';if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])){$countWhere.=' AND assigned_to=:reviewer';$countParams['reviewer']=Auth::id();}
        $counts=$db->all('SELECT status,COUNT(*) AS total FROM applications WHERE '.$countWhere.' GROUP BY status',$countParams);
        $cycles=$db->all('SELECT id,name FROM admission_cycles ORDER BY starts_at DESC');
        $programs=$db->all('SELECT cp.id,cp.admission_cycle_id,p.name,p.code FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id ORDER BY p.name');
        $categories=$db->all("SELECT code,name FROM admission_categories WHERE status='active' ORDER BY sort_order,name");
        $reviewers=$db->all("SELECT DISTINCT u.id,CONCAT(u.first_name,' ',u.last_name) AS name FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.status='active' AND r.slug IN ('reviewer','admission-officer','super-admin') ORDER BY name");
        $status=$filters['status']; $search=$filters['q'];
        $this->view('admin/applications/index',compact('applications','counts','status','search','filters','cycles','programs','categories','reviewers','total','page','pages')+['title'=>'Applications'],'admin');
    }

    public function show(string $id): void
    {
        $db = Database::get();
        $application = $db->fetch("SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS applicant_name, u.email, u.mobile,
            ac.name AS cycle_name, ap.*, a.id AS id, a.status AS status FROM applications a JOIN users u ON u.id = a.user_id
            JOIN admission_cycles ac ON ac.id = a.admission_cycle_id LEFT JOIN applicant_profiles ap ON ap.user_id = a.user_id WHERE a.id = :id", ['id' => (int) $id]);
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
        if ($status==='correction_required') { Flash::set('warning','Use the targeted correction request form.'); $this->redirect('admin/applications/'.$id); }
        if (in_array($status,['rejected','withdrawn'],true)&&$remarks==='') { Flash::set('warning','Remarks are required for this decision.'); $this->redirect('admin/applications/'.$id); }
        try {
            (new ApplicationWorkflowService())->transition((int)$id,$status,$remarks,(int)Auth::id(),[
                'cycle_program_id'=>(int)($_POST['cycle_program_id']??0),
                'category'=>trim((string)($_POST['seat_category']??'')),
                'quota'=>trim((string)($_POST['seat_quota']??'state')),
                'status_version'=>(int)($_POST['status_version']??-1),
            ]);
            Flash::set('success','Application status updated with state and seat checks.');
        } catch (RuntimeException $exception) { Flash::set('warning',$exception->getMessage()); }
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
                        $db->insert('notifications',['user_id'=>$application['user_id'],'type'=>'payment','title'=>'Admission fee verified','message'=>'Accounts verified your admission fee. Follow the dashboard for admission confirmation.','action_url'=>'/student/payments','read_at'=>null,'created_at'=>date('Y-m-d H:i:s')]);
                    }
                }
                return $payment;
            });
            AuditService::log('payment_reviewed','payment',$payment['id'],['status'=>$payment['status']],['status'=>$status]);
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
        if((int)($_GET['reviewer']??0)>0){$where[]='a.assigned_to=:assigned_reviewer';$params['assigned_reviewer']=(int)$_GET['reviewer'];}
        $search=trim((string)($_GET['q']??'')); if($search!==''){ $where[]="(a.application_number LIKE :q1 OR CONCAT(u.first_name,' ',u.last_name) LIKE :q2 OR u.email LIKE :q3 OR u.mobile LIKE :q4)"; $term='%'.$search.'%';$params+=['q1'=>$term,'q2'=>$term,'q3'=>$term,'q4'=>$term]; }
        if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])){$where[]='a.assigned_to=:reviewer';$params['reviewer']=Auth::id();}
        $rows=$db->all("SELECT a.application_number,CONCAT(u.first_name,' ',u.last_name) AS applicant,u.email,u.mobile,ac.name AS cycle,ap.category,a.eligibility_status,a.status,p.name AS selected_program,(SELECT py.status FROM payments py WHERE py.application_id=a.id ORDER BY py.id DESC LIMIT 1) AS payment_status,a.submitted_at FROM applications a JOIN users u ON u.id=a.user_id JOIN admission_cycles ac ON ac.id=a.admission_cycle_id LEFT JOIN applicant_profiles ap ON ap.user_id=a.user_id LEFT JOIN cycle_programs cp ON cp.id=a.selected_cycle_program_id LEFT JOIN programs p ON p.id=cp.program_id WHERE ".implode(' AND ',$where).' ORDER BY COALESCE(a.submitted_at,a.created_at) DESC',$params);
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="applications-'.date('Y-m-d').'.csv"');
        $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['Application No.','Applicant','Email','Mobile','Cycle','Category','Eligibility','Status','Selected programme','Payment','Submitted']);
        $safe=static function(mixed $value): string { $value=(string)$value; return preg_match('/^[=+\-@\t\r]/',$value)?"'".$value:$value; };
        foreach($rows as $row) fputcsv($out,array_map($safe,array_values($row))); fclose($out); exit;
    }

    private function assertApplicationAccess(int $applicationId): array
    {
        $application=Database::get()->fetch('SELECT id,assigned_to FROM applications WHERE id=:id AND deleted_at IS NULL',['id'=>$applicationId]);
        if(!$application){http_response_code(404);exit('Application not found.');}
        if(Auth::hasRole('reviewer')&&!Auth::hasRole(['super-admin','admission-officer','principal'])&&(int)$application['assigned_to']!==Auth::id()){http_response_code(403);exit('Access denied.');}
        return $application;
    }

}
