<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class FakeEmailStrategy extends AbstractStrategy
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $strategyName = "email_fake", array $options = [])
    {
        parent::__construct($strategyName, $options);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        if ($salt !== null) {
            $id = $salt;
        } elseif ($originalValue === null) {
            return '';
        } elseif (is_numeric($originalValue)) {
            $id = $originalValue;
        } else {
            $id = crc32($originalValue);
        }

        return "user_{$id}@fake.local";
    }
}
