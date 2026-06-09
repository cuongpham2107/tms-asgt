<?php

namespace App\Http\Requests\Concerns;

trait NormalizesDecimalInput
{
    protected function normalizeDecimal(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace(',', '.', $value);
        }

        return $value;
    }
}
