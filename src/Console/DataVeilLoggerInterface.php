<?php

declare(strict_types=1);

namespace DataVeil\Console;

use Psr\Log\LoggerInterface;

interface DataVeilLoggerInterface extends LoggerInterface, ProgressLoggerInterface
{
}
