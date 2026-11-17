<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * Builds an iCalendar (.ics, RFC 5545) file by hand. Format notes:
 *  - plain text, one "NAME:value" property per line, lines end with CRLF;
 *  - times are written in UTC with a trailing Z (20261207T050000Z), so no timezone
 *    definitions are needed and every calendar app converts to the user's zone;
 *  - UID must be globally unique and stable, so re-importing updates instead of duplicating;
 *  - , ; \ and newlines inside values must be escaped; lines over 75 bytes are folded
 *    (continued on the next line starting with a space).
 */
final class IcsBuilder
{
    public static function for(Appointment $appointment): string
    {
        $branch = $appointment->branch;
        $service = $appointment->service;
        $documents = implode('; ', $service->required_documents);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Northwind Bank//BranchBook//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$appointment->reference.'@branchbook.northwind.test',
            'DTSTAMP:'.self::utc(CarbonImmutable::now()),
            'DTSTART:'.self::utc($appointment->starts_at),
            'DTEND:'.self::utc($appointment->ends_at),
            'SUMMARY:'.self::escape('Northwind Bank: '.$service->name),
            'LOCATION:'.self::escape($branch->name.', '.$branch->address),
            'DESCRIPTION:'.self::escape("Booking reference {$appointment->reference}. Please bring: {$documents}"),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function utc(CarbonImmutable $time): string
    {
        return $time->utc()->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
    }

    private static function fold(string $line): string
    {
        $out = [];
        while (strlen($line) > 75) {
            // Cut on a character boundary, never in the middle of a UTF-8 sequence.
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out[] = substr($line, 0, $cut);
            $line = ' '.substr($line, $cut);
        }
        $out[] = $line;

        return implode("\r\n", $out);
    }
}
