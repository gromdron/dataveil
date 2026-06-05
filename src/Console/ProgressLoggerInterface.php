<?php

declare(strict_types=1);

namespace DataVeil\Console;

interface ProgressLoggerInterface
{
    public function progress(string $message): void;
}
