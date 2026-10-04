<?php

namespace App\Services\Gps;

final readonly class DistanceResult
{
    public const SOURCE_PHONE = 'phone_gps';

    public const SOURCE_EUP = 'eup';

    public const SOURCE_MIXED = 'mixed';

    public function __construct(
        public float $km,
        public float $coverage,
        public ?string $source,
        public bool $hasMocked,
        public int $osrmFilledSeconds,
    ) {}

    public static function empty(): self
    {
        return new self(0.0, 0.0, null, false, 0);
    }
}
