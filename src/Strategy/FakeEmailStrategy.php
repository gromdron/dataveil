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
        if ($salt === null) {
            return '';
        }

        return "user_" . substr($salt, 0, 16) . "@fake.local";
    }
}
