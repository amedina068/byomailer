<?php

namespace App\Support;

/**
 * Makes a value safe for one cell of a CSV export. A spreadsheet runs a cell
 * that starts with `=`, `+`, `-`, `@`, a tab or a carriage return as a
 * formula. Contact data comes from public forms and imports, so the export
 * puts a quote in front of such a value. The spreadsheet then shows it as
 * text.
 */
class SpreadsheetCell
{
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function escapeFormula(string $value): string
    {
        return $value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true)
            ? "'".$value
            : $value;
    }

    /**
     * Converts a stored value to the text of one cell. An array becomes JSON.
     */
    public static function fromValue(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };

        return self::escapeFormula($text);
    }
}
