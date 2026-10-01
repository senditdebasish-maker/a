<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class ApplicationWorkflowService
{
    private const TRANSITIONS = [
        'draft'=>['submitted','withdrawn'],
        'submitted'=>['eligibility_check','under_review','correction_required','rejected','withdrawn'],
        'resubmitted'=>['eligibility_check','under_review','correction_required','rejected','withdrawn'],
        'eligibility_check'=>['under_review','correction_required','rejected','withdrawn'],
        'under_review'=>['correction_required','approved','selected','rejected','withdrawn'],
        'correction_required'=>['resubmitted','rejected','withdrawn'],
        'approved'=>['selected','rejected','withdrawn'],
        'selected'=>['payment_pending','fee_verified','admitted','rejected','withdrawn'],
        'payment_pending'=>['fee_verified','admitted','rejected','withdrawn'],
        'fee_verified'=>['admitted','withdrawn'],
        'rejected'=>[], 'withdrawn'=>[], 'admitted'=>[],
    ];

    public function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status]??[];
    }

    public function transition(int $applicationId, string $to, string $remarks, int $actorId, array $options=[]): array
    {
        $db=Database::get();
        $oldStatus='';
        $result=$db->transaction(function (Database $db) use ($applicationId,$to,$remarks,$actorId,$options,&$oldStatus): array {
            $application=$db->fetch('SELECT * FROM applications WHERE id=:id FOR UPDATE',['id'=>$applicationId]);
            if (!$application) throw new RuntimeException('Application not found.');
            $oldStatus=(string)$application['status'];
            if (!in_array($to,$this->allowedTransitions($oldStatus),true)) throw new RuntimeException("Transition from {$oldStatus} to {$to} is not allowed.");
            if (isset($options['status_version'])&&(int)$options['status_version']!==(int)$application['status_version']) throw new RuntimeException('This application changed in another session. Refresh before making a decision.');
            $updates=['status'=>$to,'status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')];
            if (in_array($to,['approved','selected','rejected','admitted'],true)) $updates['decision_at']=date('Y-m-d H:i:s');
            if ($to==='resubmitted') { $updates['resubmitted_at']=date('Y-m-d H:i:s'); $updates['locked_at']=date('Y-m-d H:i:s'); }
            if ($to==='correction_required') $updates['locked_at']=null;
            if ($to==='withdrawn') { $updates['withdrawn_at']=date('Y-m-d H:i:s'); $updates['withdrawal_reason']=$remarks; }
            if ($to==='selected') {
                $programId=(int)($options['cycle_program_id']??0);
                $eligibility=json_decode((string)($application['eligibility_flags']??''),true);$programEligibility=null;foreach($eligibility['programs']??[] as $result)if((int)($result['cycle_program_id']??0)===$programId)$programEligibility=$result['status']??null;
                if($programEligibility!=='eligible') throw new RuntimeException('Run eligibility checks and resolve all rules for the selected programme before allocating a seat.');
                $applicationFee=$db->fetch("SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type='application_fee' FOR UPDATE",['application'=>$applicationId]);
                if(!$applicationFee){$firstProgram=(int)$db->scalar('SELECT cycle_program_id FROM application_preferences WHERE application_id=:application ORDER BY preference_order LIMIT 1',['application'=>$applicationId]);$profileCategory=(string)($db->scalar('SELECT category FROM applicant_profiles WHERE user_id=:user',['user'=>$application['user_id']])?:'');$applicationFee=(new AdmissionFeeService())->assess($db,$applicationId,$firstProgram,'application_fee',$profileCategory,$application['configuration_version_id']?(int)$application['configuration_version_id']:null);}
                if((float)$applicationFee['total_amount']>0&&!in_array($applicationFee['status'],['paid','waived'],true)) throw new RuntimeException('The assessed application fee must be paid or formally waived before selection.');
                $profile=$db->fetch('SELECT category FROM applicant_profiles WHERE user_id=:user',['user'=>$application['user_id']]);
                $category=trim((string)($options['category']??$profile['category']??''));
                $quota=trim((string)($options['quota']??'state'));
                if ($programId<1||$category===''||$quota==='') throw new RuntimeException('Programme, seat category, and quota are required for selection.');
                (new SeatAllocationService())->allocate($db,$application,$programId,$category,$quota,$actorId);
                (new AdmissionFeeService())->assess($db,$applicationId,$programId,'admission_fee',$category,$application['configuration_version_id']?(int)$application['configuration_version_id']:null);
                $updates['selected_cycle_program_id']=$programId;
            }
            if (in_array($to,['rejected','withdrawn'],true)) (new SeatAllocationService())->release($db,$applicationId,$actorId,$remarks);
            if ($to==='admitted') {
                $programId=(int)($application['selected_cycle_program_id']??0);
                if ($programId<1) throw new RuntimeException('Select and allocate a programme before admission.');
                $assessment=$db->fetch("SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type='admission_fee' FOR UPDATE",['application'=>$applicationId]);
                if ($assessment&&(float)$assessment['total_amount']>0&&!in_array($assessment['status'],['paid','waived'],true)) throw new RuntimeException('Admission fee must be paid or formally waived before admission.');
                (new SeatAllocationService())->confirm($db,$applicationId);
                $updates['admitted_at']=date('Y-m-d H:i:s');
                if (!$db->fetch('SELECT id FROM student_enrollments WHERE application_id=:application',['application'=>$applicationId])) {
                    $number='NCP-ENR-'.date('Y').'-'.str_pad((string)$applicationId,6,'0',STR_PAD_LEFT);
                    $db->insert('student_enrollments',['application_id'=>$applicationId,'user_id'=>$application['user_id'],'cycle_program_id'=>$programId,'enrollment_number'=>$number,'university_roll_number'=>null,'status'=>'active','enrolled_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                }
            }
            $db->update('applications',$updates,'id=:id',['id'=>$applicationId]);
            $db->insert('application_status_history',['application_id'=>$applicationId,'from_status'=>$oldStatus,'to_status'=>$to,'remarks'=>$remarks,'changed_by'=>$actorId,'created_at'=>date('Y-m-d H:i:s')]);
            $db->insert('notifications',['user_id'=>$application['user_id'],'type'=>'status','title'=>'Application status updated','message'=>'Your application is now '.str_replace('_',' ',$to).($remarks!==''?'. '.$remarks:''),'action_url'=>'/student/dashboard','read_at'=>null,'created_at'=>date('Y-m-d H:i:s')]);
            return $db->fetch('SELECT * FROM applications WHERE id=:id',['id'=>$applicationId])??[];
        });
        AuditService::log('application_status_changed','application',$applicationId,['status'=>$oldStatus],['status'=>$to,'remarks'=>$remarks,'actor_id'=>$actorId]);
        return $result;
    }
}
