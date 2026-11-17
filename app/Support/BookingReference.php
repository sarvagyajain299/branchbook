<?php

declare(strict_types=1);

namespace App\Support;

final class BookingReference
{
    /** No 0/O/1/I so a reference read aloud over the phone isn't misheard. */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** e.g. NWB-7KQ4-P2XD (32^8 ≈ 1.1 trillion combinations). */
    public static function generate(): string
    {
        return 'NWB-'.self::chunk().'-'.self::chunk();
    }

    private static function chunk(): string
    {
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
