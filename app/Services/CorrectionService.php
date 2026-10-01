<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class CorrectionService
{
    public function request(int $applicationId, string $reason, ?string $dueAt, array $items, int $actorId): int
    {
        $reason=trim($reason);
        if ($reason===''||mb_strlen($reason)>1000) throw new RuntimeException('A correction reason of up to 1000 characters is required.');
        if (!$items) throw new RuntimeException('Select at least one field, section, or document for correction.');
        if ($dueAt!==null&&$dueAt!==''&&strtotime($dueAt)<=time()) throw new RuntimeException('Correction deadline must be in the future.');
        $dueAt=$dueAt!==null&&$dueAt!==''?date('Y-m-d H:i:s',strtotime($dueAt)):null;
        $db=Database::get();
        $id=$db->transaction(function (Database $db) use ($applicationId,$reason,$dueAt,$items,$actorId): int {
            $application=$db->fetch('SELECT * FROM applications WHERE id=:id FOR UPDATE',['id'=>$applicationId]);
            if (!$application||!in_array($application['status'],['submitted','resubmitted','eligibility_check','under_review'],true)) throw new RuntimeException('A correction cannot be requested at the current application stage.');
            if ($db->fetch("SELECT id FROM application_corrections WHERE application_id=:application AND status='open' FOR UPDATE",['application'=>$applicationId])) throw new RuntimeException('An open correction request already exists.');
            $correctionId=$db->insert('application_corrections',['application_id'=>$applicationId,'requested_by'=>$actorId,'reason'=>$reason,'status'=>'open','due_at'=>$dueAt?:null,'submitted_at'=>null,'resolved_by'=>null,'resolved_at'=>null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            foreach ($items as $item) {
                $type=(string)($item['target_type']??''); $key=trim((string)($item['target_key']??'')); $instructions=trim((string)($item['instructions']??$reason));
                if (!in_array($type,['field','section','document'],true)||$key===''||$instructions==='') throw new RuntimeException('Every correction target must be valid and include instructions.');
                $fieldId=null;$documentTypeId=null;
                if($type==='field'){$field=$db->fetch('SELECT id,field_key FROM admission_form_fields WHERE admission_cycle_id=:cycle AND status=\'active\' AND (field_key=:key OR id=:numeric)',['cycle'=>$application['admission_cycle_id'],'key'=>$key,'numeric'=>(int)$key]);if(!$field)throw new RuntimeException('Correction field does not belong to the application cycle.');$fieldId=(int)$field['id'];$key=(string)$field['field_key'];}
                elseif($type==='document'){$document=$db->fetch('SELECT dt.id,dt.code FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id WHERE cdr.admission_cycle_id=:cycle AND (dt.code=:key OR dt.id=:numeric) ORDER BY cdr.id LIMIT 1',['cycle'=>$application['admission_cycle_id'],'key'=>$key,'numeric'=>(int)$key]);if(!$document)throw new RuntimeException('Correction document does not belong to the application cycle.');$documentTypeId=(int)$document['id'];$key=(string)$document['code'];}
                elseif(!(int)$db->scalar('SELECT COUNT(*) FROM admission_form_sections WHERE admission_cycle_id=:cycle AND status=\'active\' AND section_key=:key',['cycle'=>$application['admission_cycle_id'],'key'=>$key]))throw new RuntimeException('Correction section does not belong to the application cycle.');
                $db->insert('application_correction_items',['correction_id'=>$correctionId,'target_type'=>$type,'target_key'=>$key,'form_field_id'=>$fieldId,'document_type_id'=>$documentTypeId,'instructions'=>$instructions,'status'=>'open','responded_at'=>null,'resolved_at'=>null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            }
            $db->update('applications',['status'=>'correction_required','locked_at'=>null,'status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$applicationId]);
            $db->insert('application_status_history',['application_id'=>$applicationId,'from_status'=>$application['status'],'to_status'=>'correction_required','remarks'=>$reason,'changed_by'=>$actorId,'created_at'=>date('Y-m-d H:i:s')]);
            $db->insert('notifications',['user_id'=>$application['user_id'],'type'=>'correction','title'=>'Application correction required','message'=>$reason,'action_url'=>'/student/application','read_at'=>null,'created_at'=>date('Y-m-d H:i:s')]);
            return $correctionId;
        });
        AuditService::log('application_correction_requested','application_correction',$id,[],['application_id'=>$applicationId,'targets'=>count($items),'due_at'=>$dueAt]);
        return $id;
    }

    public function openForApplication(int $applicationId): ?array
    {
        $db=Database::get();
        $correction=$db->fetch("SELECT * FROM application_corrections WHERE application_id=:application AND status='open' ORDER BY id DESC LIMIT 1",['application'=>$applicationId]);
        if (!$correction) return null;
        $correction['items']=$db->all('SELECT * FROM application_correction_items WHERE correction_id=:id ORDER BY id',['id'=>$correction['id']]);
        return $correction;
    }

    public function permits(array $correction, string $targetType, string $targetKey): bool
    {
        if (($correction['status']??'')!=='open') return false;
        if (!empty($correction['due_at'])&&strtotime((string)$correction['due_at'])<time()) return false;
        foreach ($correction['items']??[] as $item) {
            if ($item['status']==='open'&&$item['target_type']===$targetType&&$item['target_key']===$targetKey) return true;
            if ($item['status']==='open'&&$item['target_type']==='section'&&$targetType==='field'&&$item['target_key']===$targetKey) return true;
        }
        return false;
    }

    public function markResponded(int $applicationId, int $userId, string $targetType, string $targetKey): void
    {
        $db=Database::get();
        $correction=$db->fetch("SELECT ac.id FROM application_corrections ac JOIN applications a ON a.id=ac.application_id WHERE ac.application_id=:application AND a.user_id=:user AND ac.status='open' ORDER BY ac.id DESC LIMIT 1",['application'=>$applicationId,'user'=>$userId]);
        if (!$correction) return;
        $db->query("UPDATE application_correction_items SET status='responded', responded_at=:now, updated_at=:now2 WHERE correction_id=:correction AND status='open' AND target_type=:type AND target_key=:target",['now'=>date('Y-m-d H:i:s'),'now2'=>date('Y-m-d H:i:s'),'correction'=>$correction['id'],'type'=>$targetType,'target'=>$targetKey]);
    }

    public function submit(int $applicationId, int $userId): void
    {
        $db=Database::get();
        $correctionId=$db->transaction(function (Database $db) use ($applicationId,$userId): int {
            $application=$db->fetch('SELECT * FROM applications WHERE id=:id AND user_id=:user FOR UPDATE',['id'=>$applicationId,'user'=>$userId]);
            if (!$application||$application['status']!=='correction_required') throw new RuntimeException('No correction request is open for this application.');
            $correction=$db->fetch("SELECT * FROM application_corrections WHERE application_id=:application AND status='open' ORDER BY id DESC LIMIT 1 FOR UPDATE",['application'=>$applicationId]);
            if (!$correction) throw new RuntimeException('Correction request not found.');
            if ($correction['due_at']&&strtotime((string)$correction['due_at'])<time()) throw new RuntimeException('The correction deadline has passed. Contact Admissions.');
            $open=(int)$db->scalar("SELECT COUNT(*) FROM application_correction_items WHERE correction_id=:id AND status='open'",['id'=>$correction['id']]);
            if ($open>0) throw new RuntimeException('Respond to every requested correction item before resubmitting.');
            $db->update('application_corrections',['status'=>'submitted','submitted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$correction['id']]);
            $db->update('applications',['status'=>'resubmitted','resubmitted_at'=>date('Y-m-d H:i:s'),'locked_at'=>date('Y-m-d H:i:s'),'status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$applicationId]);
            (new ApplicationSnapshotService())->capture($db,$applicationId,(int)$application['configuration_version_id'],$userId);
            $db->insert('application_status_history',['application_id'=>$applicationId,'from_status'=>'correction_required','to_status'=>'resubmitted','remarks'=>'Requested corrections resubmitted by applicant','changed_by'=>$userId,'created_at'=>date('Y-m-d H:i:s')]);
            return (int)$correction['id'];
        });
        AuditService::log('application_correction_resubmitted','application_correction',$correctionId,[],['application_id'=>$applicationId]);
    }
}
