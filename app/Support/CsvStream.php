<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a CSV download row by row, so a huge export never has to fit in memory.
 * Cells that start with = + - @ (or a tab/CR) are prefixed with an apostrophe: spreadsheet apps
 * would otherwise run them as formulas ("CSV injection").
 */
final class CsvStream
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int|string, mixed>>  $rows  an array or a lazy collection/generator
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 byte order mark so Excel reads non-ASCII text correctly
            fputcsv($out, $headers, ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), array_values($row)), ',', '"', '\\');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public static function safe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
