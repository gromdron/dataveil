<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

use DataVeil\Config\Configuration;

class StrategyManager
{
    /**
     * @var array<string, class-string<StrategyInterface>>
     */
    private static array $typeMap = [
        'email_fake' => FakeEmailStrategy::class,
        'phone_fake' => FakePhoneStrategy::class,
        'name_fake' => FakeNameStrategy::class,
        'lastname_fake' => FakeLastnameStrategy::class,
        'string_random' => StringRandomStrategy::class,
        'empty' => EmptyStringStrategy::class,
    ];

    /**
     * @var array<string, StrategyInterface>
     */
    private array $strategies = [];

    private readonly SeedDeriver $deriver;

    /**
     * @param array<int, array{name: string, type: string, options?: array<string, mixed>}> $customStrategies
     */
    public function __construct(array $customStrategies = [], ?SeedDeriver $deriver = null)
    {
        $this->deriver = $deriver ?? new SeedDeriver(Configuration::DEFAULT_SECRET_KEY);

        foreach (self::$typeMap as $type => $class) {
            $this->strategies[$type] = new $class($type);
        }

        foreach ($customStrategies as $definition) {
            $this->registerFromConfig($definition);
        }
    }

    /**
     * @param array{name: string, type: string, options?: array<string, mixed>} $definition
     */
    public function registerFromConfig(array $definition): void
    {
        $name = $definition['name'];
        $type = $definition['type'];
        $options = $definition['options'] ?? [];

        if (!isset(self::$typeMap[$type])) {
            throw new \InvalidArgumentException("Unknown strategy type: '{$type}'");
        }

        $class = self::$typeMap[$type];
        $this->strategies[$name] = new $class($name, $options);
    }

    public function register(string $name, StrategyInterface $strategy): void
    {
        $this->strategies[$name] = $strategy;
    }

    public function getStrategy(string $strategyName): ?StrategyInterface
    {
        return $this->strategies[$strategyName] ?? null;
    }

    /**
     * @return array<string, class-string<StrategyInterface>>
     */
    public static function getTypeMap(): array
    {
        return self::$typeMap;
    }

    public function generate(string $strategyName, mixed $originalValue, ?string $salt = null): string
    {
        $strategy = $this->getStrategy($strategyName);

        if ($strategy === null) {
            throw new \InvalidArgumentException("Unknown strategy: {$strategyName}");
        }

        $seed = $this->deriver->derive($originalValue, $salt);

        return $strategy->generate($originalValue, $seed);
    }
}
