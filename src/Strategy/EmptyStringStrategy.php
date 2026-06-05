<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class EmptyStringStrategy extends AbstractStrategy
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $strategyName = "empty", array $options = [])
    {
        parent::__construct($strategyName, $options);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        return "";
    }
}
