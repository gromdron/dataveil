<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

abstract class AbstractStrategy implements StrategyInterface
{
    protected string $strategyName;

    /**
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $strategyName, array $options = [])
    {
        $this->strategyName = $strategyName;
        $this->options = $options;
    }

    abstract public function generate(mixed $originalValue, ?string $salt = null): string;
    
    public function supports(string $strategyName): bool
    {
        return $this->strategyName === $strategyName;
    }
}
