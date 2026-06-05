<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Console;

use DataVeil\Console\NullLogger;
use PHPUnit\Framework\TestCase;

class NullLoggerTest extends TestCase
{
    public function testAllMethodsAreNoOps(): void
    {
        $logger = new NullLogger();

        $logger->emergency('test');
        $logger->alert('test');
        $logger->critical('test');
        $logger->error('test');
        $logger->warning('test');
        $logger->notice('test');
        $logger->info('test');
        $logger->debug('test');
        $logger->log('info', 'test');
        $logger->progress('test');

        $this->assertTrue(true);
    }
}
