<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;
use App\Services\AdmissionCycleService;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $db=Database::get(); $cycles=$db->all('SELECT id,name,status,starts_at,ends_at,closing_soon_hours FROM admission_cycles ORDER BY starts_at DESC');
        foreach($cycles as &$cycle)$cycle['effective_status']=(new AdmissionCycleService())->effectiveStatus($cycle);unset($cycle);
        $selectedCycle=(int)($_GET['cycle']??($cycles[0]['id']??0));
        if($selectedCycle&&!in_array($selectedCycle,array_map(fn($c)=>(int)$c['id'],$cycles),true))$selectedCycle=(int)($cycles[0]['id']??0);
        $scope=$selectedCycle?' WHERE admission_cycle_id=:cycle':''; $params=$selectedCycle?['cycle'=>$selectedCycle]:[];
        $count=fn(string $condition='1=1'): int=>(int)$db->scalar('SELECT COUNT(*) FROM applications'.($selectedCycle?' WHERE admission_cycle_id=:cycle AND ':' WHERE ').$condition,$params);
        $stats=['total'=>$count(),'submitted'=>$count("status IN ('submitted','resubmitted','eligibility_check','under_review')"),'corrections'=>$count("status='correction_required'"),'selected'=>$count("status IN ('approved','selected','payment_pending','fee_verified','admitted')"),'pending_docs'=>(int)$db->scalar("SELECT COUNT(*) FROM application_documents ad JOIN applications a ON a.id=ad.application_id WHERE ad.status='pending'".($selectedCycle?' AND a.admission_cycle_id=:cycle':''),$params),'pending_payments'=>(int)$db->scalar("SELECT COUNT(*) FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.status='pending'".($selectedCycle?' AND a.admission_cycle_id=:cycle':''),$params),'open_tickets'=>(int)$db->scalar("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")];
        $byStatus=$db->all('SELECT status,COUNT(*) AS total FROM applications'.$scope.' GROUP BY status ORDER BY total DESC',$params);
        $recent=$db->all("SELECT a.*,CONCAT(u.first_name,' ',u.last_name) AS applicant_name,u.email,ac.name AS cycle_name FROM applications a JOIN users u ON u.id=a.user_id JOIN admission_cycles ac ON ac.id=a.admission_cycle_id".($selectedCycle?' WHERE a.admission_cycle_id=:cycle':'')." ORDER BY COALESCE(a.submitted_at,a.created_at) DESC LIMIT 8",$params);
        $queue=$db->all("SELECT a.id,a.application_number,a.status,a.submitted_at,CONCAT(u.first_name,' ',u.last_name) AS applicant_name,DATEDIFF(CURRENT_DATE,DATE(a.submitted_at)) AS waiting_days FROM applications a JOIN users u ON u.id=a.user_id WHERE a.status IN ('submitted','resubmitted','eligibility_check','under_review','correction_required')".($selectedCycle?' AND a.admission_cycle_id=:cycle':'')." ORDER BY a.submitted_at LIMIT 6",$params);
        $seat=$db->fetch("SELECT COALESCE(SUM(cp.seat_capacity),0) AS capacity,COALESCE(SUM((SELECT COUNT(*) FROM seat_allocations sa WHERE sa.cycle_program_id=cp.id AND sa.is_active=1)),0) AS offered,COALESCE(SUM((SELECT COUNT(*) FROM seat_allocations sa WHERE sa.cycle_program_id=cp.id AND sa.is_active=1 AND sa.status='confirmed')),0) AS enrolled FROM cycle_programs cp".($selectedCycle?' WHERE cp.admission_cycle_id=:cycle':''),$params);
        $cycleName='All cycles'; foreach($cycles as $cycle)if((int)$cycle['id']===$selectedCycle)$cycleName=$cycle['name'];
        $this->view('admin/dashboard',compact('stats','byStatus','recent','queue','seat','cycles','selectedCycle','cycleName')+['title'=>'Admissions overview'],'admin');
    }
}
