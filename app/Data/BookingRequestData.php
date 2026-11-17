<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Branch;
use App\Models\Service;
use Carbon\CarbonImmutable;

/** Everything needed to hold a slot. Built from a validated request (or an API payload later). */
final readonly class BookingRequestData
{
    public function __construct(
        public Service $service,
        public Branch $branch,
        public CarbonImmutable $startsAt,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public bool $marketingOptIn = false,
    ) {}
}
