<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class MeritService
{
    public const TIE_BREAKS = ['class_12_percentage_desc','class_10_percentage_desc','submitted_at_asc','application_number_asc'];

    public function saveCycleSettings(int $cycleId, string $policy, string $quota, int $offerHours, int $actorId): void
    {
        if (!in_array($policy,['open_first','category_only'],true)) throw new RuntimeException('Select a valid reservation policy.');
        $quota=trim($quota);
        if ($quota===''||mb_strlen($quota)>80) throw new RuntimeException('Enter a valid default quota.');
        if ($offerHours<1||$offerHours>720) throw new RuntimeException('Offer validity must be between 1 and 720 hours.');
        $db=Database::get();
        if(!(int)$db->scalar('SELECT COUNT(*) FROM admission_cycles WHERE id=:id',['id'=>$cycleId])) throw new RuntimeException('Admission cycle not found.');
        $existing=$db->fetch('SELECT id FROM merit_cycle_settings WHERE admission_cycle_id=:cycle',['cycle'=>$cycleId]);
        $data=['reservation_policy'=>$policy,'default_quota'=>$quota,'offer_valid_hours'=>$offerHours,'updated_by'=>$actorId,'updated_at'=>date('Y-m-d H:i:s')];
        if($existing)$db->update('merit_cycle_settings',$data,'id=:id',['id'=>$existing['id']]);
        else $db->insert('merit_cycle_settings',$data+['admission_cycle_id'=>$cycleId,'created_at'=>date('Y-m-d H:i:s')]);
        AuditService::log('merit_cycle_settings_updated','admission_cycle',$cycleId,[],['reservation_policy'=>$policy,'default_quota'=>$quota,'offer_valid_hours'=>$offerHours]);
    }

    public function saveFormula(int $cycleProgramId, float $class10, float $class12, float $entrance, int $actorId): int
    {
        foreach ([$class10,$class12,$entrance] as $weight) if($weight<0||$weight>100) throw new RuntimeException('Each merit weight must be between 0 and 100.');
        if(abs(($class10+$class12+$entrance)-100.0)>0.001) throw new RuntimeException('Merit weights must total exactly 100%.');
        $db=Database::get();
        $program=$db->fetch('SELECT id,admission_cycle_id FROM cycle_programs WHERE id=:id AND status=:status',['id'=>$cycleProgramId,'status'=>'active']);
        if(!$program)throw new RuntimeException('Active cycle programme not found.');
        $id=$db->transaction(function(Database $db)use($program,$cycleProgramId,$class10,$class12,$entrance,$actorId):int{
            $db->fetch('SELECT id FROM cycle_programs WHERE id=:id FOR UPDATE',['id'=>$cycleProgramId]);
            $version=(int)$db->scalar('SELECT COALESCE(MAX(version_no),0)+1 FROM merit_formula_versions WHERE cycle_program_id=:program',['program'=>$cycleProgramId]);
            $db->query("UPDATE merit_formula_versions SET status='retired',retired_at=NOW() WHERE cycle_program_id=:program AND status='active'",['program'=>$cycleProgramId]);
            return $db->insert('merit_formula_versions',[
                'admission_cycle_id'=>$program['admission_cycle_id'],'cycle_program_id'=>$cycleProgramId,'version_no'=>$version,
                'class_10_weight'=>number_format($class10,3,'.',''),'class_12_weight'=>number_format($class12,3,'.',''),'entrance_weight'=>number_format($entrance,3,'.',''),
                'tie_break_json'=>json_encode(self::TIE_BREAKS,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'status'=>'active','created_by'=>$actorId,'created_at'=>date('Y-m-d H:i:s'),'retired_at'=>null,
            ]);
        });
        AuditService::log('merit_formula_version_created','merit_formula_version',$id,[],['cycle_program_id'=>$cycleProgramId,'weights'=>[$class10,$class12,$entrance]]);
        return $id;
    }

    public function generate(int $cycleId, int $actorId): int
    {
        $db=Database::get();
        $cycle=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id',['id'=>$cycleId]);
        if(!$cycle)throw new RuntimeException('Admission cycle not found.');
        $settings=$db->fetch('SELECT * FROM merit_cycle_settings WHERE admission_cycle_id=:cycle',['cycle'=>$cycleId])?:[
            'reservation_policy'=>'open_first','default_quota'=>'state','offer_valid_hours'=>72,
        ];
        $programs=$db->all("SELECT cp.id,p.name,p.code,mfv.id AS formula_id,mfv.version_no AS formula_version,mfv.class_10_weight,mfv.class_12_weight,mfv.entrance_weight,mfv.tie_break_json
            FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id
            LEFT JOIN merit_formula_versions mfv ON mfv.cycle_program_id=cp.id AND mfv.status='active'
            WHERE cp.admission_cycle_id=:cycle AND cp.status='active' AND p.status='active' ORDER BY cp.id",['cycle'=>$cycleId]);
        if(!$programs)throw new RuntimeException('No active programme is configured for this cycle.');
        $missing=array_values(array_filter($programs,static fn(array $program):bool=>empty($program['formula_id'])));
        if($missing)throw new RuntimeException('Define an explicit 100% merit formula for every programme before generation: '.implode(', ',array_column($missing,'name')).'.');

        $candidates=$db->all("SELECT a.id,a.application_number,a.user_id,a.submitted_at,a.eligibility_flags,profile.category,
                MAX(CASE WHEN education.level='class_10' THEN education.percentage END) AS class_10_percentage,
                MAX(CASE WHEN education.level='class_12' THEN education.percentage END) AS class_12_percentage,
                MAX(exam.percentile) AS entrance_percentile
            FROM applications a JOIN applicant_profiles profile ON profile.user_id=a.user_id
            LEFT JOIN education_records education ON education.application_id=a.id
            LEFT JOIN entrance_exams exam ON exam.application_id=a.id
            WHERE a.admission_cycle_id=:cycle AND a.status='verified' AND a.eligibility_status='eligible'
            GROUP BY a.id,a.application_number,a.user_id,a.submitted_at,a.eligibility_flags,profile.category
            ORDER BY a.id",['cycle'=>$cycleId]);
        $preferences=$db->all("SELECT preference.application_id,preference.cycle_program_id,preference.preference_order
            FROM application_preferences preference JOIN applications application ON application.id=preference.application_id
            WHERE application.admission_cycle_id=:cycle ORDER BY preference.application_id,preference.preference_order",['cycle'=>$cycleId]);
        $preferencesByProgram=[];
        foreach($preferences as $preference)$preferencesByProgram[(int)$preference['cycle_program_id']][(int)$preference['application_id']]=$preference;
        $candidateById=[];foreach($candidates as $candidate)$candidateById[(int)$candidate['id']]=$candidate;

        $ranked=[];$criteriaCandidates=[];
        foreach($programs as $program){
            $programId=(int)$program['id'];$rows=[];
            foreach($preferencesByProgram[$programId]??[] as $applicationId=>$preference){
                $candidate=$candidateById[$applicationId]??null;if(!$candidate)continue;
                if(!$this->eligibleForProgram($candidate,$programId))continue;
                $class10=$this->requiredPercentage($candidate,'class_10_percentage',(float)$program['class_10_weight'],$candidate['application_number'],$program['name']);
                $class12=$this->requiredPercentage($candidate,'class_12_percentage',(float)$program['class_12_weight'],$candidate['application_number'],$program['name']);
                $entrance=$this->requiredPercentage($candidate,'entrance_percentile',(float)$program['entrance_weight'],$candidate['application_number'],$program['name']);
                $score=round(($class10*(float)$program['class_10_weight']+$class12*(float)$program['class_12_weight']+$entrance*(float)$program['entrance_weight'])/100,5);
                $rows[]=$candidate+['preference_order'=>(int)$preference['preference_order'],'score'=>$score,'class10'=>$class10,'class12'=>$class12,'entrance'=>$candidate['entrance_percentile']!==null?(float)$candidate['entrance_percentile']:null];
            }
            usort($rows,fn(array $a,array $b):int=>$this->compare($a,$b));
            $ranked[$programId]=$rows;
            foreach($rows as $row)$criteriaCandidates[]=['application_id'=>(int)$row['id'],'program_id'=>$programId,'score'=>$row['score'],'class10'=>$row['class10'],'class12'=>$row['class12'],'entrance'=>$row['entrance']];
        }
        $formulaCriteria=array_map(static fn(array $p):array=>['program_id'=>(int)$p['id'],'formula_id'=>(int)$p['formula_id'],'version'=>(int)$p['formula_version'],'weights'=>[(float)$p['class_10_weight'],(float)$p['class_12_weight'],(float)$p['entrance_weight']]],$programs);
        $criteriaHash=hash('sha256',json_encode(['cycle'=>$cycleId,'policy'=>$settings['reservation_policy'],'formulas'=>$formulaCriteria,'candidates'=>$criteriaCandidates],JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));

        $runId=$db->transaction(function(Database $db)use($cycleId,$actorId,$settings,$programs,$ranked,$criteriaHash):int{
            $db->fetch('SELECT id FROM admission_cycles WHERE id=:id FOR UPDATE',['id'=>$cycleId]);
            $version=(int)$db->scalar('SELECT COALESCE(MAX(version_no),0)+1 FROM merit_runs WHERE admission_cycle_id=:cycle',['cycle'=>$cycleId]);
            $distinct=[];$entryCount=0;
            $runId=$db->insert('merit_runs',[
                'admission_cycle_id'=>$cycleId,'version_no'=>$version,'status'=>'draft','reservation_policy'=>$settings['reservation_policy'],'source_statuses'=>'verified',
                'criteria_hash'=>$criteriaHash,'applicant_count'=>0,'entry_count'=>0,'generated_by'=>$actorId,'generated_at'=>date('Y-m-d H:i:s'),'published_by'=>null,'published_at'=>null,'superseded_at'=>null,
            ]);
            foreach($programs as $program){
                $programId=(int)$program['id'];$rows=$ranked[$programId]??[];
                $snapshot=['formula_version'=>(int)$program['formula_version'],'class_10_weight'=>(float)$program['class_10_weight'],'class_12_weight'=>(float)$program['class_12_weight'],'entrance_weight'=>(float)$program['entrance_weight'],'tie_breaks'=>self::TIE_BREAKS];
                $runProgramId=$db->insert('merit_run_programs',['merit_run_id'=>$runId,'cycle_program_id'=>$programId,'formula_version_id'=>$program['formula_id'],'formula_snapshot_json'=>json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'eligible_count'=>count($rows)]);
                $reservedCounters=[];
                foreach($rows as $index=>$row){
                    $overall=$index+1;$category=(string)($row['category']?:'General');$distinct[(int)$row['id']]=true;
                    $this->insertEntry($db,$runId,$runProgramId,$programId,$row,'General',(string)$settings['default_quota'],$overall,$overall,$snapshot);$entryCount++;
                    if((string)$settings['reservation_policy']==='open_first'&&strcasecmp($category,'General')!==0){
                        $reservedCounters[$category]=($reservedCounters[$category]??0)+1;
                        $this->insertEntry($db,$runId,$runProgramId,$programId,$row,$category,(string)$settings['default_quota'],$overall,$reservedCounters[$category],$snapshot);$entryCount++;
                    }elseif((string)$settings['reservation_policy']==='category_only'&&strcasecmp($category,'General')!==0){
                        // Category-only lists do not duplicate the open row; convert it to the applicant category.
                        $db->query('DELETE FROM merit_entries WHERE merit_run_id=:run AND application_id=:application AND cycle_program_id=:program AND merit_category=:category',['run'=>$runId,'application'=>$row['id'],'program'=>$programId,'category'=>'General']);$entryCount--;
                        $reservedCounters[$category]=($reservedCounters[$category]??0)+1;
                        $this->insertEntry($db,$runId,$runProgramId,$programId,$row,$category,(string)$settings['default_quota'],$overall,$reservedCounters[$category],$snapshot);$entryCount++;
                    }
                }
            }
            $db->update('merit_runs',['applicant_count'=>count($distinct),'entry_count'=>$entryCount],'id=:id',['id'=>$runId]);
            return $runId;
        });
        AuditService::log('merit_run_generated','merit_run',$runId,[],['cycle_id'=>$cycleId,'criteria_hash'=>$criteriaHash]);
        return $runId;
    }

    public function publish(int $runId, int $actorId): void
    {
        $db=Database::get();
        $applications=$db->transaction(function(Database $db)use($runId,$actorId):array{
            $run=$db->fetch("SELECT * FROM merit_runs WHERE id=:id FOR UPDATE",['id'=>$runId]);
            if(!$run||$run['status']!=='draft')throw new RuntimeException('Only a draft merit run can be published.');
            if((int)$run['entry_count']<1)throw new RuntimeException('An empty merit run cannot be published.');
            $stale=(int)$db->scalar("SELECT COUNT(DISTINCT me.application_id) FROM merit_entries me JOIN applications a ON a.id=me.application_id WHERE me.merit_run_id=:run AND (a.status<>'verified' OR a.eligibility_status<>'eligible')",['run'=>$runId]);
            if($stale>0)throw new RuntimeException("{$stale} ranked application(s) changed after generation. Generate a new frozen run instead of publishing stale evidence.");
            $db->query("UPDATE merit_runs SET status='superseded',superseded_at=NOW() WHERE admission_cycle_id=:cycle AND status='published'",['cycle'=>$run['admission_cycle_id']]);
            $db->update('merit_runs',['status'=>'published','published_by'=>$actorId,'published_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$runId]);
            $applications=$db->all("SELECT DISTINCT application_id FROM merit_entries WHERE merit_run_id=:run ORDER BY application_id",['run'=>$runId]);
            foreach($applications as $row){
                $application=$db->fetch("SELECT status,status_version FROM applications WHERE id=:id FOR UPDATE",['id'=>$row['application_id']]);
                if(!$application||$application['status']!=='verified')continue;
                $db->update('applications',['status'=>'waitlisted','status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$row['application_id']]);
                $db->insert('application_status_history',['application_id'=>$row['application_id'],'from_status'=>$application['status'],'to_status'=>'waitlisted','remarks'=>'Published in merit run v'.$run['version_no'],'changed_by'=>$actorId,'created_at'=>date('Y-m-d H:i:s')]);
                $db->query("UPDATE merit_entries SET result_status='waitlisted' WHERE merit_run_id=:run AND application_id=:application AND result_status='ranked'",['run'=>$runId,'application'=>$row['application_id']]);
            }
            return array_map('intval',array_column($applications,'application_id'));
        });
        $notifier=new AdmissionNotificationService();
        foreach($applications as $applicationId){
            $ranks=$db->all("SELECT me.overall_rank,me.category_rank,me.merit_category,p.name FROM merit_entries me JOIN cycle_programs cp ON cp.id=me.cycle_program_id JOIN programs p ON p.id=cp.program_id WHERE me.merit_run_id=:run AND me.application_id=:application ORDER BY me.preference_order,me.merit_category",['run'=>$runId,'application'=>$applicationId]);
            $summary=$ranks?'Your highest published rank is '.min(array_column($ranks,'overall_rank')).'.':'';
            $notifier->meritPublished($applicationId,$summary);
            $notifier->status($applicationId,'waitlisted',[],false);
        }
        AuditService::log('merit_run_published','merit_run',$runId,[],['queued_notifications'=>count($applications)]);
    }

    public function select(int $entryId, string $seatCategory, string $quota, int $offerHours, int $actorId): array
    {
        $db=Database::get();
        $entry=$db->fetch("SELECT me.*,a.status_version FROM merit_entries me JOIN merit_runs mr ON mr.id=me.merit_run_id JOIN applications a ON a.id=me.application_id WHERE me.id=:id AND mr.status='published'",['id'=>$entryId]);
        if(!$entry)throw new RuntimeException('Published merit entry not found.');
        return (new ApplicationWorkflowService())->transition((int)$entry['application_id'],'selected','Selected from published merit rank '.$entry['category_rank'],$actorId,[
            'status_version'=>(int)$entry['status_version'],'cycle_program_id'=>(int)$entry['cycle_program_id'],'merit_entry_id'=>$entryId,
            'category'=>$seatCategory!==''?$seatCategory:(string)$entry['merit_category'],'quota'=>$quota!==''?$quota:(string)$entry['quota'],'offer_valid_hours'=>$offerHours,
        ]);
    }

    public function closeRemaining(int $runId, int $actorId): int
    {
        $db=Database::get();$closed=[];
        $applicationIds=array_map('intval',array_column($db->all("SELECT DISTINCT me.application_id FROM merit_entries me JOIN merit_runs mr ON mr.id=me.merit_run_id JOIN applications a ON a.id=me.application_id WHERE me.merit_run_id=:run AND mr.status='published' AND me.result_status='waitlisted' AND a.status='waitlisted'",['run'=>$runId]),'application_id'));
        foreach($applicationIds as $applicationId){
            $application=$db->fetch('SELECT status_version FROM applications WHERE id=:id',['id'=>$applicationId]);
            if(!$application)continue;
            (new ApplicationWorkflowService())->transition($applicationId,'not_selected','Merit selection round closed without an offer.',$actorId,['status_version'=>(int)$application['status_version'],'defer_notification'=>true]);
            $db->query("UPDATE merit_entries SET result_status='not_selected' WHERE merit_run_id=:run AND application_id=:application AND result_status='waitlisted'",['run'=>$runId,'application'=>$applicationId]);
            $closed[]=$applicationId;
        }
        AuditService::log('merit_run_remaining_closed','merit_run',$runId,[],['count'=>count($closed)]);
        return count($closed);
    }

    public function expireDueOffers(int $actorId, int $limit=500): int
    {
        $db=Database::get();$limit=max(1,min(2000,$limit));$expired=0;
        $offers=$db->all("SELECT offer.application_id,a.status_version FROM selection_offers offer JOIN applications a ON a.id=offer.application_id WHERE offer.status='payment_due' AND offer.expires_at<NOW() AND a.status IN ('selected','payment_pending') ORDER BY offer.expires_at LIMIT {$limit}");
        foreach($offers as $offer){
            try{(new ApplicationWorkflowService())->transition((int)$offer['application_id'],'offer_expired','Payment deadline expired; seat released for explicit waitlist promotion.',$actorId,['status_version'=>(int)$offer['status_version'],'defer_notification'=>true]);$expired++;}
            catch(RuntimeException){continue;}
        }
        return $expired;
    }

    private function insertEntry(Database $db,int $runId,int $runProgramId,int $programId,array $row,string $meritCategory,string $quota,int $overall,int $categoryRank,array $formula):void
    {
        $db->insert('merit_entries',[
            'merit_run_id'=>$runId,'merit_run_program_id'=>$runProgramId,'application_id'=>$row['id'],'cycle_program_id'=>$programId,'application_number'=>$row['application_number'],
            'applicant_category'=>(string)($row['category']?:'General'),'merit_category'=>$meritCategory,'quota'=>$quota,'preference_order'=>$row['preference_order'],'merit_score'=>number_format((float)$row['score'],5,'.',''),
            'overall_rank'=>$overall,'category_rank'=>$categoryRank,'class_10_percentage'=>number_format((float)$row['class10'],2,'.',''),'class_12_percentage'=>number_format((float)$row['class12'],2,'.',''),
            'entrance_percentile'=>$row['entrance']===null?null:number_format((float)$row['entrance'],3,'.',''),'component_snapshot_json'=>json_encode(['class_10_percentage'=>$row['class10'],'class_12_percentage'=>$row['class12'],'entrance_percentile'=>$row['entrance'],'formula'=>$formula],JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),
            'tie_break_snapshot_json'=>json_encode(['class_12_percentage'=>$row['class12'],'class_10_percentage'=>$row['class10'],'submitted_at'=>$row['submitted_at'],'application_number'=>$row['application_number']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            'result_status'=>'ranked','selected_at'=>null,'selected_by'=>null,'created_at'=>date('Y-m-d H:i:s'),
        ]);
    }

    private function eligibleForProgram(array $candidate,int $programId):bool
    {
        $flags=json_decode((string)($candidate['eligibility_flags']??''),true);
        foreach($flags['programs']??[] as $program)if((int)($program['cycle_program_id']??0)===$programId)return ($program['status']??null)==='eligible';
        return false;
    }

    private function requiredPercentage(array $candidate,string $key,float $weight,string $applicationNumber,string $programName):float
    {
        if($weight<=0)return (float)($candidate[$key]??0);
        if($candidate[$key]===null||$candidate[$key]==='')throw new RuntimeException("Cannot generate merit: {$applicationNumber} is missing {$key} required by {$programName}'s formula.");
        $value=(float)$candidate[$key];if($value<0||$value>100)throw new RuntimeException("Cannot generate merit: {$applicationNumber} has an invalid {$key}.");
        return $value;
    }

    private function compare(array $a,array $b):int
    {
        foreach([['score','desc'],['class12','desc'],['class10','desc'],['submitted_at','asc'],['application_number','asc']] as [$key,$direction]){
            $comparison=is_numeric($a[$key])&&is_numeric($b[$key])?((float)$a[$key]<=>(float)$b[$key]):strcmp((string)$a[$key],(string)$b[$key]);
            if($comparison!==0)return $direction==='desc'?-$comparison:$comparison;
        }
        return (int)$a['id']<=>(int)$b['id'];
    }
}
