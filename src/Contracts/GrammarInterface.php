<?php

namespace LutfiNur\QueryBuilder\Contracts;

/**
 * Interface GrammarInterface
 *
 * Contract untuk SQL grammar yang berbeda per database driver.
 * Setiap driver (MySQL, PostgreSQL, dll) harus implement interface ini.
 */
interface GrammarInterface
{
    /**
     * Generate DATE_FORMAT expression.
     *
     * @param string $column Full column reference (e.g., 'table.column')
     * @param string $format Format string (menggunakan format MySQL sebagai standar)
     * @return string SQL expression
     */
    public function dateFormat(string $column, string $format): string;

    /**
     * Generate IF/CASE conditional expression.
     *
     * @param string $condition SQL condition
     * @param string $trueValue Value jika true
     * @param string $falseValue Value jika false
     * @return string SQL expression
     */
    public function conditional(string $condition, string $trueValue, string $falseValue): string;

    /**
     * Generate IFNULL/COALESCE expression.
     *
     * @param string $expression SQL expression yang mungkin NULL
     * @param string $default Default value jika NULL
     * @return string SQL expression
     */
    public function ifNull(string $expression, string $default): string;

    /**
     * Generate CONCAT expression.
     *
     * @param string ...$parts Parts to concatenate
     * @return string SQL expression
     */
    public function concat(string ...$parts): string;

    /**
     * Generate LIKE operator (case-insensitive search).
     *
     * @param string $column Column atau expression
     * @param string $placeholder Placeholder (biasanya '?')
     * @return string SQL expression (e.g., "column LIKE ?" atau "column ILIKE ?")
     */
    public function like(string $column, string $placeholder = '?'): string;

    /**
     * Get the driver name.
     *
     * @return string
     */
    public function getDriverName(): string;
}
