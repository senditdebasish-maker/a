<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\MeritService;
use RuntimeException;
use Throwable;

final class MeritController extends Controller
{
    public function index(): void
    {
        $db=Database::get();
        $cycles=$db->all("SELECT * FROM admission_cycles ORDER BY academic_year DESC,id DESC");
        $cycleId=max(0,(int)($_GET['cycle']??($cycles[0]['id']??0)));
        $cycle=$cycleId?$db->fetch('SELECT * FROM admission_cycles WHERE id=:id',['id'=>$cycleId]):null;
        if(!$cycle&&$cycles){$cycle=$cycles[0];$cycleId=(int)$cycle['id'];}
        $settings=$cycle?$db->fetch('SELECT * FROM merit_cycle_settings WHERE admission_cycle_id=:cycle',['cycle'=>$cycleId]):null;
        $programs=$cycle?$db->all("SELECT cp.id,cp.seat_capacity AS total_seats,p.name,p.code,mfv.id AS formula_id,mfv.version_no,mfv.class_10_weight,mfv.class_12_weight,mfv.entrance_weight,mfv.created_at AS formula_created_at
            FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id LEFT JOIN merit_formula_versions mfv ON mfv.cycle_program_id=cp.id AND mfv.status='active'
            WHERE cp.admission_cycle_id=:cycle AND cp.status='active' ORDER BY p.name",['cycle'=>$cycleId]):[];
        $runs=$cycle?$db->all("SELECT mr.*,CONCAT(generator.first_name,' ',generator.last_name) AS generator_name,CONCAT(publisher.first_name,' ',publisher.last_name) AS publisher_name FROM merit_runs mr LEFT JOIN users generator ON generator.id=mr.generated_by LEFT JOIN users publisher ON publisher.id=mr.published_by WHERE mr.admission_cycle_id=:cycle ORDER BY mr.version_no DESC",['cycle'=>$cycleId]):[];
        $runId=max(0,(int)($_GET['run']??($runs[0]['id']??0)));
        $run=$runId?$db->fetch('SELECT * FROM merit_runs WHERE id=:id AND admission_cycle_id=:cycle',['id'=>$runId,'cycle'=>$cycleId]):null;
        if(!$run&&$runs){$run=$runs[0];$runId=(int)$run['id'];}
        $categories=$db->all("SELECT * FROM admission_categories WHERE status='active' ORDER BY sort_order,name");
        $entryProgram=(int)($_GET['program']??0);$entryCategory=trim((string)($_GET['category']??''));$entryStatus=trim((string)($_GET['result']??''));$q=trim((string)($_GET['q']??''));
        $page=max(1,(int)($_GET['page']??1));$perPage=100;$offset=($page-1)*$perPage;
        $where=['me.merit_run_id=:run'];$params=['run'=>$runId];
        if($entryProgram>0){$where[]='me.cycle_program_id=:program';$params['program']=$entryProgram;}
        if($entryCategory!==''){$where[]='me.merit_category=:category';$params['category']=$entryCategory;}
        if(in_array($entryStatus,['ranked','waitlisted','selected','not_selected','expired'],true)){$where[]='me.result_status=:result';$params['result']=$entryStatus;}
        if($q!==''){$where[]='me.application_number LIKE :q';$params['q']='%'.$q.'%';}
        $whereSql=implode(' AND ',$where);$entries=[];$total=0;
        if($run){
            $total=(int)$db->scalar("SELECT COUNT(*) FROM merit_entries me WHERE {$whereSql}",$params);
            $entries=$db->all("SELECT me.*,p.name AS program_name,p.code AS program_code,CONCAT(u.first_name,' ',u.last_name) AS applicant_name,a.status AS application_status,offer.expires_at AS offer_expires_at
                FROM merit_entries me JOIN cycle_programs cp ON cp.id=me.cycle_program_id JOIN programs p ON p.id=cp.program_id JOIN applications a ON a.id=me.application_id JOIN users u ON u.id=a.user_id
                LEFT JOIN selection_offers offer ON offer.merit_entry_id=me.id
                WHERE {$whereSql} ORDER BY p.name,me.merit_category,me.category_rank,me.application_number LIMIT {$perPage} OFFSET {$offset}",$params);
        }
        $seatRows=$cycle?$db->all("SELECT sm.*,p.name AS program_name FROM seat_matrix sm JOIN cycle_programs cp ON cp.id=sm.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle ORDER BY p.name,sm.quota,sm.category",['cycle'=>$cycleId]):[];
        $this->view('admin/merit/index',compact('cycles','cycle','settings','programs','runs','run','categories','entries','total','page','perPage','entryProgram','entryCategory','entryStatus','q','seatRows'),'admin');
    }

    public function saveSettings(string $cycleId): never
    {
        try{(new MeritService())->saveCycleSettings((int)$cycleId,(string)$this->input('reservation_policy'),(string)$this->input('default_quota'),(int)$this->input('offer_valid_hours',72),(int)Auth::id());Flash::set('success','Merit and offer settings saved.');}
        catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/merit?cycle='.(int)$cycleId.'#settings');
    }

    public function saveFormula(string $cycleProgramId): never
    {
        $db=Database::get();$program=$db->fetch('SELECT admission_cycle_id FROM cycle_programs WHERE id=:id',['id'=>(int)$cycleProgramId]);
        try{(new MeritService())->saveFormula((int)$cycleProgramId,(float)$this->input('class_10_weight'),(float)$this->input('class_12_weight'),(float)$this->input('entrance_weight'),(int)Auth::id());Flash::set('success','A new immutable merit formula version was saved.');}
        catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/merit?cycle='.(int)($program['admission_cycle_id']??0).'#formulas');
    }

    public function generate(string $cycleId): never
    {
        try{$runId=(new MeritService())->generate((int)$cycleId,(int)Auth::id());Flash::set('success','Frozen merit run generated. Review ranks before publishing.');$this->redirect('admin/merit?cycle='.(int)$cycleId.'&run='.$runId.'#rankings');}
        catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());$this->redirect('admin/merit?cycle='.(int)$cycleId.'#runs');}
    }

    public function publish(string $runId): never
    {
        $run=Database::get()->fetch('SELECT admission_cycle_id FROM merit_runs WHERE id=:id',['id'=>(int)$runId]);
        try{(new MeritService())->publish((int)$runId,(int)Auth::id());Flash::set('success','Merit run published. Applicant emails are queued and private/public results are live.');}
        catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/merit?cycle='.(int)($run['admission_cycle_id']??0).'&run='.(int)$runId.'#runs');
    }

    public function select(string $entryId): never
    {
        $db=Database::get();$entry=$db->fetch('SELECT me.merit_run_id,mr.admission_cycle_id FROM merit_entries me JOIN merit_runs mr ON mr.id=me.merit_run_id WHERE me.id=:id',['id'=>(int)$entryId]);
        try{(new MeritService())->select((int)$entryId,trim((string)$this->input('seat_category')),trim((string)$this->input('seat_quota')),max(1,(int)$this->input('offer_valid_hours',72)),(int)Auth::id());Flash::set('success','Candidate selected, seat reserved, fee assessed and deadline notification sent.');}
        catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/merit?cycle='.(int)($entry['admission_cycle_id']??0).'&run='.(int)($entry['merit_run_id']??0).'#rankings');
    }

    public function batchSelect(): never
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)$this->input('entry_ids',[])))));$cycleId=(int)$this->input('cycle_id');$runId=(int)$this->input('run_id');
        if(!$ids){Flash::set('warning','Select at least one merit row.');$this->redirect("admin/merit?cycle={$cycleId}&run={$runId}#rankings");}
        $selected=0;$errors=[];
        foreach(array_slice($ids,0,200) as $id){try{(new MeritService())->select($id,trim((string)$this->input('seat_category')),trim((string)$this->input('seat_quota')),max(1,(int)$this->input('offer_valid_hours',72)),(int)Auth::id());$selected++;}catch(Throwable $exception){$errors[]="Entry {$id}: ".$exception->getMessage();}}
        if($selected)Flash::set('success',"Selected {$selected} candidate(s). Every row was checked transactionally against live seat capacity.");
        if($errors)Flash::set('warning',implode(' ',array_slice($errors,0,5)).(count($errors)>5?' Additional rows also failed.':''));
        $this->redirect("admin/merit?cycle={$cycleId}&run={$runId}#rankings");
    }

    public function closeRemaining(string $runId): never
    {
        $run=Database::get()->fetch('SELECT admission_cycle_id FROM merit_runs WHERE id=:id',['id'=>(int)$runId]);
        try{$count=(new MeritService())->closeRemaining((int)$runId,(int)Auth::id());Flash::set('success',"Closed {$count} remaining waitlisted result(s) as not selected.");}
        catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/merit?cycle='.(int)($run['admission_cycle_id']??0).'&run='.(int)$runId.'#runs');
    }
}
