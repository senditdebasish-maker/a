<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;

final class ReportController extends Controller
{
    public function index(): void
    {
        $db=Database::get(); $cycle=(int)($_GET['cycle']??0); $program=(int)($_GET['program']??0);
        $where=['1=1'];$params=[];
        if($cycle>0){$where[]='a.admission_cycle_id=:cycle';$params['cycle']=$cycle;}
        if($program>0){$where[]='EXISTS (SELECT 1 FROM application_preferences fp WHERE fp.application_id=a.id AND fp.cycle_program_id=:program)';$params['program']=$program;}
        $scope=implode(' AND ',$where);
        $status=$db->all("SELECT a.status AS label,COUNT(*) AS total FROM applications a WHERE {$scope} GROUP BY a.status ORDER BY total DESC",$params);
        $category=$db->all("SELECT COALESCE(ap.category,'Not provided') AS label,COUNT(*) AS total FROM applications a LEFT JOIN applicant_profiles ap ON ap.user_id=a.user_id WHERE {$scope} GROUP BY ap.category ORDER BY total DESC",$params);
        $gender=$db->all("SELECT COALESCE(ap.gender,'Not provided') AS label,COUNT(*) AS total FROM applications a LEFT JOIN applicant_profiles ap ON ap.user_id=a.user_id WHERE {$scope} GROUP BY ap.gender ORDER BY total DESC",$params);
        $geography=$db->all("SELECT COALESCE(addr.state,'Not provided') AS label,COUNT(*) AS total FROM applications a LEFT JOIN applicant_addresses addr ON addr.application_id=a.id WHERE {$scope} GROUP BY addr.state ORDER BY total DESC LIMIT 12",$params);
        $daily=$db->all("SELECT DATE(a.submitted_at) AS label,COUNT(*) AS total FROM applications a WHERE a.submitted_at IS NOT NULL AND {$scope} GROUP BY DATE(a.submitted_at) ORDER BY label DESC LIMIT 30",$params);
        $reviewers=$db->all("SELECT CONCAT(u.first_name,' ',u.last_name) AS label,COUNT(a.id) AS total FROM applications a JOIN users u ON u.id=a.assigned_to WHERE {$scope} GROUP BY u.id ORDER BY total DESC",$params);
        $payments=$db->all("SELECT p.type,p.status,COUNT(*) AS records,COALESCE(SUM(p.amount),0) AS amount FROM payments p JOIN applications a ON a.id=p.application_id WHERE {$scope} GROUP BY p.type,p.status ORDER BY p.type,p.status",$params);
        $seatWhere=[];$seatParams=[];if($cycle>0){$seatWhere[]='cp.admission_cycle_id=:seat_cycle';$seatParams['seat_cycle']=$cycle;}if($program>0){$seatWhere[]='cp.id=:seat_program';$seatParams['seat_program']=$program;}
        $seats=$db->all("SELECT ac.name AS cycle_name,p.name AS program_name,sm.category,sm.quota,sm.seats,sm.filled_seats,(sm.seats-sm.filled_seats) AS available FROM seat_matrix sm JOIN cycle_programs cp ON cp.id=sm.cycle_program_id JOIN admission_cycles ac ON ac.id=cp.admission_cycle_id JOIN programs p ON p.id=cp.program_id".($seatWhere?' WHERE '.implode(' AND ',$seatWhere):'').' ORDER BY ac.starts_at DESC,p.name,sm.category',$seatParams);
        $totals=['applications'=>(int)$db->scalar("SELECT COUNT(*) FROM applications a WHERE {$scope}",$params),'submitted'=>(int)$db->scalar("SELECT COUNT(*) FROM applications a WHERE a.submitted_at IS NOT NULL AND {$scope}",$params),'admitted'=>(int)$db->scalar("SELECT COUNT(*) FROM applications a WHERE a.status='admitted' AND {$scope}",$params),'verified_revenue'=>(float)$db->scalar("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.status='verified' AND {$scope}",$params)];
        $cycles=$db->all('SELECT id,name FROM admission_cycles ORDER BY starts_at DESC');
        $programs=$db->all('SELECT cp.id,cp.admission_cycle_id,p.name,p.code FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id ORDER BY p.name');
        $this->view('admin/reports',compact('status','category','gender','geography','daily','reviewers','payments','seats','totals','cycles','programs','cycle','program')+['title'=>'Admissions reports'],'admin');
    }
}
