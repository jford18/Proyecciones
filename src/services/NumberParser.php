<?php

declare(strict_types=1);

namespace App\services;

class NumberParser
{
    public function parse(mixed $value): array
    {
        if ($value === null) {
            return ['value' => 0.0, 'is_numeric' => true, 'had_content' => false];
        }

        if (is_int($value) || is_float($value)) {
            return ['value' => (float) $value, 'is_numeric' => true, 'had_content' => true];
        }

        $text = trim((string) $value);
        if ($text === '') {
            return ['value' => 0.0, 'is_numeric' => true, 'had_content' => false];
        }

        $normalized = str_replace(["\xc2\xa0", ' ', '.'], '', $text);
        $normalized = str_replace(',', '.', $normalized);

        if (!is_numeric($normalized)) {
            return ['value' => 0.0, 'is_numeric' => false, 'had_content' => true];
        }

        return ['value' => (float) $normalized, 'is_numeric' => true, 'had_content' => true];
    }
}
