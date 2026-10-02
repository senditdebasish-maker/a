<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Validator;
use App\Services\AdmissionCycleService;
use App\Services\AuditService;
use App\Services\UploadService;
use RuntimeException;
use Throwable;

final class AdmissionController extends Controller
{
    public function index(): void
    {
        $db=Database::get(); $service=new AdmissionCycleService();
        $cycles=$db->all("SELECT ac.*,ses.name AS session_name,
            (SELECT COUNT(*) FROM cycle_programs cp WHERE cp.admission_cycle_id=ac.id) AS program_count,
            (SELECT COUNT(*) FROM applications a WHERE a.admission_cycle_id=ac.id) AS application_count,
            (SELECT COUNT(*) FROM applications a WHERE a.admission_cycle_id=ac.id AND a.status='admitted') AS admitted_count,
            (SELECT COALESCE(SUM(cp.seat_capacity),0) FROM cycle_programs cp WHERE cp.admission_cycle_id=ac.id) AS capacity
            FROM admission_cycles ac JOIN academic_sessions ses ON ses.id=ac.academic_session_id ORDER BY ac.starts_at DESC,ac.id DESC");
        foreach($cycles as &$cycle) { $cycle['effective_status']=$service->effectiveStatus($cycle); $cycle['readiness']=$service->readiness((int)$cycle['id']); } unset($cycle);
        $stats=['cycles'=>count($cycles),'live'=>count(array_filter($cycles,fn($c)=>in_array($c['effective_status'],['live','closing_soon'],true))),'applications'=>(int)$db->scalar('SELECT COUNT(*) FROM applications'),'pending'=>(int)$db->scalar("SELECT COUNT(*) FROM applications WHERE status IN ('submitted','resubmitted','eligibility_check','under_review','correction_required')")];
        $programs=$db->all("SELECT p.*,d.name AS department_name,(SELECT COUNT(*) FROM cycle_programs cp WHERE cp.program_id=p.id) AS cycle_count FROM programs p LEFT JOIN departments d ON d.id=p.department_id ORDER BY p.sort_order,p.name");
        $this->view('admin/admissions/index',compact('cycles','stats','programs')+['title'=>'Admission management'],'admin');
    }

    public function createProgram(): never
    {
        $db=Database::get();$name=trim((string)($_POST['name']??''));$code=strtoupper(trim((string)($_POST['code']??'')));
        try {
            if($name===''||!preg_match('/^[A-Z0-9-]{2,20}$/',$code)||$db->scalar('SELECT COUNT(*) FROM programs WHERE code=:code',['code'=>$code])) throw new RuntimeException('Enter a unique programme name and 2–20 character code.');
            $slug=$this->slug($name).'-'.strtolower($code);$department=(int)$db->scalar('SELECT id FROM departments ORDER BY id LIMIT 1');
            $id=$db->insert('programs',['department_id'=>$department?:null,'name'=>$name,'code'=>$code,'slug'=>$slug,'award_type'=>trim((string)($_POST['award_type']??'Undergraduate Degree')),'duration_years'=>max(.5,(float)($_POST['duration_years']??4)),'total_semesters'=>max(1,(int)($_POST['total_semesters']??8)),'summary'=>trim((string)($_POST['summary']??'')),'description'=>'','eligibility_summary'=>'','career_summary'=>'','image_path'=>'assets/images/research-lab.jpg','status'=>'active','sort_order'=>100,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            AuditService::log('program_created','program',$id,[],['name'=>$name,'code'=>$code]);Flash::set('success','Programme added to the catalogue. It can now be assigned to a draft cycle.');
        } catch(Throwable $e){Flash::set('warning',$e instanceof RuntimeException?$e->getMessage():'The programme could not be created.');}
        $this->redirect('admin/admissions');
    }

    public function create(): void
    {
        $sessions=Database::get()->all('SELECT * FROM academic_sessions ORDER BY starts_on DESC');
        $this->view('admin/admissions/form',compact('sessions')+['cycle'=>null,'title'=>'Create admission cycle'],'admin');
    }

    public function store(): never
    {
        try {
            $data=$this->cycleData(); $db=Database::get();
            if ($db->fetch('SELECT id FROM admission_cycles WHERE code=:code OR slug=:slug',['code'=>$data['code'],'slug'=>$data['slug']])) throw new RuntimeException('Cycle code and slug must be unique.');
            $id=$db->insert('admission_cycles',$data+['status'=>'draft','configuration_version'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            foreach([['personal','Personal details',10],['address','Address',20],['guardian','Parent / guardian',30],['academic','Academic history',40],['preferences','Programme preferences',50]] as [$key,$title,$sort]) $db->insert('admission_form_sections',['admission_cycle_id'=>$id,'section_key'=>$key,'title'=>$title,'description'=>null,'sort_order'=>$sort,'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            AuditService::log('admission_cycle_created','admission_cycle',$id,[],$data);
            Flash::set('success','Draft admission cycle created. Configure every section before publishing.');
            $this->redirect('admin/admissions/'.$id);
        } catch(RuntimeException $exception) { Flash::withInput($_POST); Flash::set('warning',$exception->getMessage()); $this->redirect('admin/admissions/create'); }
    }

    public function show(string $id): void
    {
        $db=Database::get(); $service=new AdmissionCycleService();
        $cycle=$db->fetch("SELECT ac.*,ses.name AS session_name FROM admission_cycles ac JOIN academic_sessions ses ON ses.id=ac.academic_session_id WHERE ac.id=:id",['id'=>(int)$id]);
        if(!$cycle){ http_response_code(404); $this->view('errors/404',['title'=>'Admission cycle not found'],'admin'); return; }
        $cycle['effective_status']=$service->effectiveStatus($cycle); $readiness=$service->readiness((int)$id);
        $programs=$db->all("SELECT cp.*,p.name,p.code,p.status AS program_status,(SELECT COALESCE(SUM(sm.seats),0) FROM seat_matrix sm WHERE sm.cycle_program_id=cp.id) AS matrix_total,(SELECT COUNT(*) FROM applications a JOIN application_preferences pref ON pref.application_id=a.id WHERE pref.cycle_program_id=cp.id) AS applicants FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle ORDER BY p.sort_order,p.name",['cycle'=>(int)$id]);
        foreach($programs as &$program){ $program['seats']=$db->all('SELECT * FROM seat_matrix WHERE cycle_program_id=:id ORDER BY category,quota',['id'=>$program['id']]); $program['eligibility']=$db->all('SELECT * FROM eligibility_rules WHERE cycle_program_id=:id ORDER BY sort_order,id',['id'=>$program['id']]); $program['fees']=$db->all('SELECT * FROM admission_fee_rules WHERE cycle_program_id=:id ORDER BY fee_type,category_code,id',['id'=>$program['id']]); } unset($program);
        $sessions=$db->all('SELECT * FROM academic_sessions ORDER BY starts_on DESC');
        $availablePrograms=$db->all("SELECT * FROM programs WHERE status='active' AND id NOT IN (SELECT program_id FROM cycle_programs WHERE admission_cycle_id=:cycle) ORDER BY name",['cycle'=>(int)$id]);
        $sections=$db->all('SELECT * FROM admission_form_sections WHERE admission_cycle_id=:cycle ORDER BY sort_order,id',['cycle'=>(int)$id]);
        $fields=$db->all('SELECT * FROM admission_form_fields WHERE admission_cycle_id=:cycle ORDER BY section_id,sort_order,id',['cycle'=>(int)$id]);
        $requirements=$db->all("SELECT cdr.*,dt.name,dt.code,p.name AS program_name FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id LEFT JOIN programs p ON p.id=cdr.program_id WHERE cdr.admission_cycle_id=:cycle ORDER BY cdr.sort_order,cdr.id",['cycle'=>(int)$id]);
        $documentTypes=$db->all("SELECT * FROM document_types WHERE status='active' ORDER BY sort_order,name");
        $categories=$db->all("SELECT * FROM admission_categories WHERE status='active' ORDER BY sort_order,name");
        $versions=$db->all("SELECT acv.*,CONCAT(u.first_name,' ',u.last_name) AS creator_name FROM admission_configuration_versions acv LEFT JOIN users u ON u.id=acv.created_by WHERE acv.admission_cycle_id=:cycle ORDER BY version_no DESC",['cycle'=>(int)$id]);
        $stats=['applications'=>(int)$db->scalar('SELECT COUNT(*) FROM applications WHERE admission_cycle_id=:cycle',['cycle'=>(int)$id]),'submitted'=>(int)$db->scalar("SELECT COUNT(*) FROM applications WHERE admission_cycle_id=:cycle AND status<>'draft'",['cycle'=>(int)$id]),'admitted'=>(int)$db->scalar("SELECT COUNT(*) FROM applications WHERE admission_cycle_id=:cycle AND status='admitted'",['cycle'=>(int)$id])];
        $this->view('admin/admissions/show',compact('cycle','readiness','programs','sessions','availablePrograms','sections','fields','requirements','documentTypes','categories','versions','stats')+['title'=>$cycle['name']],'admin');
    }

    public function preview(string $id): void
    {
        $db=Database::get(); $cycle=$db->fetch("SELECT ac.*,ses.name AS session_name FROM admission_cycles ac JOIN academic_sessions ses ON ses.id=ac.academic_session_id WHERE ac.id=:id",['id'=>(int)$id]);
        if(!$cycle){ http_response_code(404); $this->view('errors/404',['title'=>'Admission cycle not found']); return; }
        $cycle['effective_status']='preview';
        $programs=$db->all("SELECT cp.*,p.name,p.code,p.slug AS program_slug,p.summary,p.duration_years,p.award_type,(SELECT COALESCE(SUM(sm.seats-sm.filled_seats),0) FROM seat_matrix sm WHERE sm.cycle_program_id=cp.id) AS available_seats FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle AND cp.status='active' ORDER BY p.sort_order,p.name",['cycle'=>$cycle['id']]);
        $requirements=$db->all("SELECT dt.name,dt.description,dt.allowed_mimes,dt.max_size_mb,cdr.is_required,cdr.stage,p.name AS program_name,cdr.category FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id LEFT JOIN programs p ON p.id=cdr.program_id WHERE cdr.admission_cycle_id=:cycle ORDER BY cdr.sort_order",['cycle'=>$cycle['id']]);
        $this->view('public/admission-detail',compact('cycle','programs','requirements')+['title'=>'Preview: '.$cycle['name']]);
    }

    public function update(string $id): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $data=$this->cycleData(); $db=Database::get();
            if($db->fetch('SELECT id FROM admission_cycles WHERE (code=:code OR slug=:slug) AND id<>:id',['code'=>$data['code'],'slug'=>$data['slug'],'id'=>$cycle['id']])) throw new RuntimeException('Cycle code and slug must be unique.');
            $stored=null;
            if(!empty($_FILES['prospectus']['name'])) { $stored=(new UploadService())->store($_FILES['prospectus'],'prospectuses/'.$cycle['id'],['application/pdf'],10); $data+=['prospectus_path'=>$stored['path'],'prospectus_original_name'=>$stored['original_name'],'prospectus_mime_type'=>$stored['mime_type']]; }
            $db->update('admission_cycles',$data+['updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$cycle['id']]);
            AuditService::log('admission_cycle_updated','admission_cycle',$cycle['id'],$cycle,$data);
            Flash::set('success','Admission cycle settings saved.');
        } catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id);
    }

    public function publish(string $id): never
    {
        try { $result=(new AdmissionCycleService())->publish((int)$id,(int)Auth::id()); Flash::set('success','Cycle published with immutable configuration version '.$result['version_no'].'.'); }
        catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id);
    }

    public function close(string $id): never
    {
        try { (new AdmissionCycleService())->close((int)$id,(int)Auth::id()); Flash::set('success','Cycle closed to new applications. Existing reviews remain available.'); }
        catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id);
    }

    public function archive(string $id): never
    {
        try { (new AdmissionCycleService())->archive((int)$id,(int)Auth::id()); Flash::set('success','Closed cycle archived.'); }
        catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id);
    }

    public function duplicate(string $id): never
    {
        try { $new=(new AdmissionCycleService())->duplicate((int)$id,$_POST,(int)Auth::id()); Flash::set('success','Cycle duplicated as a draft without applications or allocations.'); $this->redirect('admin/admissions/'.$new); }
        catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); $this->redirect('admin/admissions/'.$id); }
    }

    public function addProgram(string $id): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $programId=(int)($_POST['program_id']??0); $capacity=(int)($_POST['seat_capacity']??0);
            if($capacity<1) throw new RuntimeException('Seat capacity must be positive.');
            $db=Database::get(); $program=$db->fetch("SELECT id FROM programs WHERE id=:id AND status='active'",['id'=>$programId]); if(!$program) throw new RuntimeException('Select an active programme.');
            $cpId=$db->transaction(function(Database $db) use($cycle,$programId,$capacity): int {
                $cp=$db->insert('cycle_programs',['admission_cycle_id'=>$cycle['id'],'program_id'=>$programId,'seat_capacity'=>$capacity,'application_fee'=>(float)($_POST['application_fee']??0),'admission_fee'=>(float)($_POST['admission_fee']??0),'minimum_marks_general'=>($_POST['minimum_marks_general']??'')!==''?(float)$_POST['minimum_marks_general']:null,'minimum_marks_reserved'=>($_POST['minimum_marks_reserved']??'')!==''?(float)$_POST['minimum_marks_reserved']:null,'min_age'=>($_POST['min_age']??'')!==''?(int)$_POST['min_age']:null,'max_age'=>($_POST['max_age']??'')!==''?(int)$_POST['max_age']:null,'accepted_entrance_exams'=>trim((string)($_POST['accepted_entrance_exams']??'')),'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                $db->insert('seat_matrix',['cycle_program_id'=>$cp,'category'=>'General','quota'=>'state','seats'=>$capacity,'filled_seats'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                foreach([['application_fee','Application fee',(float)($_POST['application_fee']??0)],['admission_fee','Admission fee',(float)($_POST['admission_fee']??0)]] as [$type,$label,$amount]) $db->insert('admission_fee_rules',['cycle_program_id'=>$cp,'category_code'=>null,'fee_type'=>$type,'label'=>$label,'amount'=>$amount,'currency'=>'INR','due_at'=>null,'late_fee_amount'=>0,'refund_policy'=>null,'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                return $cp;
            });
            AuditService::log('cycle_program_added','cycle_program',$cpId,[],['cycle_id'=>$cycle['id'],'program_id'=>$programId]); Flash::set('success','Programme added with default fee and seat rows.');
        } catch(\Throwable $exception){ Flash::set('warning',$exception instanceof RuntimeException?$exception->getMessage():'Programme is already assigned to this cycle.'); }
        $this->redirect('admin/admissions/'.$id.'#programmes');
    }

    public function saveProgram(string $id, string $programId): never
    {
        try {
            $cycle=$this->draftCycle((int)$id);$db=Database::get();$program=$db->fetch('SELECT * FROM cycle_programs WHERE id=:program AND admission_cycle_id=:cycle',['program'=>(int)$programId,'cycle'=>$cycle['id']]);
            if(!$program)throw new RuntimeException('Cycle programme not found.');
            $general=($_POST['minimum_marks_general']??'')!==''?(float)$_POST['minimum_marks_general']:null;$reserved=($_POST['minimum_marks_reserved']??'')!==''?(float)$_POST['minimum_marks_reserved']:null;
            if(($general!==null&&($general<0||$general>100))||($reserved!==null&&($reserved<0||$reserved>100)))throw new RuntimeException('Minimum marks must be between 0 and 100.');
            $minAge=($_POST['min_age']??'')!==''?(int)$_POST['min_age']:null;$maxAge=($_POST['max_age']??'')!==''?(int)$_POST['max_age']:null;if($minAge!==null&&$maxAge!==null&&$maxAge<$minAge)throw new RuntimeException('Maximum age cannot be below minimum age.');
            $data=['minimum_marks_general'=>$general,'minimum_marks_reserved'=>$reserved,'min_age'=>$minAge,'max_age'=>$maxAge,'accepted_entrance_exams'=>trim((string)($_POST['accepted_entrance_exams']??''))?:null,'status'=>in_array(($_POST['status']??'active'),['active','inactive'],true)?($_POST['status']??'active'):'active','updated_at'=>date('Y-m-d H:i:s')];
            $db->update('cycle_programs',$data,'id=:id',['id'=>$program['id']]);AuditService::log('cycle_program_updated','cycle_program',$program['id'],$program,$data);Flash::set('success','Programme eligibility defaults and availability saved.');
        }catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/admissions/'.$id.'#programme-'.$programId);
    }

    public function deleteProgram(string $id, string $programId): never
    {
        try{
            $cycle=$this->draftCycle((int)$id);$db=Database::get();$program=$db->fetch('SELECT cp.*,p.name FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.id=:program AND cp.admission_cycle_id=:cycle',['program'=>(int)$programId,'cycle'=>$cycle['id']]);if(!$program)throw new RuntimeException('Cycle programme not found.');
            if((int)$db->scalar('SELECT COUNT(*) FROM application_preferences WHERE cycle_program_id=:program',['program'=>$program['id']])>0)throw new RuntimeException('This programme already has application preferences and cannot be removed.');
            $db->transaction(function(Database $db)use($program,$cycle):void{$db->query('DELETE FROM cycle_document_requirements WHERE admission_cycle_id=:cycle AND program_id=:program',['cycle'=>$cycle['id'],'program'=>$program['program_id']]);$db->query('DELETE FROM cycle_programs WHERE id=:id',['id'=>$program['id']]);});AuditService::log('cycle_program_removed','cycle_program',$program['id'],$program,[]);Flash::set('success','Programme and its draft rules, seats and fees were removed from the cycle.');
        }catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/admissions/'.$id.'#programmes');
    }

    public function saveSeats(string $id, string $programId): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $db=Database::get(); $cp=$db->fetch('SELECT * FROM cycle_programs WHERE id=:program AND admission_cycle_id=:cycle',['program'=>(int)$programId,'cycle'=>$cycle['id']]); if(!$cp) throw new RuntimeException('Cycle programme not found.');
            $rows=(array)($_POST['seats']??[]); if(!$rows) throw new RuntimeException('Seat rows are required.');$capacity=(int)($_POST['seat_capacity']??$cp['seat_capacity']);if($capacity<1)throw new RuntimeException('Programme capacity must be positive.');
            $db->transaction(function(Database $db) use($rows,$cp,$capacity): void {
                $total=0; $updates=[];
                foreach($rows as $seatId=>$value){ $seat=$db->fetch('SELECT * FROM seat_matrix WHERE id=:id AND cycle_program_id=:program FOR UPDATE',['id'=>(int)$seatId,'program'=>$cp['id']]); if(!$seat) throw new RuntimeException('Invalid seat matrix row.'); $value=(int)$value; if($value<(int)$seat['filled_seats']) throw new RuntimeException('Seats cannot be below active allocations.'); $total+=$value; $updates[]=[$seat,$value]; }
                $newCategory=trim((string)($_POST['new_category']??'')); $newQuota=trim((string)($_POST['new_quota']??'state')); $newSeats=(int)($_POST['new_seats']??0);
                if($newCategory!==''&&$newSeats>0){ if(!(int)$db->scalar("SELECT COUNT(*) FROM admission_categories WHERE code=:code AND status='active'",['code'=>$newCategory])) throw new RuntimeException('Select a valid category for the new seat row.'); if($db->fetch('SELECT id FROM seat_matrix WHERE cycle_program_id=:program AND category=:category AND quota=:quota',['program'=>$cp['id'],'category'=>$newCategory,'quota'=>$newQuota])) throw new RuntimeException('That category and quota row already exists.'); $total+=$newSeats; }
                if($total!==$capacity) throw new RuntimeException('Category seat total must equal the programme capacity entered above.');
                foreach($updates as [$seat,$value]) $db->update('seat_matrix',['seats'=>$value,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$seat['id']]);
                if($newCategory!==''&&$newSeats>0) $db->insert('seat_matrix',['cycle_program_id'=>$cp['id'],'category'=>$newCategory,'quota'=>$newQuota,'seats'=>$newSeats,'filled_seats'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                $db->update('cycle_programs',['seat_capacity'=>$capacity,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$cp['id']]);
            });
            AuditService::log('seat_matrix_updated','cycle_program',$cp['id'],[],['seat_total'=>$capacity]); Flash::set('success','Seat matrix saved.');
        } catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id.'#seats');
    }

    public function deleteSeat(string $id,string $programId,string $seatId): never
    {
        try{$cycle=$this->draftCycle((int)$id);$db=Database::get();$seat=$db->fetch('SELECT sm.* FROM seat_matrix sm JOIN cycle_programs cp ON cp.id=sm.cycle_program_id WHERE sm.id=:seat AND sm.cycle_program_id=:program AND cp.admission_cycle_id=:cycle',['seat'=>(int)$seatId,'program'=>(int)$programId,'cycle'=>$cycle['id']]);if(!$seat)throw new RuntimeException('Seat row not found.');if((int)$seat['filled_seats']>0)throw new RuntimeException('A seat row with active allocations cannot be removed.');$remaining=(int)$db->scalar('SELECT COALESCE(SUM(seats),0) FROM seat_matrix WHERE cycle_program_id=:program AND id<>:seat',['program'=>(int)$programId,'seat'=>(int)$seatId]);if($remaining<1)throw new RuntimeException('A programme must retain at least one positive seat row.');$db->transaction(function(Database $db)use($seat,$programId,$remaining):void{$db->query('DELETE FROM seat_matrix WHERE id=:id',['id'=>$seat['id']]);$db->update('cycle_programs',['seat_capacity'=>$remaining,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>(int)$programId]);});AuditService::log('seat_matrix_row_removed','seat_matrix',$seat['id'],$seat,[]);Flash::set('success','Seat row removed and programme capacity recalculated.');}catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}$this->redirect('admin/admissions/'.$id.'#programme-'.$programId);
    }

    public function saveEligibility(string $id, string $programId): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $db=Database::get(); $cp=$db->fetch('SELECT id FROM cycle_programs WHERE id=:program AND admission_cycle_id=:cycle',['program'=>(int)$programId,'cycle'=>$cycle['id']]); if(!$cp) throw new RuntimeException('Cycle programme not found.');
            $operator=(string)($_POST['operator']??''); if(!in_array($operator,['eq','neq','gt','gte','lt','lte','in','not_in','contains','between','regex'],true)) throw new RuntimeException('Invalid eligibility operator.');$ruleType=trim((string)($_POST['rule_type']??'custom'));if(!in_array($ruleType,['marks','subject','age','category','entrance','custom'],true))throw new RuntimeException('Invalid eligibility rule type.');
            $data=['cycle_program_id'=>$cp['id'],'rule_type'=>$ruleType,'field_name'=>trim((string)($_POST['field_name']??'')),'operator'=>$operator,'comparison_value'=>trim((string)($_POST['comparison_value']??'')),'message'=>trim((string)($_POST['message']??'')),'is_blocking'=>isset($_POST['is_blocking'])?1:0,'sort_order'=>(int)($_POST['sort_order']??0)];
            if($data['field_name']===''||$data['comparison_value']===''||$data['message']==='') throw new RuntimeException('Field, comparison value and message are required.');
            $ruleId=(int)($_POST['rule_id']??0); if($ruleId){ if(!(int)$db->scalar('SELECT COUNT(*) FROM eligibility_rules WHERE id=:id AND cycle_program_id=:program',['id'=>$ruleId,'program'=>$cp['id']])) throw new RuntimeException('Eligibility rule not found.'); $db->update('eligibility_rules',$data,'id=:id',['id'=>$ruleId]); } else $ruleId=$db->insert('eligibility_rules',$data+['created_at'=>date('Y-m-d H:i:s')]);
            AuditService::log('eligibility_rule_saved','eligibility_rule',$ruleId,[],$data); Flash::set('success','Eligibility rule saved.');
        } catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id.'#eligibility');
    }

    public function deleteEligibility(string $id,string $programId,string $ruleId): never
    {
        try{$cycle=$this->draftCycle((int)$id);$db=Database::get();$rule=$db->fetch('SELECT er.* FROM eligibility_rules er JOIN cycle_programs cp ON cp.id=er.cycle_program_id WHERE er.id=:rule AND er.cycle_program_id=:program AND cp.admission_cycle_id=:cycle',['rule'=>(int)$ruleId,'program'=>(int)$programId,'cycle'=>$cycle['id']]);if(!$rule)throw new RuntimeException('Eligibility rule not found.');$db->query('DELETE FROM eligibility_rules WHERE id=:id',['id'=>$rule['id']]);AuditService::log('eligibility_rule_removed','eligibility_rule',$rule['id'],$rule,[]);Flash::set('success','Eligibility rule removed. Publication readiness will require another rule if none remain.');}catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}$this->redirect('admin/admissions/'.$id.'#programme-'.$programId);
    }

    public function saveSection(string $id): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $db=Database::get(); $key=$this->slug((string)($_POST['section_key']??''),'_'); $title=trim((string)($_POST['title']??'')); if($key===''||$title==='') throw new RuntimeException('Section key and title are required.');
            $sectionId=(int)($_POST['section_id']??0); $data=['admission_cycle_id'=>$cycle['id'],'section_key'=>$key,'title'=>$title,'description'=>trim((string)($_POST['description']??'')),'sort_order'=>(int)($_POST['sort_order']??0),'status'=>in_array(($_POST['status']??'active'),['active','inactive'],true)?($_POST['status']??'active'):'active','updated_at'=>date('Y-m-d H:i:s')];
            if($sectionId){ if(!(int)$db->scalar('SELECT COUNT(*) FROM admission_form_sections WHERE id=:id AND admission_cycle_id=:cycle',['id'=>$sectionId,'cycle'=>$cycle['id']])) throw new RuntimeException('Form section not found.'); $db->update('admission_form_sections',$data,'id=:id',['id'=>$sectionId]); } else $sectionId=$db->insert('admission_form_sections',$data+['created_at'=>date('Y-m-d H:i:s')]);
            AuditService::log('admission_form_section_saved','admission_form_section',$sectionId,[],$data); Flash::set('success','Form section saved.');
        } catch(\Throwable $exception){ Flash::set('warning',$exception instanceof RuntimeException?$exception->getMessage():'Section key must be unique in this cycle.'); }
        $this->redirect('admin/admissions/'.$id.'#form-builder');
    }

    public function deleteSection(string $id,string $sectionId): never
    {
        try{$cycle=$this->draftCycle((int)$id);$db=Database::get();$section=$db->fetch('SELECT * FROM admission_form_sections WHERE id=:section AND admission_cycle_id=:cycle',['section'=>(int)$sectionId,'cycle'=>$cycle['id']]);if(!$section)throw new RuntimeException('Form section not found.');if((int)$db->scalar('SELECT COUNT(*) FROM admission_form_fields WHERE section_id=:section',['section'=>$section['id']])>0)throw new RuntimeException('Move or remove the fields in this section before deleting it.');$db->query('DELETE FROM admission_form_sections WHERE id=:id',['id'=>$section['id']]);AuditService::log('admission_form_section_removed','admission_form_section',$section['id'],$section,[]);Flash::set('success','Empty form section removed.');}catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}$this->redirect('admin/admissions/'.$id.'#form-builder');
    }

    public function saveField(string $id): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $db=Database::get(); $sectionId=(int)($_POST['section_id']??0); if(!(int)$db->scalar('SELECT COUNT(*) FROM admission_form_sections WHERE id=:id AND admission_cycle_id=:cycle',['id'=>$sectionId,'cycle'=>$cycle['id']])) throw new RuntimeException('Select a valid form section.');
            $type=(string)($_POST['field_type']??'text'); $types=['text','textarea','email','tel','number','date','select','radio','checkbox','multiselect','file']; if(!in_array($type,$types,true)) throw new RuntimeException('Invalid field type.');
            $key=$this->slug((string)($_POST['field_key']??''),'_'); $label=trim((string)($_POST['label']??'')); if($key===''||$label==='') throw new RuntimeException('Field key and label are required.');
            $binding=trim((string)($_POST['canonical_binding']??''))?:null;
            if($type==='file'&&(!$binding||!str_starts_with($binding,'document:')||!(int)$db->scalar("SELECT COUNT(*) FROM document_types WHERE code=:code AND status='active'",['code'=>substr($binding,9)]))) throw new RuntimeException('File fields must bind to an active document type as document:code.');
            $conditional=trim((string)($_POST['conditional_rules']??''))?:null; if($conditional!==null&&json_decode($conditional,true)===null) throw new RuntimeException('Conditional rules must be valid JSON.');
            $options=[]; foreach(preg_split('/\R/',trim((string)($_POST['options']??'')))?:[] as $line){ if($line==='')continue; [$value,$optionLabel]=array_pad(explode('|',$line,2),2,$line); $options[trim($value)]=trim($optionLabel); }
            $data=['admission_cycle_id'=>$cycle['id'],'section_id'=>$sectionId,'field_key'=>$key,'label'=>$label,'field_type'=>$type,'canonical_binding'=>$binding,'help_text'=>trim((string)($_POST['help_text']??''))?:null,'placeholder'=>trim((string)($_POST['placeholder']??''))?:null,'default_value'=>null,'options_json'=>$options?json_encode($options,JSON_UNESCAPED_UNICODE):null,'validation_rules'=>json_encode(['required'=>isset($_POST['is_required'])],JSON_UNESCAPED_UNICODE),'conditional_rules'=>$conditional,'is_required'=>isset($_POST['is_required'])?1:0,'is_searchable'=>isset($_POST['is_searchable'])?1:0,'sort_order'=>(int)($_POST['sort_order']??0),'status'=>in_array(($_POST['status']??'active'),['active','inactive'],true)?($_POST['status']??'active'):'active','updated_at'=>date('Y-m-d H:i:s')];
            $fieldId=(int)($_POST['field_id']??0); if($fieldId){ if(!(int)$db->scalar('SELECT COUNT(*) FROM admission_form_fields WHERE id=:id AND admission_cycle_id=:cycle',['id'=>$fieldId,'cycle'=>$cycle['id']])) throw new RuntimeException('Form field not found.'); $db->update('admission_form_fields',$data,'id=:id',['id'=>$fieldId]); } else $fieldId=$db->insert('admission_form_fields',$data+['created_at'=>date('Y-m-d H:i:s')]);
            AuditService::log('admission_form_field_saved','admission_form_field',$fieldId,[],$data); Flash::set('success','Form field saved.');
        } catch(\Throwable $exception){ Flash::set('warning',$exception instanceof RuntimeException?$exception->getMessage():'Field key must be unique in this cycle.'); }
        $this->redirect('admin/admissions/'.$id.'#form-builder');
    }

    public function deleteField(string $id,string $fieldId): never
    {
        try{$cycle=$this->draftCycle((int)$id);$db=Database::get();$field=$db->fetch('SELECT * FROM admission_form_fields WHERE id=:field AND admission_cycle_id=:cycle',['field'=>(int)$fieldId,'cycle'=>$cycle['id']]);if(!$field)throw new RuntimeException('Form field not found.');if((int)$db->scalar('SELECT COUNT(*) FROM application_field_responses WHERE form_field_id=:field',['field'=>$field['id']])>0)throw new RuntimeException('This field already has applicant responses. Set it inactive instead of deleting it.');$db->query('DELETE FROM admission_form_fields WHERE id=:id',['id'=>$field['id']]);AuditService::log('admission_form_field_removed','admission_form_field',$field['id'],$field,[]);Flash::set('success','Draft form field removed.');}catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}$this->redirect('admin/admissions/'.$id.'#form-builder');
    }

    public function saveDocument(string $id): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $db=Database::get(); $typeId=(int)($_POST['document_type_id']??0); if(!(int)$db->scalar("SELECT COUNT(*) FROM document_types WHERE id=:id AND status='active'",['id'=>$typeId])) throw new RuntimeException('Select an active document type.');
            $programId=($_POST['program_id']??'')!==''?(int)$_POST['program_id']:null; if($programId&&!(int)$db->scalar('SELECT COUNT(*) FROM cycle_programs WHERE admission_cycle_id=:cycle AND program_id=:program',['cycle'=>$cycle['id'],'program'=>$programId])) throw new RuntimeException('Document programme is not assigned to this cycle.');
            $category=trim((string)($_POST['category']??''))?:null; if($category&&!(int)$db->scalar("SELECT COUNT(*) FROM admission_categories WHERE code=:code AND status='active'",['code'=>$category])) throw new RuntimeException('Invalid document category.');
            $requirementId=(int)($_POST['requirement_id']??0);$existing=$requirementId?$db->fetch('SELECT id FROM cycle_document_requirements WHERE id=:id AND admission_cycle_id=:cycle',['id'=>$requirementId,'cycle'=>$cycle['id']]):$db->fetch('SELECT id FROM cycle_document_requirements WHERE admission_cycle_id=:cycle AND document_type_id=:type AND program_id <=> :program AND category <=> :category',['cycle'=>$cycle['id'],'type'=>$typeId,'program'=>$programId,'category'=>$category]);if($requirementId&&!$existing)throw new RuntimeException('Document requirement not found.');
            $duplicate=$db->fetch('SELECT id FROM cycle_document_requirements WHERE admission_cycle_id=:cycle AND document_type_id=:type AND program_id <=> :program AND category <=> :category AND id<>:id',['cycle'=>$cycle['id'],'type'=>$typeId,'program'=>$programId,'category'=>$category,'id'=>$existing['id']??0]);if($duplicate)throw new RuntimeException('An identical document requirement already exists.');
            $data=['admission_cycle_id'=>$cycle['id'],'document_type_id'=>$typeId,'program_id'=>$programId,'category'=>$category,'is_required'=>isset($_POST['is_required'])?1:0,'stage'=>in_array($_POST['stage']??'application',['application','admission'],true)?$_POST['stage']:'application','sort_order'=>(int)($_POST['sort_order']??0)];
            if($existing){ $db->update('cycle_document_requirements',$data,'id=:id',['id'=>$existing['id']]); $requirementId=(int)$existing['id']; } else $requirementId=$db->insert('cycle_document_requirements',$data+['created_at'=>date('Y-m-d H:i:s')]);
            AuditService::log('admission_document_requirement_saved','cycle_document_requirement',$requirementId,[],$data); Flash::set('success','Document requirement saved.');
        } catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id.'#documents');
    }

    public function deleteDocument(string $id,string $requirementId): never
    {
        try{$cycle=$this->draftCycle((int)$id);$db=Database::get();$requirement=$db->fetch('SELECT * FROM cycle_document_requirements WHERE id=:requirement AND admission_cycle_id=:cycle',['requirement'=>(int)$requirementId,'cycle'=>$cycle['id']]);if(!$requirement)throw new RuntimeException('Document requirement not found.');if((int)$db->scalar('SELECT COUNT(*) FROM application_documents ad JOIN applications a ON a.id=ad.application_id WHERE a.admission_cycle_id=:cycle AND ad.document_type_id=:type',['cycle'=>$cycle['id'],'type'=>$requirement['document_type_id']])>0)throw new RuntimeException('Applicants already uploaded this document type. Set the requirement optional instead of deleting it.');$db->query('DELETE FROM cycle_document_requirements WHERE id=:id',['id'=>$requirement['id']]);AuditService::log('admission_document_requirement_removed','cycle_document_requirement',$requirement['id'],$requirement,[]);Flash::set('success','Document requirement removed.');}catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}$this->redirect('admin/admissions/'.$id.'#documents');
    }

    public function saveFee(string $id, string $programId): never
    {
        try {
            $cycle=$this->draftCycle((int)$id); $db=Database::get(); $cp=$db->fetch('SELECT id FROM cycle_programs WHERE id=:id AND admission_cycle_id=:cycle',['id'=>(int)$programId,'cycle'=>$cycle['id']]); if(!$cp) throw new RuntimeException('Cycle programme not found.');
            $type=(string)($_POST['fee_type']??''); if(!in_array($type,['application_fee','admission_fee'],true)) throw new RuntimeException('Invalid fee type.'); $amount=(float)($_POST['amount']??-1); $late=(float)($_POST['late_fee_amount']??0); if($amount<0||$late<0) throw new RuntimeException('Fee amounts cannot be negative.');
            $category=trim((string)($_POST['category_code']??''))?:null;if($category&&!(int)$db->scalar("SELECT COUNT(*) FROM admission_categories WHERE code=:code AND status='active'",['code'=>$category]))throw new RuntimeException('Select a valid fee category.');if(($_POST['due_at']??'')!==''&&strtotime((string)$_POST['due_at'])===false)throw new RuntimeException('Enter a valid fee due date.'); $ruleId=(int)($_POST['fee_rule_id']??0);
            $data=['cycle_program_id'=>$cp['id'],'category_code'=>$category,'fee_type'=>$type,'label'=>trim((string)($_POST['label']??ucwords(str_replace('_',' ',$type)))),'amount'=>$amount,'currency'=>'INR','due_at'=>($_POST['due_at']??'')!==''?date('Y-m-d H:i:s',strtotime((string)$_POST['due_at'])):null,'late_fee_amount'=>$late,'refund_policy'=>trim((string)($_POST['refund_policy']??''))?:null,'status'=>in_array(($_POST['status']??'active'),['active','inactive'],true)?($_POST['status']??'active'):'active','updated_at'=>date('Y-m-d H:i:s')];
            if($ruleId){ if(!(int)$db->scalar('SELECT COUNT(*) FROM admission_fee_rules WHERE id=:id AND cycle_program_id=:program',['id'=>$ruleId,'program'=>$cp['id']])) throw new RuntimeException('Fee rule not found.'); $db->update('admission_fee_rules',$data,'id=:id',['id'=>$ruleId]); } else $ruleId=$db->insert('admission_fee_rules',$data+['created_at'=>date('Y-m-d H:i:s')]);
            if($category===null&&$type==='application_fee') $db->update('cycle_programs',['application_fee'=>$amount,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$cp['id']]);
            if($category===null&&$type==='admission_fee') $db->update('cycle_programs',['admission_fee'=>$amount,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$cp['id']]);
            AuditService::log('admission_fee_rule_saved','admission_fee_rule',$ruleId,[],$data); Flash::set('success','Fee rule saved.');
        } catch(RuntimeException $exception){ Flash::set('warning',$exception->getMessage()); }
        $this->redirect('admin/admissions/'.$id.'#fees');
    }

    public function deleteFee(string $id,string $programId,string $feeId): never
    {
        try{$cycle=$this->draftCycle((int)$id);$db=Database::get();$fee=$db->fetch('SELECT afr.* FROM admission_fee_rules afr JOIN cycle_programs cp ON cp.id=afr.cycle_program_id WHERE afr.id=:fee AND afr.cycle_program_id=:program AND cp.admission_cycle_id=:cycle',['fee'=>(int)$feeId,'program'=>(int)$programId,'cycle'=>$cycle['id']]);if(!$fee)throw new RuntimeException('Fee rule not found.');if((int)$db->scalar('SELECT COUNT(*) FROM application_fee_assessments WHERE fee_rule_id=:fee',['fee'=>$fee['id']])>0)throw new RuntimeException('This fee rule already has assessments and cannot be deleted. Set it inactive instead.');$db->query('DELETE FROM admission_fee_rules WHERE id=:id',['id'=>$fee['id']]);AuditService::log('admission_fee_rule_removed','admission_fee_rule',$fee['id'],$fee,[]);Flash::set('success','Draft fee rule removed.');}catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}$this->redirect('admin/admissions/'.$id.'#programme-'.$programId);
    }

    private function cycleData(): array
    {
        $validator=new Validator(); $errors=$validator->validate($_POST,['academic_session_id'=>'required|numeric','name'=>'required|max:160','code'=>'required|max:50','slug'=>'required|max:190','starts_at'=>'required|date','ends_at'=>'required|date','application_number_prefix'=>'required|max:30']);
        if($errors) throw new RuntimeException(implode(' ',array_map(fn($messages)=>$messages[0],$errors)));
        if(strtotime((string)$_POST['ends_at'])<=strtotime((string)$_POST['starts_at'])) throw new RuntimeException('Closing date must be after opening date.');
        if(!Database::get()->fetch('SELECT id FROM academic_sessions WHERE id=:id',['id'=>(int)$_POST['academic_session_id']])) throw new RuntimeException('Academic session not found.');
        return ['academic_session_id'=>(int)$_POST['academic_session_id'],'name'=>trim((string)$_POST['name']),'code'=>strtoupper(trim((string)$_POST['code'])),'slug'=>$this->slug((string)$_POST['slug']), 'summary'=>trim((string)($_POST['summary']??''))?:null,'starts_at'=>date('Y-m-d H:i:s',strtotime((string)$_POST['starts_at'])),'ends_at'=>date('Y-m-d H:i:s',strtotime((string)$_POST['ends_at'])),'correction_deadline'=>($_POST['correction_deadline']??'')!==''?date('Y-m-d H:i:s',strtotime((string)$_POST['correction_deadline'])):null,'instructions'=>trim((string)($_POST['instructions']??'')),'declaration_text'=>trim((string)($_POST['declaration_text']??'')),'application_number_prefix'=>trim((string)$_POST['application_number_prefix']),'application_fee_strategy'=>'first_preference','max_program_preferences'=>max(1,min(10,(int)($_POST['max_program_preferences']??3))),'closing_soon_hours'=>max(0,min(720,(int)($_POST['closing_soon_hours']??72)))];
    }

    private function draftCycle(int $id): array
    {
        $cycle=Database::get()->fetch('SELECT * FROM admission_cycles WHERE id=:id',['id'=>$id]);
        if(!$cycle) throw new RuntimeException('Admission cycle not found.');
        if($cycle['status']!=='draft') throw new RuntimeException('Published, closed, and archived configurations are immutable. Duplicate the cycle to create a new draft.');
        return $cycle;
    }

    private function slug(string $value,string $separator='-'): string
    {
        return strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/',$separator,$value),$separator));
    }
}
