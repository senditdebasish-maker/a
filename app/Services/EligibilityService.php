<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use RuntimeException;

final class EligibilityService
{
    public function evaluate(int $applicationId): array
    {
        $db = Database::get();
        $application = $db->fetch("SELECT a.*, ac.starts_at, ap.date_of_birth, ap.category, ap.gender, ap.nationality
            FROM applications a
            JOIN admission_cycles ac ON ac.id = a.admission_cycle_id
            LEFT JOIN applicant_profiles ap ON ap.user_id = a.user_id
            WHERE a.id = :id LIMIT 1", ['id' => $applicationId]);
        if (!$application) throw new RuntimeException('Application not found.');

        $education = [];
        foreach ($db->all('SELECT * FROM education_records WHERE application_id = :id', ['id' => $applicationId]) as $row) {
            $education[(string) $row['level']] = $row;
        }
        $exam = $db->fetch('SELECT * FROM entrance_exams WHERE application_id = :id ORDER BY id LIMIT 1', ['id' => $applicationId]) ?: [];
        $custom = [];
        foreach ($db->all('SELECT aff.field_key, afr.value_text, afr.value_json FROM application_field_responses afr JOIN admission_form_fields aff ON aff.id = afr.form_field_id WHERE afr.application_id = :id', ['id' => $applicationId]) as $row) {
            $custom[$row['field_key']] = $row['value_json'] !== null ? json_decode((string) $row['value_json'], true) : $row['value_text'];
        }
        $preferences = $db->all("SELECT pref.preference_order, cp.*, p.name AS program_name, p.code AS program_code
            FROM application_preferences pref
            JOIN cycle_programs cp ON cp.id = pref.cycle_program_id
            JOIN programs p ON p.id = cp.program_id
            WHERE pref.application_id = :id AND cp.admission_cycle_id = :cycle AND cp.status = 'active'
            ORDER BY pref.preference_order", ['id' => $applicationId, 'cycle' => $application['admission_cycle_id']]);
        if (!$preferences) throw new RuntimeException('No valid programme preference is configured.');

        $programResults = [];
        foreach ($preferences as $program) {
            $rules = $db->all('SELECT * FROM eligibility_rules WHERE cycle_program_id = :id ORDER BY sort_order, id', ['id' => $program['id']]);
            $flags = [];
            foreach ($rules as $rule) {
                $actual = $this->resolveValue((string) $rule['field_name'], $application, $education, $exam, $custom);
                $expected = (string) $rule['comparison_value'];
                if ($rule['rule_type'] === 'marks' && $rule['field_name'] === 'class_12_percentage') {
                    $reserved = !$this->isGeneralCategory((string) ($application['category'] ?? ''));
                    $configured = $reserved ? $program['minimum_marks_reserved'] : $program['minimum_marks_general'];
                    if ($configured !== null) $expected = (string) $configured;
                }
                if ($rule['rule_type'] === 'age' && $rule['field_name'] === 'age_on_cutoff' && $program['min_age'] !== null) {
                    $expected = (string) $program['min_age'];
                }
                $passed = $this->compare($actual, (string) $rule['operator'], $expected);
                $flags[] = $this->flag((string) $rule['message'], $passed, (bool) $rule['is_blocking'], $actual, $expected, (string) $rule['field_name'], (string) $rule['operator']);
            }
            if (!$rules) {
                $flags = $this->fallbackFlags($application, $education, $program);
            }
            if ($program['max_age'] !== null) {
                $age = $this->ageOn((string) ($application['date_of_birth'] ?? ''), (string) $application['starts_at']);
                $flags[] = $this->flag('Maximum age at cycle opening', $age !== null && $age <= (int) $program['max_age'], true, $age, (string) $program['max_age'], 'age_on_cutoff', 'lte');
            }
            $blockingFailure = count(array_filter($flags, static fn (array $flag): bool => !$flag['passed'] && $flag['blocking'])) > 0;
            $anyFailure = count(array_filter($flags, static fn (array $flag): bool => !$flag['passed'])) > 0;
            $programResults[] = [
                'cycle_program_id' => (int) $program['id'], 'program_code' => $program['program_code'],
                'program_name' => $program['program_name'], 'preference_order' => (int) $program['preference_order'],
                'status' => $blockingFailure ? 'ineligible' : ($anyFailure ? 'needs_review' : 'eligible'), 'flags' => $flags,
            ];
        }

        $statuses = array_column($programResults, 'status');
        $status = in_array('eligible', $statuses, true) ? 'eligible' : (in_array('needs_review', $statuses, true) ? 'needs_review' : 'ineligible');
        $encoded = json_encode(['programs' => $programResults], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->update('applications', ['eligibility_status' => $status, 'eligibility_flags' => $encoded, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $applicationId]);
        return ['status' => $status, 'programs' => $programResults, 'flags' => $programResults[0]['flags'] ?? []];
    }

    private function resolveValue(string $field, array $application, array $education, array $exam, array $custom): mixed
    {
        return match ($field) {
            'class_10_percentage' => $education['class_10']['percentage'] ?? null,
            'class_12_percentage' => $education['class_12']['percentage'] ?? null,
            'class_10_subjects' => $education['class_10']['subjects'] ?? null,
            'class_12_subjects' => $education['class_12']['subjects'] ?? null,
            'age_on_cutoff' => $this->ageOn((string) ($application['date_of_birth'] ?? ''), (string) $application['starts_at']),
            'category', 'gender', 'nationality' => $application[$field] ?? null,
            'entrance_exam_name' => $exam['exam_name'] ?? null,
            'entrance_exam_rank' => $exam['rank_score'] ?? null,
            'entrance_exam_percentile' => $exam['percentile'] ?? null,
            default => $custom[$field] ?? null,
        };
    }

    private function compare(mixed $actual, string $operator, string $expected): bool
    {
        if ($actual === null || $actual === '' || (is_array($actual) && !$actual)) return false;
        $operator = strtolower(trim($operator));
        return match ($operator) {
            'eq', '=', '==' => mb_strtolower(trim((string) $actual)) === mb_strtolower(trim($expected)),
            'neq', '!=', '<>' => mb_strtolower(trim((string) $actual)) !== mb_strtolower(trim($expected)),
            'gt', '>' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte', '>=' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt', '<' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte', '<=' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'in' => in_array(mb_strtolower(trim((string) $actual)), array_map(static fn (string $v): string => mb_strtolower(trim($v)), explode(',', $expected)), true),
            'not_in' => !in_array(mb_strtolower(trim((string) $actual)), array_map(static fn (string $v): string => mb_strtolower(trim($v)), explode(',', $expected)), true),
            'contains' => $this->containsGroups((string) $actual, $expected),
            'between' => $this->between($actual, $expected),
            'regex' => @preg_match($expected, (string) $actual) === 1,
            default => false,
        };
    }

    private function containsGroups(string $actual, string $expected): bool
    {
        $haystack = mb_strtolower($actual);
        foreach (array_filter(array_map('trim', explode(',', $expected))) as $group) {
            $alternatives = array_filter(array_map('trim', explode('|', $group)));
            $matched = false;
            foreach ($alternatives as $alternative) {
                if (str_contains($haystack, mb_strtolower($alternative))) { $matched = true; break; }
            }
            if (!$matched) return false;
        }
        return true;
    }

    private function between(mixed $actual, string $expected): bool
    {
        $range = array_map('trim', explode(',', $expected));
        return count($range) === 2 && is_numeric($actual) && is_numeric($range[0]) && is_numeric($range[1])
            && (float) $actual >= (float) $range[0] && (float) $actual <= (float) $range[1];
    }

    private function fallbackFlags(array $application, array $education, array $program): array
    {
        $reserved = !$this->isGeneralCategory((string) ($application['category'] ?? ''));
        $threshold = $reserved ? $program['minimum_marks_reserved'] : $program['minimum_marks_general'];
        $percentage = $education['class_12']['percentage'] ?? null;
        $age = $this->ageOn((string) ($application['date_of_birth'] ?? ''), (string) $application['starts_at']);
        $flags = [];
        if ($threshold !== null) $flags[] = $this->flag('Class 12 minimum marks', $percentage !== null && (float) $percentage >= (float) $threshold, true, $percentage, (string) $threshold, 'class_12_percentage', 'gte');
        if ($program['min_age'] !== null) $flags[] = $this->flag('Minimum age at cycle opening', $age !== null && $age >= (int) $program['min_age'], true, $age, (string) $program['min_age'], 'age_on_cutoff', 'gte');
        return $flags;
    }

    private function ageOn(string $birthDate, string $cutoff): ?int
    {
        if ($birthDate === '' || $cutoff === '') return null;
        try { return (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable($cutoff))->y; }
        catch (\Throwable) { return null; }
    }

    private function isGeneralCategory(string $category): bool
    {
        return in_array(mb_strtolower(trim($category)), ['general','unreserved','ur'], true);
    }

    private function flag(string $label, bool $passed, bool $blocking, mixed $actual, string $expected, string $field, string $operator): array
    {
        return [
            'label' => $label, 'passed' => $passed, 'blocking' => $blocking,
            'actual' => $actual === null || $actual === '' ? 'Missing' : (is_array($actual) ? json_encode($actual, JSON_UNESCAPED_UNICODE) : (string) $actual),
            'expected' => $expected, 'field' => $field, 'operator' => $operator,
        ];
    }
}
