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
        'under_review'=>['correction_required','verified','rejected','withdrawn'],
        'correction_required'=>['resubmitted','rejected','withdrawn'],
        // approved remains supported for pre-upgrade records; all new UI uses verified.
        'approved'=>['waitlisted','rejected','withdrawn'],
        'verified'=>['waitlisted','rejected','withdrawn'],
        'waitlisted'=>['selected','not_selected','rejected','withdrawn'],
        'selected'=>['payment_pending','fee_verified','admitted','offer_expired','rejected','withdrawn'],
        'payment_pending'=>['fee_verified','admitted','offer_expired','rejected','withdrawn'],
        'fee_verified'=>['admitted','withdrawn'],
        'offer_expired'=>[], 'not_selected'=>[], 'rejected'=>[], 'withdrawn'=>[], 'admitted'=>[],
    ];

    public function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public function transition(int $applicationId, string $to, string $remarks, int $actorId, array $options=[]): array
    {
        $db=Database::get();
        $oldStatus='';
        $notificationContext=[];
        $result=$db->transaction(function (Database $db) use ($applicationId,$to,$remarks,$actorId,$options,&$oldStatus,&$notificationContext): array {
            $application=$db->fetch('SELECT * FROM applications WHERE id=:id FOR UPDATE',['id'=>$applicationId]);
            if (!$application) throw new RuntimeException('Application not found.');
            $oldStatus=(string)$application['status'];
            if (!in_array($to,$this->allowedTransitions($oldStatus),true)) throw new RuntimeException("Transition from {$oldStatus} to {$to} is not allowed.");
            if (isset($options['status_version'])&&(int)$options['status_version']!==(int)$application['status_version']) throw new RuntimeException('This application changed in another session. Refresh before making a decision.');
            if (in_array($to,['verified','approved'],true)) $this->assertVerificationGate($db,$application);

            $updates=['status'=>$to,'status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')];
            if (in_array($to,['verified','approved','selected','rejected','admitted','not_selected','offer_expired'],true)) $updates['decision_at']=date('Y-m-d H:i:s');
            if ($to==='resubmitted') { $updates['resubmitted_at']=date('Y-m-d H:i:s'); $updates['locked_at']=date('Y-m-d H:i:s'); }
            if ($to==='correction_required') $updates['locked_at']=null;
            if ($to==='withdrawn') { $updates['withdrawn_at']=date('Y-m-d H:i:s'); $updates['withdrawal_reason']=$remarks; }

            if ($to==='selected') {
                $programId=(int)($options['cycle_program_id']??0);
                $entryId=(int)($options['merit_entry_id']??0);
                $entry=$db->fetch("SELECT me.* FROM merit_entries me JOIN merit_runs mr ON mr.id=me.merit_run_id WHERE me.id=:entry AND me.application_id=:application AND me.cycle_program_id=:program AND me.result_status IN ('ranked','waitlisted') AND mr.status='published' FOR UPDATE",['entry'=>$entryId,'application'=>$applicationId,'program'=>$programId]);
                if (!$entry) throw new RuntimeException('Selection must use an eligible entry from the currently published merit workspace.');
                $eligibility=json_decode((string)($application['eligibility_flags']??''),true);$programEligibility=null;
                foreach($eligibility['programs']??[] as $programResult) if((int)($programResult['cycle_program_id']??0)===$programId) $programEligibility=$programResult['status']??null;
                if($programEligibility!=='eligible') throw new RuntimeException('Eligibility is not resolved for the selected programme.');
                $profile=$db->fetch('SELECT category FROM applicant_profiles WHERE user_id=:user',['user'=>$application['user_id']]);
                $category=trim((string)($options['category']??$entry['merit_category']??$profile['category']??''));
                $quota=trim((string)($options['quota']??$entry['quota']??'state'));
                if ($programId<1||$category===''||$quota==='') throw new RuntimeException('Programme, seat category, and quota are required for selection.');
                $allocation=(new SeatAllocationService())->allocate($db,$application,$programId,$category,$quota,$actorId);
                (new AdmissionFeeService())->assess($db,$applicationId,$programId,'admission_fee',$category,$application['configuration_version_id']?(int)$application['configuration_version_id']:null);
                $settings=$db->fetch('SELECT offer_valid_hours FROM merit_cycle_settings WHERE admission_cycle_id=:cycle',['cycle'=>$application['admission_cycle_id']]);
                $hours=max(1,min(720,(int)($options['offer_valid_hours']??$settings['offer_valid_hours']??72)));
                $expires=date('Y-m-d H:i:s',time()+$hours*3600);
                $offerNumber='NCP-OFFER-'.date('Y').'-'.str_pad((string)$entryId,7,'0',STR_PAD_LEFT);
                $offerId=$db->insert('selection_offers',[
                    'merit_entry_id'=>$entryId,'application_id'=>$applicationId,'seat_allocation_id'=>$allocation['id'],'offer_number'=>$offerNumber,
                    'status'=>'payment_due','offered_by'=>$actorId,'offered_at'=>date('Y-m-d H:i:s'),'expires_at'=>$expires,
                    'payment_received_at'=>null,'payment_verified_at'=>null,'expired_at'=>null,'closed_at'=>null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),
                ]);
                $db->update('merit_entries',['result_status'=>'selected','selected_at'=>date('Y-m-d H:i:s'),'selected_by'=>$actorId],'id=:id',['id'=>$entryId]);
                $db->query("UPDATE merit_entries SET result_status='not_selected' WHERE merit_run_id=:run AND application_id=:application AND id<>:id AND result_status IN ('ranked','waitlisted')",['run'=>$entry['merit_run_id'],'application'=>$applicationId,'id'=>$entryId]);
                $updates['selected_cycle_program_id']=$programId;
                $notificationContext=['deadline'=>$expires,'offer_id'=>$offerId];
            }

            if (in_array($to,['rejected','withdrawn','not_selected','offer_expired'],true)) (new SeatAllocationService())->release($db,$applicationId,$actorId,$remarks);
            if ($to==='offer_expired') {
                $db->query("UPDATE selection_offers SET status='expired',expired_at=NOW(),closed_at=NOW(),updated_at=NOW() WHERE application_id=:application AND status IN ('payment_due','payment_received')",['application'=>$applicationId]);
                $db->query("UPDATE merit_entries SET result_status='expired' WHERE application_id=:application AND result_status='selected'",['application'=>$applicationId]);
            }
            if ($to==='payment_pending') $db->query("UPDATE selection_offers SET status='payment_received',payment_received_at=NOW(),updated_at=NOW() WHERE application_id=:application AND status='payment_due'",['application'=>$applicationId]);
            if($to==='fee_verified'){
                $assessment=$db->fetch("SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type='admission_fee' FOR UPDATE",['application'=>$applicationId]);
                if(!$assessment||((float)$assessment['total_amount']>0&&!in_array($assessment['status'],['paid','waived'],true))) throw new RuntimeException('Admission fee must be verified or formally waived before fee verification.');
                $db->query("UPDATE selection_offers SET status='payment_verified',payment_verified_at=NOW(),updated_at=NOW() WHERE application_id=:application AND status IN ('payment_due','payment_received')",['application'=>$applicationId]);
            }
            if ($to==='admitted') {
                $programId=(int)($application['selected_cycle_program_id']??0);
                if ($programId<1) throw new RuntimeException('Select and allocate a programme before admission.');
                $assessment=$db->fetch("SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type='admission_fee' FOR UPDATE",['application'=>$applicationId]);
                if(!$assessment){$allocation=$db->fetch('SELECT category FROM seat_allocations WHERE application_id=:application AND is_active=1 FOR UPDATE',['application'=>$applicationId]);$assessment=(new AdmissionFeeService())->assess($db,$applicationId,$programId,'admission_fee',(string)($allocation['category']??''),$application['configuration_version_id']?(int)$application['configuration_version_id']:null);}
                if ((float)$assessment['total_amount']>0&&!in_array($assessment['status'],['paid','waived'],true)) throw new RuntimeException('Admission fee must be paid or formally waived before admission.');
                (new SeatAllocationService())->confirm($db,$applicationId);
                $db->query("UPDATE selection_offers SET status='admitted',closed_at=NOW(),updated_at=NOW() WHERE application_id=:application AND status='payment_verified'",['application'=>$applicationId]);
                $updates['admitted_at']=date('Y-m-d H:i:s');
                if (!$db->fetch('SELECT id FROM student_enrollments WHERE application_id=:application',['application'=>$applicationId])) {
                    $number='NCP-ENR-'.date('Y').'-'.str_pad((string)$applicationId,6,'0',STR_PAD_LEFT);
                    $db->insert('student_enrollments',['application_id'=>$applicationId,'user_id'=>$application['user_id'],'cycle_program_id'=>$programId,'enrollment_number'=>$number,'university_roll_number'=>null,'status'=>'active','enrolled_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
                }
            }
            $db->update('applications',$updates,'id=:id',['id'=>$applicationId]);
            $db->insert('application_status_history',['application_id'=>$applicationId,'from_status'=>$oldStatus,'to_status'=>$to,'remarks'=>$remarks,'changed_by'=>$actorId,'created_at'=>date('Y-m-d H:i:s')]);
            return $db->fetch('SELECT * FROM applications WHERE id=:id',['id'=>$applicationId])??[];
        });
        AuditService::log('application_status_changed','application',$applicationId,['status'=>$oldStatus],['status'=>$to,'remarks'=>$remarks,'actor_id'=>$actorId]);
        (new AdmissionNotificationService())->status($applicationId,$to,$notificationContext,empty($options['defer_notification']));
        return $result;
    }

    private function assertVerificationGate(Database $db, array $application): void
    {
        if ((string)$application['eligibility_status'] !== 'eligible') throw new RuntimeException('All blocking eligibility checks must pass before verification.');
        $openCorrections=(int)$db->scalar("SELECT COUNT(*) FROM application_corrections WHERE application_id=:application AND status='open'",['application'=>$application['id']]);
        if ($openCorrections>0) throw new RuntimeException('Resolve every open correction request before verification.');
        $category=(string)($db->scalar('SELECT category FROM applicant_profiles WHERE user_id=:user',['user'=>$application['user_id']])?:'');
        $unverified=(int)$db->scalar("SELECT COUNT(*) FROM cycle_document_requirements requirement
            WHERE requirement.admission_cycle_id=:cycle AND requirement.is_required=1 AND requirement.stage='application'
            AND (requirement.program_id IS NULL OR EXISTS (SELECT 1 FROM application_preferences preference JOIN cycle_programs cycle_program ON cycle_program.id=preference.cycle_program_id WHERE preference.application_id=:application AND cycle_program.program_id=requirement.program_id))
            AND (requirement.category IS NULL OR requirement.category=:category)
            AND NOT EXISTS (SELECT 1 FROM application_documents document WHERE document.application_id=:application_document AND document.document_type_id=requirement.document_type_id AND document.status='verified')",
            ['cycle'=>$application['admission_cycle_id'],'application'=>$application['id'],'category'=>$category,'application_document'=>$application['id']]);
        if ($unverified>0) throw new RuntimeException('Every required application-stage document must be verified before merit verification.');
        $applicationFee=$db->fetch("SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type='application_fee' FOR UPDATE",['application'=>$application['id']]);
        if(!$applicationFee){
            $firstProgram=(int)$db->scalar('SELECT cycle_program_id FROM application_preferences WHERE application_id=:application ORDER BY preference_order LIMIT 1',['application'=>$application['id']]);
            $applicationFee=(new AdmissionFeeService())->assess($db,(int)$application['id'],$firstProgram,'application_fee',$category,$application['configuration_version_id']?(int)$application['configuration_version_id']:null);
        }
        if((float)$applicationFee['total_amount']>0&&!in_array($applicationFee['status'],['paid','waived'],true)) throw new RuntimeException('The assessed application fee must be paid or formally waived before merit verification.');
    }
}
