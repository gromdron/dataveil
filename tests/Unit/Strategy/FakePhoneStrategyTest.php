<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Strategy;

use DataVeil\Strategy\FakePhoneStrategy;
use PHPUnit\Framework\TestCase;

class FakePhoneStrategyTest extends TestCase
{
    private FakePhoneStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new FakePhoneStrategy();
    }

    public function testIdsCongruentMod100ProduceDifferentPhones(): void
    {
        $phones = [
            $this->strategy->generate(null, '42'),
            $this->strategy->generate(null, '142'),
            $this->strategy->generate(null, '10042'),
        ];

        $this->assertCount(3, array_unique($phones));
    }

    public function testSequentialIdsProduceUniquePhones(): void
    {
        $count = 10000;
        $phones = [];

        for ($id = 0; $id < $count; $id++) {
            $phones[] = $this->strategy->generate(null, (string) $id);
        }

        $this->assertCount($count, array_unique($phones));
    }

    public function testGeneratesValidRussianMobileFormat(): void
    {
        $phone = $this->strategy->generate(null, '123456');

        $this->assertMatchesRegularExpression('/^\+7\d{10}$/', $phone);
    }

    public function testSameIdProducesSamePhone(): void
    {
        $first = $this->strategy->generate(null, '55');
        $second = $this->strategy->generate(null, '55');

        $this->assertSame($first, $second);
    }

    public function testDifferentNumericOriginalValuesProduceDifferentPhones(): void
    {
        $first = $this->strategy->generate(1000, null);
        $second = $this->strategy->generate(2000, null);

        $this->assertNotSame($first, $second);
    }

    public function testNullOriginalValueWithoutSaltReturnsEmptyString(): void
    {
        $this->assertSame('', $this->strategy->generate(null, null));
    }
}
