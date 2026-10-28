<?php

declare(strict_types=1);

namespace DataVeil\Database;

/**
 * Converts '?' placeholders to PostgreSQL numbered placeholders ($1, $2, ...).
 *
 * Limitation: plain string replacement — a literal '?' inside a quoted SQL
 * string literal would also be converted. Internal SQL never contains such
 * literals, so a naive replacement is sufficient.
 */
final class SqlPlaceholderConverter
{
    public static function questionToNumbered(string $sql): string
    {
        $index = 0;

        return (string) preg_replace_callback(
            '/\?/',
            static function () use (&$index): string {
                $index++;

                return '$' . $index;
            },
            $sql
        );
    }
}
