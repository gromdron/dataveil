<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Strategy;

use DataVeil\Strategy\SeedDeriver;
use DataVeil\Strategy\StrategyManager;
use DataVeil\Strategy\StringRandomStrategy;
use PHPUnit\Framework\TestCase;

class StrategyManagerTest extends TestCase
{
    private StrategyManager $strategyManager;

    protected function setUp(): void
    {
        $this->strategyManager = new StrategyManager();
    }

    public function testFakeEmailStrategy(): void
    {
        $email = $this->strategyManager->generate("email_fake", "user123");

        $this->assertStringContainsString("@fake.local", $email);
        $this->assertStringContainsString("user_", $email);
    }

    public function testFakePhoneStrategy(): void
    {
        $phone = $this->strategyManager->generate("phone_fake", "123456");

        $this->assertStringStartsWith("+7", $phone);
        $this->assertMatchesRegularExpression('/^\+7\d{3}\d{7}$/', $phone);
    }

    public function testFakeNameStrategy(): void
    {
        $name = $this->strategyManager->generate("name_fake", "1");
        $this->assertNotEmpty($name);
    }

    public function testFakeLastnameStrategy(): void
    {
        $lastname = $this->strategyManager->generate("lastname_fake", "1");

        $this->assertNotEmpty($lastname);
    }

    public function testStringRandomStrategy(): void
    {
        $strategy = new StringRandomStrategy(
            strategyName: "exactly8",
            options: ["prefix" => "usr_", "length" => 8],
        );
        $this->strategyManager->register("exactly8", $strategy);

        $result = $this->strategyManager->generate("exactly8", "123456");

        $this->assertStringStartsWith("usr_", $result);
        $this->assertEquals(12, strlen($result));
    }

    public function testEmptyGeneration(): void
    {
        $empty = $this->strategyManager->generate("empty", "test");
        $this->assertEmpty($empty);
    }

    public function testDeterministicGeneration(): void
    {
        $email1 = $this->strategyManager->generate(
            "email_fake",
            "test",
            salt: "55",
        );
        $email2 = $this->strategyManager->generate(
            "email_fake",
            "test",
            salt: "55",
        );

        $this->assertEquals($email1, $email2);
    }

    public function testUnknownStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->strategyManager->generate("unknown_strategy", "test");
    }

    public function testCustomStrategyFromConfig(): void
    {
        $manager = new StrategyManager([
            [
                'name' => 'user_login',
                'type' => 'string_random',
                'options' => ['length' => 10, 'prefix' => 'user_'],
            ],
        ]);

        $result = $manager->generate("user_login", "test123");

        $this->assertStringStartsWith("user_", $result);
        $this->assertEquals(15, strlen($result));
    }

    public function testCustomStrategyOverridesBuiltin(): void
    {
        $manager = new StrategyManager([
            [
                'name' => 'email_fake',
                'type' => 'string_random',
                'options' => ['length' => 4, 'prefix' => 'test_'],
            ],
        ]);

        $result = $manager->generate("email_fake", "value");

        $this->assertStringStartsWith("test_", $result);
        $this->assertEquals(9, strlen($result));
    }

    public function testBuiltinStrategiesStillAvailableWithCustom(): void
    {
        $manager = new StrategyManager([
            [
                'name' => 'my_string',
                'type' => 'string_random',
                'options' => ['length' => 5, 'prefix' => ''],
            ],
        ]);

        $email = $manager->generate("email_fake", "user123");
        $this->assertStringContainsString("@fake.local", $email);

        $custom = $manager->generate("my_string", "test");
        $this->assertEquals(5, strlen($custom));
    }

    public function testCustomStrategyWithUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown strategy type: 'nonexistent'");

        new StrategyManager([
            [
                'name' => 'bad_strategy',
                'type' => 'nonexistent',
            ],
        ]);
    }

    public function testMultipleCustomStrategies(): void
    {
        $manager = new StrategyManager([
            [
                'name' => 'short',
                'type' => 'string_random',
                'options' => ['length' => 3, 'prefix' => ''],
            ],
            [
                'name' => 'long',
                'type' => 'string_random',
                'options' => ['length' => 20, 'prefix' => 'id_'],
            ],
        ]);

        $short = $manager->generate("short", "test");
        $long = $manager->generate("long", "test");

        $this->assertEquals(3, strlen($short));
        $this->assertEquals(23, strlen($long));
        $this->assertStringStartsWith("id_", $long);
    }

    public function testGetTypeMap(): void
    {
        $map = StrategyManager::getTypeMap();

        $this->assertArrayHasKey('email_fake', $map);
        $this->assertArrayHasKey('phone_fake', $map);
        $this->assertArrayHasKey('name_fake', $map);
        $this->assertArrayHasKey('lastname_fake', $map);
        $this->assertArrayHasKey('string_random', $map);
        $this->assertArrayHasKey('empty', $map);
    }

    public function testSameKeyAndInputsProduceIdenticalOutputs(): void
    {
        $m1 = new StrategyManager();
        $m2 = new StrategyManager();
        $this->assertSame(
            $m1->generate('email_fake', 'user123', 'pk-1'),
            $m2->generate('email_fake', 'user123', 'pk-1'),
        );
    }

    public function testCustomDeriverKeyChangesOutput(): void
    {
        $a = (new StrategyManager([], new SeedDeriver('key-a')))->generate('email_fake', 'user123');
        $b = (new StrategyManager([], new SeedDeriver('key-b')))->generate('email_fake', 'user123');
        $this->assertNotSame($a, $b);
    }

    public function testEmailWithSaltFromValueDoesNotLeakOriginal(): void
    {
        // salt_source: value симулируется передачей оригинала в качестве соли
        $email = 'ivanov@secret-corp.ru';
        $out = (new StrategyManager())->generate('email_fake', $email, $email);
        $this->assertStringNotContainsString($email, $out);
        $this->assertStringNotContainsString('ivanov', $out);
    }
}
