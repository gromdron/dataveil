<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class SeedDeriver
{
    public function __construct(private readonly string $secretKey)
    {
    }

    public function derive(mixed $originalValue, ?string $salt): ?string
    {
        if ($salt === null && $originalValue === null) {
            return null;
        }

        return hash_hmac('sha256', $salt ?? (string) $originalValue, $this->secretKey);
    }
}
