<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

interface StrategyInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $strategyName, array $options = []);
    public function generate(mixed $originalValue, ?string $salt = null): string;
    public function supports(string $strategyName): bool;
}
