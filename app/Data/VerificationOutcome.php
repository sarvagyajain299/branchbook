<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\VerificationStatus;

final readonly class VerificationOutcome
{
    public function __construct(
        public VerificationStatus $status,
        public ?string $manageToken = null,
        public int $attemptsLeft = 0,
    ) {}
}
