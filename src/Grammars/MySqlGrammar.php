<?php

namespace LutfiNur\QueryBuilder\Grammars;

use LutfiNur\QueryBuilder\Contracts\GrammarInterface;

/**
 * Class MySqlGrammar
 *
 * SQL grammar implementation untuk MySQL / MariaDB.
 */
class MySqlGrammar implements GrammarInterface
{
    /**
     * {@inheritdoc}
     *
     * Menggunakan DATE_FORMAT() bawaan MySQL.
     * Format string menggunakan specifier MySQL:
     *   %d = day, %b = abbreviated month, %M = full month,
     *   %Y = 4-digit year, %H = 24h hour, %i = minute,
     *   %s = second, %W = full weekday name
     */
    public function dateFormat(string $column, string $format): string
    {
        return "(DATE_FORMAT({$column}, '{$format}'))";
    }

    /**
     * {@inheritdoc}
     *
     * Menggunakan IF() bawaan MySQL.
     */
    public function conditional(string $condition, string $trueValue, string $falseValue): string
    {
        return "(IF({$condition}, {$trueValue}, {$falseValue}))";
    }

    /**
     * {@inheritdoc}
     *
     * Menggunakan IFNULL() bawaan MySQL.
     */
    public function ifNull(string $expression, string $default): string
    {
        return "IFNULL({$expression}, {$default})";
    }

    /**
     * {@inheritdoc}
     *
     * Menggunakan CONCAT() bawaan MySQL.
     */
    public function concat(string ...$parts): string
    {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /**
     * {@inheritdoc}
     *
     * MySQL LIKE (case-insensitive tergantung collation, tapi default CI).
     */
    public function like(string $column, string $placeholder = '?'): string
    {
        return "{$column} LIKE {$placeholder}";
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'mysql';
    }
}
