<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): array
    {
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            foreach (explode('|', $fieldRules) as $rule) {
                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name !== 'required' && ($value === null || $value === '')) {
                    continue;
                }
                $failed = match ($name) {
                    'required' => $value === null || trim((string) $value) === '',
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false,
                    'min' => mb_strlen((string) $value) < (int) $parameter,
                    'max' => mb_strlen((string) $value) > (int) $parameter,
                    'numeric' => !is_numeric($value),
                    'date' => strtotime((string) $value) === false,
                    'in' => !in_array((string) $value, explode(',', (string) $parameter), true),
                    'confirmed' => $value !== ($data[$field . '_confirmation'] ?? null),
                    default => false,
                };
                if ($failed) {
                    $this->errors[$field][] = $this->message($field, $name, $parameter);
                    break;
                }
            }
        }
        return $this->errors;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function message(string $field, string $rule, ?string $parameter): string
    {
        $label = ucfirst(str_replace('_', ' ', $field));
        return match ($rule) {
            'required' => "{$label} is required.",
            'email' => "Enter a valid {$label}.",
            'min' => "{$label} must contain at least {$parameter} characters.",
            'max' => "{$label} may not exceed {$parameter} characters.",
            'numeric' => "{$label} must be a number.",
            'date' => "Enter a valid {$label}.",
            'in' => "Select a valid {$label}.",
            'confirmed' => "{$label} confirmation does not match.",
            default => "{$label} is invalid.",
        };
    }
}
