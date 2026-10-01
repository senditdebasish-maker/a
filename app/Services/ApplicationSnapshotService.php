<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class ApplicationSnapshotService
{
    public function capture(Database $db, int $applicationId, int $configurationVersionId, ?int $actorId=null): array
    {
        $application=$db->fetch("SELECT a.*,u.first_name,u.last_name,u.email,u.mobile FROM applications a JOIN users u ON u.id=a.user_id WHERE a.id=:id",['id'=>$applicationId]);
        if (!$application) throw new RuntimeException('Application not found.');
        $version=$db->fetch('SELECT id,admission_cycle_id,version_no,snapshot_hash FROM admission_configuration_versions WHERE id=:id',['id'=>$configurationVersionId]);
        if (!$version||(int)$version['admission_cycle_id']!==(int)$application['admission_cycle_id']) throw new RuntimeException('Configuration version does not belong to the application cycle.');
        $queries=[
            'profile'=>['SELECT * FROM applicant_profiles WHERE user_id=:id',(int)$application['user_id']],
            'address'=>['SELECT * FROM applicant_addresses WHERE application_id=:id',(int)$applicationId],
            'guardian'=>['SELECT * FROM guardians WHERE application_id=:id',(int)$applicationId],
            'education'=>['SELECT * FROM education_records WHERE application_id=:id ORDER BY level,id',(int)$applicationId],
            'exams'=>['SELECT * FROM entrance_exams WHERE application_id=:id ORDER BY id',(int)$applicationId],
            'preferences'=>['SELECT pref.*,cp.program_id,p.code AS program_code,p.name AS program_name FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE pref.application_id=:id ORDER BY pref.preference_order',(int)$applicationId],
            'responses'=>['SELECT afr.*,aff.field_key,aff.label FROM application_field_responses afr JOIN admission_form_fields aff ON aff.id=afr.form_field_id WHERE afr.application_id=:id ORDER BY aff.section_id,aff.sort_order',(int)$applicationId],
            'documents'=>['SELECT id,document_type_id,revision_no,original_name,mime_type,size_bytes,checksum_sha256,status,uploaded_at FROM application_documents WHERE application_id=:id ORDER BY document_type_id',(int)$applicationId],
        ];
        $payload=['application'=>$application,'configuration'=>['id'=>(int)$version['id'],'version_no'=>(int)$version['version_no'],'snapshot_hash'=>$version['snapshot_hash']]];
        foreach ($queries as $key=>[$sql,$id]) $payload[$key]=$db->all($sql,['id'=>$id]);
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $existing=$db->fetch('SELECT id FROM application_submission_snapshots WHERE application_id=:id FOR UPDATE',['id'=>$applicationId]);
        $data=['configuration_version_id'=>$configurationVersionId,'snapshot_json'=>$json,'snapshot_hash'=>hash('sha256',$json),'created_at'=>date('Y-m-d H:i:s')];
        if ($existing) $db->update('application_submission_snapshots',$data,'id=:id',['id'=>$existing['id']]);
        else $db->insert('application_submission_snapshots',$data+['application_id'=>$applicationId]);
        $latest=$db->fetch('SELECT revision_no FROM application_submission_snapshot_revisions WHERE application_id=:application ORDER BY revision_no DESC LIMIT 1 FOR UPDATE',['application'=>$applicationId]);
        $revision=(int)($latest['revision_no']??0)+1;
        $db->insert('application_submission_snapshot_revisions',['application_id'=>$applicationId,'configuration_version_id'=>$configurationVersionId,'revision_no'=>$revision,'event_type'=>$application['status']==='resubmitted'?'correction_resubmission':'initial_submission','snapshot_json'=>$json,'snapshot_hash'=>$data['snapshot_hash'],'created_by'=>$actorId?:$application['user_id'],'created_at'=>$data['created_at']]);
        return ['hash'=>$data['snapshot_hash'],'revision'=>$revision,'payload'=>$payload];
    }
}
