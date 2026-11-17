<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpResult;
use App\Models\OtpCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-time codes for customers, who have no passwords. Only a salted hash of the code is
 * stored. A code expires after 10 minutes and allows 5 guesses in total.
 */
final class OtpService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** Creates a fresh code (invalidating older ones for the same email and purpose) and returns the plain code. */
    public function issue(string $email, string $purpose): string
    {
        // 000000-999999 with leading zeros. random_int is cryptographically secure; rand() is not.
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($email, $purpose, $code): void {
            OtpCode::query()->where('email', $email)->where('purpose', $purpose)->whereNull('consumed_at')
                ->update(['consumed_at' => CarbonImmutable::now()]);

            OtpCode::query()->create([
                'email' => $email,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'expires_at' => CarbonImmutable::now()->addMinutes(self::TTL_MINUTES),
                'attempts' => 0,
            ]);
        });

        return $code;
    }

    /** @return array{0: OtpResult, 1: int} the result and the guesses left afterwards */
    public function verify(string $email, string $purpose, string $code, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($email, $purpose, $code, $now): array {
            // Lock the row so two parallel guesses can't both count as "attempt 1".
            $otp = OtpCode::query()->where('email', $email)->where('purpose', $purpose)
                ->whereNull('consumed_at')->latest('id')->lockForUpdate()->first();

            if ($otp === null) {
                return [OtpResult::Missing, 0];
            }

            if ($otp->expires_at->lessThanOrEqualTo($now)) {
                return [OtpResult::Expired, 0];
            }

            if ($otp->attempts >= self::MAX_ATTEMPTS) {
                return [OtpResult::LockedOut, 0];
            }

            // Count the attempt BEFORE checking, so a crash can't give a free guess.
            $otp->attempts++;
            $otp->save();
            $left = self::MAX_ATTEMPTS - $otp->attempts;

            // Hash::check compares in constant time, so response timing reveals nothing.
            if (! Hash::check($code, $otp->code_hash)) {
                return [$left === 0 ? OtpResult::LockedOut : OtpResult::Wrong, $left];
            }

            $otp->consumed_at = $now;
            $otp->save();

            return [OtpResult::Verified, $left];
        });
    }

    /** The purpose string ties a code to ONE booking, so a code for booking A can't confirm booking B. */
    public static function purposeFor(string $reference): string
    {
        return 'booking:'.$reference;
    }
}
