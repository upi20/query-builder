<?php

namespace LutfiNur\QueryBuilder\Grammars;

use LutfiNur\QueryBuilder\Contracts\GrammarInterface;

/**
 * Class PostgresGrammar
 *
 * SQL grammar implementation untuk PostgreSQL.
 */
class PostgresGrammar implements GrammarInterface
{
    /**
     * Mapping MySQL DATE_FORMAT specifiers ke PostgreSQL TO_CHAR format.
     *
     * @var array<string, string>
     */
    protected static array $dateFormatMap = [
        '%Y' => 'YYYY',       // 4-digit year
        '%y' => 'YY',         // 2-digit year
        '%m' => 'MM',         // month number (01-12)
        '%d' => 'DD',         // day of month (01-31)
        '%e' => 'FMDD',       // day of month (1-31, no leading zero)
        '%H' => 'HH24',       // hour (00-23)
        '%h' => 'HH12',       // hour (01-12)
        '%i' => 'MI',         // minute (00-59)
        '%s' => 'SS',         // second (00-59)
        '%M' => 'FMMonth',    // full month name (January, ...)
        '%b' => 'Mon',        // abbreviated month (Jan, Feb, ...)
        '%W' => 'FMDay',      // full weekday name (Sunday, ...)
        '%a' => 'Dy',         // abbreviated weekday (Sun, Mon, ...)
        '%p' => 'AM',         // AM/PM
        '%T' => 'HH24:MI:SS', // time (24-hour)
        '%r' => 'HH12:MI:SS AM', // time (12-hour with AM/PM)
        '%%' => '%',           // literal percent
    ];

    /**
     * {@inheritdoc}
     *
     * Menggunakan TO_CHAR() PostgreSQL.
     * MySQL format specifiers otomatis dikonversi ke format PostgreSQL.
     */
    public function dateFormat(string $column, string $format): string
    {
        $pgFormat = $this->convertDateFormat($format);
        return "(TO_CHAR({$column}, '{$pgFormat}'))";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL tidak punya IF(); menggunakan CASE WHEN ... THEN ... ELSE ... END.
     */
    public function conditional(string $condition, string $trueValue, string $falseValue): string
    {
        return "(CASE WHEN {$condition} THEN {$trueValue} ELSE {$falseValue} END)";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL menggunakan COALESCE() (lebih standar SQL).
     */
    public function ifNull(string $expression, string $default): string
    {
        return "COALESCE({$expression}, {$default})";
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL CONCAT() atau bisa juga pakai || operator.
     * Kita gunakan CONCAT() agar consistent.
     */
    public function concat(string ...$parts): string
    {
        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /**
     * {@inheritdoc}
     *
     * PostgreSQL menggunakan ILIKE untuk case-insensitive search.
     */
    public function like(string $column, string $placeholder = '?'): string
    {
        return "{$column}::text ILIKE {$placeholder}";
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'pgsql';
    }

    /**
     * Convert MySQL DATE_FORMAT string ke PostgreSQL TO_CHAR format.
     *
     * @param string $format MySQL format string (e.g., '%d-%b-%Y')
     * @return string PostgreSQL format string (e.g., 'DD-Mon-YYYY')
     */
    protected function convertDateFormat(string $format): string
    {
        // Sort by key length descending agar %M tidak match sebelum %Mi dst
        $map = static::$dateFormatMap;
        uksort($map, fn(string $a, string $b) => strlen($b) - strlen($a));

        $result = $format;
        foreach ($map as $mysql => $pg) {
            $result = str_replace($mysql, $pg, $result);
        }

        return $result;
    }
}
