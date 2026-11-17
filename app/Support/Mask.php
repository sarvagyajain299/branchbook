<?php

declare(strict_types=1);

namespace App\Support;

/** Masks personal data for staff screens and logs. */
final class Mask
{
    /** "sarah.jones@example.com" -> "s***@example.com" */
    public static function email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($domain === '') {
            return '***';
        }

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /** "+971501234567" -> "+971 ••• ••• 567" (country code and last 3 digits only) */
    public static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 8) {
            return '••••';
        }

        return '+'.substr($digits, 0, 3).' ••• ••• '.substr($digits, -3);
    }
}
