<?php

namespace App\Http\Services;

// Service to handle versioning logic, e.g., generating next version strings

class VersioningService
{
    public static function next(string $parent, ?string $latest): string
    {
        // Handle null safely
        if ($latest === null) {
            if (preg_match('/^V(\d+)$/', $parent, $matches)) {
                $next = (int)$matches[1] + 1;
                return "V{$next}";
            }
            return $parent . 'A';
        }

        $pattern = '/^V(\d+)([A-Z]*)(\d*)$/';
        if (!preg_match($pattern, $latest, $matches)) {
            // fallback
            return $parent . 'A';
        }

        $base = $matches[1];     // number part
        $letters = $matches[2];  // letters (e.g. "A", "B")
        $digits = $matches[3];   // digits (e.g. "1")

        // Case 1: same as parent → branch with A
        if ($latest === $parent) {
            return $parent . 'A';
        }

        // Case 2: has letters, no digits → branch into digit 1
        if ($letters && $digits === '') {
            $baseInt = (int)$base;
            if ($baseInt === 1) {
                return "V{$base}{$letters}1";
            } else {
                if ($letters === 'A') {
                    return "V{$base}B";
                } else {
                    return "V{$base}{$letters}1";
                }
            }
        }

        // Case 3: has letters and digits → increment digit
        if ($letters && $digits !== '') {
            $nextDigit = (int)$digits + 1;
            return "V{$base}{$letters}{$nextDigit}";
        }

        // Case 4: no letters → branch with A
        return "V{$base}A";
    }
}