<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class ApplicationNumberService
{
    public function generate(Database $db, array $application, array $cycle, array $primaryProgram): string
    {
        $year = date('y', strtotime((string) $cycle['starts_at']));
        $program = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($primaryProgram['code'] ?? 'GEN')) ?: 'GEN');
        $counterKey = $year . ':' . $program;
        $db->query("INSERT INTO application_number_counters (admission_cycle_id,counter_key,last_sequence,updated_at)
            VALUES (:cycle,:counter,0,:updated) ON DUPLICATE KEY UPDATE updated_at=updated_at", [
            'cycle'=>$cycle['id'],'counter'=>$counterKey,'updated'=>date('Y-m-d H:i:s'),
        ]);
        $counter=$db->fetch('SELECT * FROM application_number_counters WHERE admission_cycle_id=:cycle AND counter_key=:counter FOR UPDATE',['cycle'=>$cycle['id'],'counter'=>$counterKey]);
        if (!$counter) throw new RuntimeException('Application number counter could not be locked.');
        $format=(string)($db->scalar("SELECT value FROM settings WHERE key_name='application_number_format'") ?: '{PREFIX}-{SEQUENCE}');
        $sequence=(int)$counter['last_sequence'];
        do {
            $sequence++;
            $number=strtr($format,[
                '{PREFIX}'=>(string)$cycle['application_number_prefix'],
                '{YEAR}'=>$year,
                '{PROGRAM}'=>$program,
                '{CYCLE}'=>strtoupper((string)$cycle['code']),
                '{SEQUENCE}'=>str_pad((string)$sequence,6,'0',STR_PAD_LEFT),
            ]);
            $number=preg_replace('/\s+/','-',trim($number)) ?: '';
            if (mb_strlen($number)>60) throw new RuntimeException('Configured application number format exceeds 60 characters.');
            $exists=(int)$db->scalar('SELECT COUNT(*) FROM applications WHERE application_number=:number AND id<>:id',['number'=>$number,'id'=>$application['id']]);
        } while ($exists>0 && $sequence<(int)$counter['last_sequence']+1000);
        if ($exists>0) throw new RuntimeException('Could not generate a unique application number.');
        $db->update('application_number_counters',['last_sequence'=>$sequence,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$counter['id']]);
        return $number;
    }
}
