<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Strategy;

use DataVeil\Strategy\SeedDeriver;
use PHPUnit\Framework\TestCase;

class SeedDeriverTest extends TestCase
{
    public function testSameInputsProduceIdenticalSeed(): void
    {
        $deriver = new SeedDeriver('key-a');
        $this->assertSame(
            $deriver->derive('ivanov@secret-corp.ru', null),
            $deriver->derive('ivanov@secret-corp.ru', null),
        );
    }

    public function testDifferentKeysProduceDifferentSeeds(): void
    {
        $a = (new SeedDeriver('key-a'))->derive('value', null);
        $b = (new SeedDeriver('key-b'))->derive('value', null);
        $this->assertNotSame($a, $b);
    }

    public function testBothNullReturnsNull(): void
    {
        $this->assertNull((new SeedDeriver('k'))->derive(null, null));
    }

    public function testSaltTakesPrecedenceOverOriginalValue(): void
    {
        $deriver = new SeedDeriver('k');
        $this->assertSame(
            $deriver->derive('ignored', 'pk-1'),
            $deriver->derive('different', 'pk-1'),
        );
        $this->assertNotSame(
            $deriver->derive('value-1', null),
            $deriver->derive('value-2', null),
        );
    }

    public function testSeedIs64HexChars(): void
    {
        $seed = (new SeedDeriver('k'))->derive('x', null);
        $this->assertIsString($seed);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $seed);
    }
}
