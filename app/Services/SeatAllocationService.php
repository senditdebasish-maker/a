<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class SeatAllocationService
{
    public function allocate(Database $db, array $application, int $cycleProgramId, string $category, string $quota, int $actorId): array
    {
        $program=$db->fetch("SELECT cp.*,p.name AS program_name,p.code AS program_code FROM cycle_programs cp JOIN programs p ON p.id=cp.program_id WHERE cp.id=:id AND cp.status='active' FOR UPDATE",['id'=>$cycleProgramId]);
        if (!$program||(int)$program['admission_cycle_id']!==(int)$application['admission_cycle_id']) throw new RuntimeException('Selected programme does not belong to this application cycle.');
        $preference=(int)$db->scalar('SELECT COUNT(*) FROM application_preferences WHERE application_id=:application AND cycle_program_id=:program',['application'=>$application['id'],'program'=>$cycleProgramId]);
        if ($preference!==1) throw new RuntimeException('A seat can be allocated only to a ranked programme choice.');
        $active=$db->fetch('SELECT * FROM seat_allocations WHERE application_id=:application AND is_active=1 FOR UPDATE',['application'=>$application['id']]);
        if ($active) {
            if ((int)$active['cycle_program_id']===$cycleProgramId&&(string)$active['category']===$category&&(string)$active['quota']===$quota) return $active;
            throw new RuntimeException('Release the existing active seat allocation before changing programme or category.');
        }
        $seat=$db->fetch('SELECT * FROM seat_matrix WHERE cycle_program_id=:program AND category=:category AND quota=:quota FOR UPDATE',['program'=>$cycleProgramId,'category'=>$category,'quota'=>$quota]);
        if (!$seat) throw new RuntimeException('No seat matrix row exists for the selected programme, category, and quota.');
        if ((int)$seat['filled_seats']>=(int)$seat['seats']) throw new RuntimeException('No configured seat remains in the selected category and quota.');
        $id=$db->insert('seat_allocations',[
            'application_id'=>$application['id'],'cycle_program_id'=>$cycleProgramId,'seat_matrix_id'=>$seat['id'],'category'=>$category,'quota'=>$quota,
            'status'=>'reserved','is_active'=>1,'allocated_by'=>$actorId,'allocated_at'=>date('Y-m-d H:i:s'),'confirmed_at'=>null,
            'released_by'=>null,'released_at'=>null,'release_reason'=>null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s'),
        ]);
        $db->update('seat_matrix',['filled_seats'=>(int)$seat['filled_seats']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$seat['id']]);
        $db->update('applications',['selected_cycle_program_id'=>$cycleProgramId,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$application['id']]);
        return $db->fetch('SELECT * FROM seat_allocations WHERE id=:id',['id'=>$id]) ?: throw new RuntimeException('Seat allocation could not be loaded.');
    }

    public function confirm(Database $db, int $applicationId): void
    {
        $allocation=$db->fetch('SELECT * FROM seat_allocations WHERE application_id=:application AND is_active=1 FOR UPDATE',['application'=>$applicationId]);
        if (!$allocation) throw new RuntimeException('Admission cannot be completed without an active seat allocation.');
        $db->update('seat_allocations',['status'=>'confirmed','confirmed_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$allocation['id']]);
    }

    public function release(Database $db, int $applicationId, int $actorId, string $reason): void
    {
        $allocation=$db->fetch('SELECT * FROM seat_allocations WHERE application_id=:application AND is_active=1 FOR UPDATE',['application'=>$applicationId]);
        if (!$allocation) return;
        $seat=$db->fetch('SELECT * FROM seat_matrix WHERE id=:id FOR UPDATE',['id'=>$allocation['seat_matrix_id']]);
        $db->update('seat_allocations',['status'=>'released','is_active'=>null,'released_by'=>$actorId,'released_at'=>date('Y-m-d H:i:s'),'release_reason'=>$reason,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$allocation['id']]);
        if ($seat) $db->update('seat_matrix',['filled_seats'=>max(0,(int)$seat['filled_seats']-1),'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$seat['id']]);
        $db->update('applications',['selected_cycle_program_id'=>null,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$applicationId]);
    }
}
