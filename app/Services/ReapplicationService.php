<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDOException;
use RuntimeException;

final class ReapplicationService
{
    public function createAttempt(int $sourceApplicationId, int $userId): array
    {
        $db=Database::get();
        try {
            $newApplication=$db->transaction(function(Database $db)use($sourceApplicationId,$userId):array{
            $source=$db->fetch('SELECT a.*,ac.status AS cycle_status,ac.starts_at AS cycle_starts_at,ac.ends_at AS cycle_ends_at,ac.correction_deadline FROM applications a JOIN admission_cycles ac ON ac.id=a.admission_cycle_id WHERE a.id=:id AND a.user_id=:user FOR UPDATE',['id'=>$sourceApplicationId,'user'=>$userId]);
            if(!$source)throw new RuntimeException('Rejected application not found.');
            if($source['status']!=='rejected')throw new RuntimeException('Only a rejected application can be used for reapplication.');
            $cycle=['status'=>$source['cycle_status'],'starts_at'=>$source['cycle_starts_at'],'ends_at'=>$source['cycle_ends_at'],'correction_deadline'=>$source['correction_deadline']];
            if(!(new AdmissionCycleService())->acceptsApplications($cycle))throw new RuntimeException('This admission cycle is no longer accepting reapplications.');

            $latest=$db->fetch('SELECT id,status,attempt_no FROM applications WHERE user_id=:user AND admission_cycle_id=:cycle ORDER BY attempt_no DESC,id DESC LIMIT 1 FOR UPDATE',['user'=>$userId,'cycle'=>$source['admission_cycle_id']]);
            if(!$latest||(int)$latest['id']!==$sourceApplicationId)throw new RuntimeException('A newer application attempt already exists for this admission cycle.');
            $attempt=(int)($latest['attempt_no']??1)+1;
            $versionId=(int)$db->scalar("SELECT id FROM admission_configuration_versions WHERE admission_cycle_id=:cycle AND status IN ('published','seeded_baseline','legacy_import') ORDER BY CASE WHEN status='published' THEN 0 ELSE 1 END,version_no DESC LIMIT 1",['cycle'=>$source['admission_cycle_id']]);
            if($versionId<1)throw new RuntimeException('Published admission configuration is unavailable. Contact Admissions.');
            $now=date('Y-m-d H:i:s');
            $newId=$db->insert('applications',[
                'application_number'=>null,'user_id'=>$userId,'admission_cycle_id'=>$source['admission_cycle_id'],'attempt_no'=>$attempt,
                'reapplied_from_application_id'=>$sourceApplicationId,'status'=>'draft','current_step'=>1,'completion_percentage'=>10,
                'eligibility_status'=>'not_evaluated','eligibility_flags'=>null,'assigned_to'=>null,'assigned_at'=>null,
                'configuration_version_id'=>$versionId,'selected_cycle_program_id'=>null,'submitted_at'=>null,'resubmitted_at'=>null,
                'decision_at'=>null,'withdrawn_at'=>null,'locked_at'=>null,'admitted_at'=>null,'withdrawal_reason'=>null,
                'status_version'=>0,'created_at'=>$now,'updated_at'=>$now,'deleted_at'=>null,
            ]);

            $db->query('INSERT INTO applicant_addresses (application_id,address_line1,address_line2,city,district,state,postal_code,country,same_as_correspondence,correspondence_address,created_at,updated_at) SELECT :new,address_line1,address_line2,city,district,state,postal_code,country,same_as_correspondence,correspondence_address,:created,:updated FROM applicant_addresses WHERE application_id=:source',['new'=>$newId,'created'=>$now,'updated'=>$now,'source'=>$sourceApplicationId]);
            $db->query('INSERT INTO guardians (application_id,name,relationship,occupation,annual_income,mobile,email,address,created_at,updated_at) SELECT :new,name,relationship,occupation,annual_income,mobile,email,address,:created,:updated FROM guardians WHERE application_id=:source',['new'=>$newId,'created'=>$now,'updated'=>$now,'source'=>$sourceApplicationId]);
            $db->query('INSERT INTO education_records (application_id,level,board,institution,passing_year,roll_number,registration_number,total_marks,obtained_marks,percentage,grade_cgpa,subjects,result_status,created_at,updated_at) SELECT :new,level,board,institution,passing_year,roll_number,registration_number,total_marks,obtained_marks,percentage,grade_cgpa,subjects,result_status,:created,:updated FROM education_records WHERE application_id=:source',['new'=>$newId,'created'=>$now,'updated'=>$now,'source'=>$sourceApplicationId]);
            $db->query('INSERT INTO entrance_exams (application_id,exam_name,roll_number,rank_score,percentile,exam_year,created_at,updated_at) SELECT :new,exam_name,roll_number,rank_score,percentile,exam_year,:created,:updated FROM entrance_exams WHERE application_id=:source',['new'=>$newId,'created'=>$now,'updated'=>$now,'source'=>$sourceApplicationId]);
            $db->query("INSERT INTO application_preferences (application_id,cycle_program_id,preference_order,allocation_status,created_at) SELECT :new,pref.cycle_program_id,pref.preference_order,'pending',:created FROM application_preferences pref JOIN cycle_programs cp ON cp.id=pref.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE pref.application_id=:source AND cp.admission_cycle_id=:cycle AND cp.status='active' AND p.status='active'",['new'=>$newId,'created'=>$now,'source'=>$sourceApplicationId,'cycle'=>$source['admission_cycle_id']]);
            $db->query("INSERT INTO application_field_responses (application_id,form_field_id,configuration_version_id,value_text,value_json,created_at,updated_at) SELECT :new,response.form_field_id,:version,response.value_text,response.value_json,:created,:updated FROM application_field_responses response JOIN admission_form_fields field ON field.id=response.form_field_id WHERE response.application_id=:source AND field.admission_cycle_id=:cycle AND field.status='active'",['new'=>$newId,'version'=>$versionId,'created'=>$now,'updated'=>$now,'source'=>$sourceApplicationId,'cycle'=>$source['admission_cycle_id']]);

            $sourceDocuments=$db->all("SELECT document.* FROM application_documents document JOIN document_types type ON type.id=document.document_type_id WHERE document.application_id=:source AND type.status='active' AND EXISTS (
                SELECT 1 FROM cycle_document_requirements requirement
                WHERE requirement.admission_cycle_id=:cycle AND requirement.document_type_id=document.document_type_id AND requirement.stage='application'
                AND (requirement.program_id IS NULL OR EXISTS (
                    SELECT 1 FROM application_preferences preference JOIN cycle_programs cycle_program ON cycle_program.id=preference.cycle_program_id
                    WHERE preference.application_id=:new_application AND cycle_program.program_id=requirement.program_id
                ))
                AND (requirement.category IS NULL OR requirement.category=COALESCE((SELECT category FROM applicant_profiles WHERE user_id=:profile_user),''))
            )",['source'=>$sourceApplicationId,'cycle'=>$source['admission_cycle_id'],'new_application'=>$newId,'profile_user'=>$userId]);
            foreach($sourceDocuments as $document){
                if(!is_file(BASE_PATH.'/storage/private/'.ltrim((string)$document['path'],'/')))continue;
                $documentId=$db->insert('application_documents',[
                    'application_id'=>$newId,'document_type_id'=>$document['document_type_id'],'path'=>$document['path'],'original_name'=>$document['original_name'],
                    'mime_type'=>$document['mime_type'],'size_bytes'=>$document['size_bytes'],'checksum_sha256'=>$document['checksum_sha256'],'revision_no'=>1,
                    'uploaded_by'=>$document['uploaded_by']?:$userId,'status'=>'pending','review_remarks'=>null,'reviewed_by'=>null,'reviewed_at'=>null,
                    'uploaded_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
                ]);
                $db->insert('application_document_versions',[
                    'application_document_id'=>$documentId,'revision_no'=>1,'path'=>$document['path'],'original_name'=>$document['original_name'],
                    'mime_type'=>$document['mime_type'],'size_bytes'=>$document['size_bytes'],'checksum_sha256'=>$document['checksum_sha256'],'status'=>'pending',
                    'review_remarks'=>null,'reviewed_by'=>null,'reviewed_at'=>null,'uploaded_by'=>$document['uploaded_by']?:$userId,'created_at'=>$now,
                ]);
            }

            $completion=$this->completionScore($db,$newId,$userId);
            $db->update('applications',['completion_percentage'=>$completion],'id=:id',['id'=>$newId]);
            $db->insert('application_status_history',['application_id'=>$newId,'from_status'=>null,'to_status'=>'draft','remarks'=>'Reapplication attempt '.$attempt.' created from rejected application '.($source['application_number']?:'#'.$sourceApplicationId),'changed_by'=>$userId,'created_at'=>$now]);
            $db->insert('notifications',['user_id'=>$userId,'type'=>'application','title'=>'Reapplication started','message'=>'Attempt '.$attempt.' was created with your existing details. Review every step before submitting again.','action_url'=>'/student/application?application_id='.$newId,'read_at'=>null,'created_at'=>$now]);
            AuditService::log('application_reapplied','application',$newId,['source_application_id'=>$sourceApplicationId],['attempt_no'=>$attempt]);
            return $db->fetch('SELECT * FROM applications WHERE id=:id',['id'=>$newId])??[];
            });
        } catch (PDOException $exception) {
            if((string)$exception->getCode()==='23000')throw new RuntimeException('A newer application attempt already exists for this admission cycle.');
            throw new RuntimeException('The new application attempt could not be created. Please try again.');
        }
        return $newApplication;
    }

    private function completionScore(Database $db,int $applicationId,int $userId):int
    {
        $score=10;
        $profile=$db->fetch('SELECT date_of_birth,gender,category FROM applicant_profiles WHERE user_id=:user',['user'=>$userId]);
        if($profile&&$profile['date_of_birth']&&$profile['gender']&&$profile['category'])$score+=20;
        if($db->scalar('SELECT COUNT(*) FROM applicant_addresses WHERE application_id=:id',['id'=>$applicationId]))$score+=15;
        if($db->scalar('SELECT COUNT(*) FROM guardians WHERE application_id=:id',['id'=>$applicationId]))$score+=15;
        if((int)$db->scalar('SELECT COUNT(*) FROM education_records WHERE application_id=:id',['id'=>$applicationId])>=2)$score+=20;
        if($db->scalar('SELECT COUNT(*) FROM application_preferences WHERE application_id=:id',['id'=>$applicationId]))$score+=10;
        if($db->scalar('SELECT COUNT(*) FROM application_documents WHERE application_id=:id',['id'=>$applicationId]))$score+=10;
        return min(100,$score);
    }
}
