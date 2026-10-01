<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class AdmissionFeeService
{
    public function assess(Database $db, int $applicationId, int $cycleProgramId, string $feeType, ?string $category, ?int $configurationVersionId): array
    {
        if (!in_array($feeType,['application_fee','admission_fee'],true)) throw new RuntimeException('Unsupported admission fee type.');
        $application=$db->fetch('SELECT * FROM applications WHERE id=:id',['id'=>$applicationId]);
        $program=$db->fetch('SELECT * FROM cycle_programs WHERE id=:id',['id'=>$cycleProgramId]);
        if (!$application||!$program||(int)$program['admission_cycle_id']!==(int)$application['admission_cycle_id']) throw new RuntimeException('The fee programme does not belong to this application cycle.');
        $rule=$db->fetch("SELECT * FROM admission_fee_rules WHERE cycle_program_id=:program AND fee_type=:type AND status='active' AND category_code=:category ORDER BY id DESC LIMIT 1",['program'=>$cycleProgramId,'type'=>$feeType,'category'=>$category]);
        if (!$rule) $rule=$db->fetch("SELECT * FROM admission_fee_rules WHERE cycle_program_id=:program AND fee_type=:type AND status='active' AND category_code IS NULL ORDER BY id DESC LIMIT 1",['program'=>$cycleProgramId,'type'=>$feeType]);
        $base=(float)($rule['amount']??$program[$feeType]??0);
        if ($base<0) throw new RuntimeException('Configured fee cannot be negative.');
        $dueAt=$rule['due_at']??null;
        $late=$dueAt&&strtotime((string)$dueAt)<time()?(float)($rule['late_fee_amount']??0):0.0;
        $total=$base+$late;
        $calculation=json_encode(['strategy'=>'first_preference','rule_id'=>$rule['id']??null,'base_amount'=>$base,'late_amount'=>$late,'assessed_at'=>date(DATE_ATOM)],JSON_UNESCAPED_SLASHES);
        $existing=$db->fetch('SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type=:type FOR UPDATE',['application'=>$applicationId,'type'=>$feeType]);
        $data=['cycle_program_id'=>$cycleProgramId,'fee_rule_id'=>$rule['id']??null,'configuration_version_id'=>$configurationVersionId,'category_code'=>$category,'base_amount'=>$base,'late_amount'=>$late,'total_amount'=>$total,'currency'=>$rule['currency']??'INR','due_at'=>$dueAt,'calculation_json'=>$calculation,'updated_at'=>date('Y-m-d H:i:s')];
        if ($existing) {
            if (in_array($existing['status'],['paid','waived','refunded'],true) && abs((float)$existing['total_amount']-$total)>0.01) throw new RuntimeException('A settled fee assessment cannot be recalculated.');
            $db->update('application_fee_assessments',$data,'id=:id',['id'=>$existing['id']]);
            $id=(int)$existing['id'];
        } else {
            $id=$db->insert('application_fee_assessments',$data+['application_id'=>$applicationId,'fee_type'=>$feeType,'status'=>'due','created_at'=>date('Y-m-d H:i:s')]);
        }
        return $db->fetch('SELECT * FROM application_fee_assessments WHERE id=:id',['id'=>$id]) ?: throw new RuntimeException('Fee assessment could not be loaded.');
    }

    public function markPaid(Database $db, int $assessmentId): void
    {
        $assessment=$db->fetch('SELECT * FROM application_fee_assessments WHERE id=:id FOR UPDATE',['id'=>$assessmentId]);
        if (!$assessment) throw new RuntimeException('Fee assessment not found.');
        $db->update('application_fee_assessments',['status'=>'paid','updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$assessmentId]);
    }
}
