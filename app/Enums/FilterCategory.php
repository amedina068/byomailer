<?php

namespace App\Enums;

/**
 * The category of an audience filter. The `filters` JSON columns of
 * newsletters, sequences, sequence steps and segments store the value.
 *
 * Mirrored on the frontend in resources/js/types/newsletters.ts.
 */
enum FilterCategory: string
{
    case Attributes = 'attributes';
    case Tags = 'tags';
    case Lists = 'lists';
    case Topics = 'topics';
    case Date = 'date';
    case Engagement = 'engagement';
    case Location = 'location';

    /**
     * Read the category of a stored filter. A filter with no category is an
     * attribute filter, because the first version of the filters stored
     * attribute filters only. An unknown category gives null.
     */
    public static function fromStored(mixed $category): ?self
    {
        if ($category === null) {
            return self::Attributes;
        }

        return is_string($category) ? self::tryFrom($category) : null;
    }

    /**
     * The categories that combine their values with an all, any or none mode.
     * A location filter also has a mode, but only the modes of
     * FilterMode::forLocation().
     *
     * @return list<self>
     */
    public static function withMode(): array
    {
        return [self::Attributes, self::Tags, self::Lists, self::Topics];
    }

    /**
     * The categories that have one operator instead of a mode.
     *
     * @return list<self>
     */
    public static function withOperator(): array
    {
        return [self::Date, self::Engagement];
    }

    public function hasMode(): bool
    {
        return in_array($this, self::withMode(), true);
    }

    /**
     * The mode of a stored filter of this category that has no mode.
     * Validation requires a mode, so only old stored filters use it.
     * Categories without a mode give null.
     */
    public function defaultMode(): ?FilterMode
    {
        return match ($this) {
            self::Attributes => FilterMode::All,
            self::Tags, self::Lists, self::Topics, self::Location => FilterMode::Any,
            self::Date, self::Engagement => null,
        };
    }

    /**
     * Read the mode of a stored filter of this category. A missing mode
     * gives the default mode. An unknown mode gives null.
     */
    public function storedMode(mixed $mode): ?FilterMode
    {
        if ($mode === null) {
            return $this->defaultMode();
        }

        return is_string($mode) ? FilterMode::tryFrom($mode) : null;
    }
}
