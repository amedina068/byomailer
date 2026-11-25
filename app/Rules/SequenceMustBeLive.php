<?php

namespace App\Rules;

use App\Enums\SequenceStatus;
use App\Models\Sequence;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SequenceMustBeLive implements ValidationRule
{
    public const MESSAGE = 'The selected sequence must be live before it can be used in an automation.';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ids = is_array($value) ? $value : [$value];

        if (! self::allLive($ids)) {
            $fail(self::MESSAGE);
        }
    }

    /**
     * Determine whether every given sequence ID refers to a live sequence.
     *
     * @param  array<int|string|null>  $sequenceIds
     */
    public static function allLive(array $sequenceIds): bool
    {
        $ids = collect($sequenceIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return true;
        }

        return Sequence::query()
            ->whereIn('id', $ids)
            ->where('status', SequenceStatus::Live)
            ->count() === count($ids);
    }
}
