<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;
use RuntimeException;

final class AdmissionCycleService
{
    public const STORED_STATES = ['draft', 'published', 'closed', 'archived'];

    public function effectiveStatus(array $cycle, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now');
        $stored = (string) ($cycle['status'] ?? 'draft');
        if ($stored === 'archived') return 'archived';
        if (in_array($stored, ['closed','processing'], true)) return 'closed';
        if ($stored === 'draft') return 'draft';
        if (!in_array($stored, ['published','open'], true)) return 'unavailable';
        try {
            $starts = new DateTimeImmutable((string) $cycle['starts_at']);
            $ends = new DateTimeImmutable((string) $cycle['ends_at']);
        } catch (\Throwable) {
            return 'unavailable';
        }
        if ($now < $starts) return 'scheduled';
        if ($now > $ends) return 'closed';
        $hours = max(0, (int) ($cycle['closing_soon_hours'] ?? 72));
        if ($hours > 0 && $now >= $ends->modify("-{$hours} hours")) return 'closing_soon';
        return 'live';
    }

    public function acceptsApplications(array $cycle, ?DateTimeImmutable $now = null): bool
    {
        return in_array($this->effectiveStatus($cycle, $now), ['live','closing_soon'], true);
    }

    public function publicCycles(bool $includeScheduled = true): array
    {
        $cycles = Database::get()->all("SELECT ac.*, ses.name AS session_name,
            (SELECT COUNT(*) FROM cycle_programs cp WHERE cp.admission_cycle_id=ac.id AND cp.status='active') AS program_count,
            (SELECT COALESCE(SUM(cp.seat_capacity),0) FROM cycle_programs cp WHERE cp.admission_cycle_id=ac.id AND cp.status='active') AS total_seats,
            (SELECT MIN(cp.application_fee) FROM cycle_programs cp WHERE cp.admission_cycle_id=ac.id AND cp.status='active') AS application_fee_from
            FROM admission_cycles ac JOIN academic_sessions ses ON ses.id=ac.academic_session_id
            WHERE ac.status IN ('published','open') ORDER BY ac.starts_at, ac.id");
        $allowed = $includeScheduled ? ['scheduled','live','closing_soon'] : ['live','closing_soon'];
        return array_values(array_filter(array_map(function (array $cycle): array {
            $cycle['effective_status'] = $this->effectiveStatus($cycle);
            return $cycle;
        }, $cycles), static fn (array $cycle): bool => in_array($cycle['effective_status'], $allowed, true)));
    }

    public function publicCycle(string $slug): ?array
    {
        $cycle = Database::get()->fetch("SELECT ac.*, ses.name AS session_name FROM admission_cycles ac JOIN academic_sessions ses ON ses.id=ac.academic_session_id WHERE ac.slug=:slug AND ac.status IN ('published','open') LIMIT 1", ['slug'=>$slug]);
        if (!$cycle) return null;
        $cycle['effective_status']=$this->effectiveStatus($cycle);
        if (!in_array($cycle['effective_status'], ['scheduled','live','closing_soon'], true)) return null;
        return $cycle;
    }

    public function readiness(int $cycleId): array
    {
        $db=Database::get();
        $cycle=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id',['id'=>$cycleId]);
        if (!$cycle) throw new RuntimeException('Admission cycle not found.');
        $errors=[]; $warnings=[];
        if (trim((string)$cycle['name'])==='') $errors[]='Cycle name is required.';
        if (trim((string)$cycle['slug'])==='') $errors[]='A unique public slug is required.';
        if (strtotime((string)$cycle['ends_at'])<=strtotime((string)$cycle['starts_at'])) $errors[]='Closing date must be after opening date.';
        if ($cycle['correction_deadline'] && strtotime((string)$cycle['correction_deadline'])<strtotime((string)$cycle['ends_at'])) $errors[]='Correction deadline cannot precede the application deadline.';
        if (trim((string)$cycle['instructions'])==='') $errors[]='Applicant instructions are required.';
        if (trim((string)$cycle['declaration_text'])==='') $errors[]='Applicant declaration text is required.';
        if (trim((string)$cycle['application_number_prefix'])==='') $errors[]='Application number prefix is required.';
        $programs=$db->all("SELECT cp.*, p.name, p.status AS program_status,
            (SELECT COUNT(*) FROM eligibility_rules er WHERE er.cycle_program_id=cp.id) AS eligibility_count,
            (SELECT COALESCE(SUM(sm.seats),0) FROM seat_matrix sm WHERE sm.cycle_program_id=cp.id) AS matrix_total,
            (SELECT COUNT(*) FROM admission_fee_rules afr WHERE afr.cycle_program_id=cp.id AND afr.fee_type='application_fee' AND afr.status='active') AS application_fee_rules,
            (SELECT COUNT(*) FROM admission_fee_rules afr WHERE afr.cycle_program_id=cp.id AND afr.fee_type='admission_fee' AND afr.status='active') AS admission_fee_rules
            FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle AND cp.status='active'",['cycle'=>$cycleId]);
        if (!$programs) $errors[]='At least one active programme is required.';
        foreach ($programs as $program) {
            if ($program['program_status']!=='active') $errors[]="{$program['name']} is not active in the programme master.";
            if ((int)$program['seat_capacity']<1) $errors[]="{$program['name']} requires a positive seat capacity.";
            if ((int)$program['matrix_total']!==(int)$program['seat_capacity']) $errors[]="{$program['name']} seat matrix total must equal its capacity.";
            if ((int)$program['eligibility_count']<1) $errors[]="{$program['name']} requires at least one eligibility rule.";
            if ((int)$program['application_fee_rules']<1) $errors[]="{$program['name']} requires an application-fee rule.";
            if ((int)$program['admission_fee_rules']<1) $errors[]="{$program['name']} requires an admission-fee rule.";
        }
        $requiredDocs=(int)$db->scalar("SELECT COUNT(*) FROM cycle_document_requirements WHERE admission_cycle_id=:cycle AND is_required=1 AND stage='application'",['cycle'=>$cycleId]);
        if ($requiredDocs<1) $errors[]='At least one application-stage required document is needed.';
        $sectionCount=(int)$db->scalar("SELECT COUNT(*) FROM admission_form_sections WHERE admission_cycle_id=:cycle AND status='active'",['cycle'=>$cycleId]);
        $requiredFieldCount=(int)$db->scalar("SELECT COUNT(*) FROM admission_form_fields WHERE admission_cycle_id=:cycle AND status='active' AND is_required=1",['cycle'=>$cycleId]);
        if ($sectionCount<1) $errors[]='At least one active form section is required.';
        if ($requiredFieldCount<1) $errors[]='At least one required form field is required.';
        if (!$cycle['prospectus_path']) $warnings[]='No prospectus has been uploaded.';
        if ((int)$cycle['max_program_preferences']<1) $errors[]='Maximum programme preferences must be at least one.';
        return ['ready'=>$errors===[],'errors'=>$errors,'warnings'=>$warnings,'cycle'=>$cycle];
    }

    public function publish(int $cycleId, int $actorId): array
    {
        $readiness=$this->readiness($cycleId);
        if (!$readiness['ready']) throw new RuntimeException(implode(' ', $readiness['errors']));
        $db=Database::get();
        $result=$db->transaction(function (Database $db) use ($cycleId,$actorId): array {
            $cycle=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id FOR UPDATE',['id'=>$cycleId]);
            if (!$cycle || $cycle['status']!=='draft') throw new RuntimeException('Only a draft cycle can be published. Duplicate published configuration to make changes.');
            $next=(int)$cycle['configuration_version']+1;
            $payload=$this->configurationPayload($cycleId);
            $payload['cycle']['status']='published';
            $payload['cycle']['configuration_version']=$next;
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $versionId=$db->insert('admission_configuration_versions',['admission_cycle_id'=>$cycleId,'version_no'=>$next,'snapshot_json'=>$json,'snapshot_hash'=>hash('sha256',$json),'status'=>'published','created_by'=>$actorId,'created_at'=>date('Y-m-d H:i:s')]);
            $db->update('admission_cycles',['status'=>'published','configuration_version'=>$next,'published_at'=>date('Y-m-d H:i:s'),'published_by'=>$actorId,'closed_at'=>null,'archived_at'=>null,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$cycleId]);
            return ['version_id'=>$versionId,'version_no'=>$next,'hash'=>hash('sha256',$json)];
        });
        AuditService::log('admission_cycle_published','admission_cycle',$cycleId,['status'=>$readiness['cycle']['status']],['status'=>'published']+$result);
        return $result;
    }

    public function close(int $cycleId, int $actorId): void
    {
        $this->changeStoredState($cycleId,'closed',$actorId);
    }

    public function archive(int $cycleId, int $actorId): void
    {
        $this->changeStoredState($cycleId,'archived',$actorId);
    }

    public function duplicate(int $sourceId, array $identity, int $actorId): int
    {
        foreach (['name','code','slug','starts_at','ends_at'] as $field) if (trim((string)($identity[$field]??''))==='') throw new RuntimeException(ucwords(str_replace('_',' ',$field)).' is required.');
        if (strtotime((string)$identity['ends_at'])<=strtotime((string)$identity['starts_at'])) throw new RuntimeException('Closing date must be after opening date.');
        $db=Database::get();
        $newId=$db->transaction(function (Database $db) use ($sourceId,$identity): int {
            $source=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id FOR UPDATE',['id'=>$sourceId]);
            if (!$source) throw new RuntimeException('Source cycle not found.');
            if ($db->fetch('SELECT id FROM admission_cycles WHERE code=:code OR slug=:slug',['code'=>$identity['code'],'slug'=>$identity['slug']])) throw new RuntimeException('Cycle code and slug must be unique.');
            $cycle=$source;
            unset($cycle['id']);
            $cycle=array_merge($cycle,[
                'academic_session_id'=>(int)($identity['academic_session_id']??$source['academic_session_id']),
                'name'=>trim((string)$identity['name']),'code'=>trim((string)$identity['code']),'slug'=>trim((string)$identity['slug']),
                'starts_at'=>date('Y-m-d H:i:s',strtotime((string)$identity['starts_at'])),'ends_at'=>date('Y-m-d H:i:s',strtotime((string)$identity['ends_at'])),'correction_deadline'=>!empty($identity['correction_deadline'])?date('Y-m-d H:i:s',strtotime((string)$identity['correction_deadline'])):null,
                'status'=>'draft','configuration_version'=>0,'published_at'=>null,'published_by'=>null,'closed_at'=>null,'archived_at'=>null,
                'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),
            ]);
            $newCycleId=$db->insert('admission_cycles',$cycle);
            $programMap=[];
            foreach ($db->all('SELECT * FROM cycle_programs WHERE admission_cycle_id=:cycle ORDER BY id',['cycle'=>$sourceId]) as $program) {
                $old=(int)$program['id']; unset($program['id']); $program['admission_cycle_id']=$newCycleId; $program['created_at']=$program['updated_at']=date('Y-m-d H:i:s');
                $programMap[$old]=$db->insert('cycle_programs',$program);
            }
            foreach ($programMap as $old=>$new) {
                foreach ($db->all('SELECT * FROM eligibility_rules WHERE cycle_program_id=:id',['id'=>$old]) as $row) { unset($row['id']); $row['cycle_program_id']=$new; $row['created_at']=date('Y-m-d H:i:s'); $db->insert('eligibility_rules',$row); }
                foreach ($db->all('SELECT * FROM seat_matrix WHERE cycle_program_id=:id',['id'=>$old]) as $row) { unset($row['id']); $row['cycle_program_id']=$new; $row['filled_seats']=0; $row['created_at']=$row['updated_at']=date('Y-m-d H:i:s'); $db->insert('seat_matrix',$row); }
                foreach ($db->all('SELECT * FROM admission_fee_rules WHERE cycle_program_id=:id',['id'=>$old]) as $row) { unset($row['id']); $row['cycle_program_id']=$new; $row['created_at']=$row['updated_at']=date('Y-m-d H:i:s'); $db->insert('admission_fee_rules',$row); }
            }
            foreach ($db->all('SELECT * FROM cycle_document_requirements WHERE admission_cycle_id=:cycle',['cycle'=>$sourceId]) as $row) { unset($row['id']); $row['admission_cycle_id']=$newCycleId; $row['created_at']=date('Y-m-d H:i:s'); $db->insert('cycle_document_requirements',$row); }
            $sectionMap=[];
            foreach ($db->all('SELECT * FROM admission_form_sections WHERE admission_cycle_id=:cycle ORDER BY id',['cycle'=>$sourceId]) as $row) { $old=(int)$row['id']; unset($row['id']); $row['admission_cycle_id']=$newCycleId; $row['created_at']=$row['updated_at']=date('Y-m-d H:i:s'); $sectionMap[$old]=$db->insert('admission_form_sections',$row); }
            foreach ($db->all('SELECT * FROM admission_form_fields WHERE admission_cycle_id=:cycle ORDER BY id',['cycle'=>$sourceId]) as $row) { unset($row['id']); $row['admission_cycle_id']=$newCycleId; $row['section_id']=$sectionMap[(int)$row['section_id']]; $row['created_at']=$row['updated_at']=date('Y-m-d H:i:s'); $db->insert('admission_form_fields',$row); }
            return $newCycleId;
        });
        AuditService::log('admission_cycle_duplicated','admission_cycle',$newId,['source_id'=>$sourceId],['actor_id'=>$actorId]);
        return $newId;
    }

    public function configurationPayload(int $cycleId): array
    {
        $db=Database::get();
        $cycle=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id',['id'=>$cycleId]);
        if (!$cycle) throw new RuntimeException('Admission cycle not found.');
        $queries=[
            'programs'=>"SELECT cp.*,p.code AS program_code,p.name AS program_name FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.admission_cycle_id=:cycle ORDER BY p.sort_order,p.name",
            'eligibility_rules'=>"SELECT er.* FROM eligibility_rules er JOIN cycle_programs cp ON cp.id=er.cycle_program_id WHERE cp.admission_cycle_id=:cycle ORDER BY er.cycle_program_id,er.sort_order,er.id",
            'documents'=>"SELECT cdr.*,dt.code AS document_code,dt.name AS document_name,dt.allowed_mimes,dt.max_size_mb FROM cycle_document_requirements cdr JOIN document_types dt ON dt.id=cdr.document_type_id WHERE cdr.admission_cycle_id=:cycle ORDER BY cdr.sort_order,cdr.id",
            'seats'=>"SELECT sm.* FROM seat_matrix sm JOIN cycle_programs cp ON cp.id=sm.cycle_program_id WHERE cp.admission_cycle_id=:cycle ORDER BY sm.cycle_program_id,sm.category,sm.quota",
            'form_sections'=>'SELECT * FROM admission_form_sections WHERE admission_cycle_id=:cycle ORDER BY sort_order,id',
            'form_fields'=>'SELECT * FROM admission_form_fields WHERE admission_cycle_id=:cycle ORDER BY section_id,sort_order,id',
            'fees'=>"SELECT afr.* FROM admission_fee_rules afr JOIN cycle_programs cp ON cp.id=afr.cycle_program_id WHERE cp.admission_cycle_id=:cycle ORDER BY afr.cycle_program_id,afr.fee_type,afr.id",
        ];
        $payload=['cycle'=>$cycle];
        foreach ($queries as $key=>$sql) $payload[$key]=$db->all($sql,['cycle'=>$cycleId]);
        return $payload;
    }

    private function changeStoredState(int $cycleId, string $to, int $actorId): void
    {
        if (!in_array($to,['closed','archived'],true)) throw new RuntimeException('Invalid cycle state.');
        $db=Database::get();
        $old=$db->transaction(function (Database $db) use ($cycleId,$to): string {
            $cycle=$db->fetch('SELECT * FROM admission_cycles WHERE id=:id FOR UPDATE',['id'=>$cycleId]);
            if (!$cycle) throw new RuntimeException('Admission cycle not found.');
            if ($to==='closed'&&!in_array($cycle['status'],['published','open'],true)) throw new RuntimeException('Only a published cycle can be closed.');
            if ($to==='archived'&&$cycle['status']!=='closed') throw new RuntimeException('Close the cycle before archiving it.');
            $updates=['status'=>$to,'updated_at'=>date('Y-m-d H:i:s')];
            $updates[$to==='closed'?'closed_at':'archived_at']=date('Y-m-d H:i:s');
            $db->update('admission_cycles',$updates,'id=:id',['id'=>$cycleId]);
            return (string)$cycle['status'];
        });
        AuditService::log('admission_cycle_'.$to,'admission_cycle',$cycleId,['status'=>$old],['status'=>$to,'actor_id'=>$actorId]);
    }
}
