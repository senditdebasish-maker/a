<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

final class EligibilityService
{
    public function evaluate(int $applicationId): array
    {
        $db = Database::get();
        $record = $db->fetch("SELECT a.id, a.user_id, ac.starts_at, cp.id AS cycle_program_id, cp.minimum_marks_general, cp.minimum_marks_reserved, cp.min_age,
            ap.date_of_birth, ap.category, er.percentage, er.subjects
            FROM applications a
            JOIN admission_cycles ac ON ac.id = a.admission_cycle_id
            LEFT JOIN application_preferences pref ON pref.application_id = a.id AND pref.preference_order = 1
            LEFT JOIN cycle_programs cp ON cp.id = pref.cycle_program_id
            LEFT JOIN applicant_profiles ap ON ap.user_id = a.user_id
            LEFT JOIN education_records er ON er.application_id = a.id AND er.level = 'class_12'
            WHERE a.id = :id LIMIT 1", ['id' => $applicationId]);
        if (!$record) throw new \RuntimeException('Application not found.');

        $flags = [];
        $category = strtolower((string) ($record['category'] ?? ''));
        $reserved = $category !== '' && !in_array($category, ['general','unreserved','ur'], true);
        $threshold = (float) ($reserved ? $record['minimum_marks_reserved'] : $record['minimum_marks_general']);
        $percentage = $record['percentage'] !== null ? (float) $record['percentage'] : null;
        $flags[] = $this->flag('Class 12 minimum marks', $percentage !== null && $percentage >= $threshold, true, $percentage === null ? 'Missing' : $percentage . '%', $threshold . '%');

        $subjects = mb_strtolower((string) ($record['subjects'] ?? ''));
        $hasPhysics = str_contains($subjects, 'physics');
        $hasChemistry = str_contains($subjects, 'chemistry');
        $hasMathOrBiology = str_contains($subjects, 'math') || str_contains($subjects, 'biology') || str_contains($subjects, 'biological');
        $flags[] = $this->flag('Required Class 12 subjects', $hasPhysics && $hasChemistry && $hasMathOrBiology, true, $record['subjects'] ?: 'Missing', 'Physics, Chemistry, and Mathematics or Biology');

        $age = null;
        if (!empty($record['date_of_birth']) && !empty($record['starts_at'])) {
            $age = (new DateTimeImmutable((string) $record['date_of_birth']))->diff(new DateTimeImmutable((string) $record['starts_at']))->y;
        }
        $minAge = (int) ($record['min_age'] ?? 0);
        $flags[] = $this->flag('Minimum age at cycle opening', $minAge === 0 || ($age !== null && $age >= $minAge), $minAge > 0, $age === null ? 'Missing' : (string) $age, $minAge > 0 ? (string) $minAge : 'No minimum');

        foreach ($db->all('SELECT * FROM eligibility_rules WHERE cycle_program_id = :id ORDER BY sort_order, id', ['id' => $record['cycle_program_id']]) as $rule) {
            if (in_array($rule['field_name'], ['class_12_percentage','class_12_subjects','age_on_cutoff'], true)) continue;
            $flags[] = $this->flag($rule['message'], false, (bool) $rule['is_blocking'], 'Manual review required', $rule['comparison_value']);
        }

        $blockingFailure = count(array_filter($flags, static fn (array $flag): bool => !$flag['passed'] && $flag['blocking'])) > 0;
        $anyFailure = count(array_filter($flags, static fn (array $flag): bool => !$flag['passed'])) > 0;
        $status = $blockingFailure ? 'ineligible' : ($anyFailure ? 'needs_review' : 'eligible');
        $db->update('applications', ['eligibility_status' => $status, 'eligibility_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $applicationId]);
        return ['status' => $status, 'flags' => $flags];
    }

    private function flag(string $label, bool $passed, bool $blocking, string $actual, string $expected): array
    {
        return compact('label', 'passed', 'blocking', 'actual', 'expected');
    }
}
