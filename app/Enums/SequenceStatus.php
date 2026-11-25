<?php

namespace App\Enums;

/**
 * The status of a sequence and of a sequence step. Only a live sequence and
 * its live steps send. A paused sequence or step has the status `draft`.
 */
enum SequenceStatus: string
{
    case Draft = 'draft';
    case Live = 'live';

    /**
     * The input alias of `draft`. The API filter and the MCP tools accept it,
     * because a paused sequence has the status `draft`. No row stores it.
     */
    public const PAUSED_ALIAS = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Live => 'Live',
        };
    }

    /**
     * The value and the label of each status, for the sequence pages.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }

    /**
     * The input values that a status filter or a status tool accepts: the
     * stored values and the `paused` alias.
     *
     * @return list<string>
     */
    public static function inputValues(): array
    {
        return [...array_column(self::cases(), 'value'), self::PAUSED_ALIAS];
    }

    /**
     * The status for an input value. `paused` gives `Draft`.
     */
    public static function fromInput(string $value): self
    {
        return $value === self::PAUSED_ALIAS ? self::Draft : self::from($value);
    }
}
