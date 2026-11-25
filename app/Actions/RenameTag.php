<?php

namespace App\Actions;

use App\Models\Tag;
use App\Support\TagUsage;
use Illuminate\Validation\ValidationException;

class RenameTag
{
    /**
     * Rename the tag. Tag filters store tag names, so a rename of a tag that
     * a saved filter uses is refused. Otherwise an "exclude" filter stops to
     * exclude the tagged contacts.
     *
     * @throws ValidationException
     */
    public function handle(Tag $tag, string $name): Tag
    {
        if ($name !== $tag->name && ($uses = TagUsage::filtersOf($tag)) !== []) {
            throw ValidationException::withMessages([
                'name' => 'Saved filters use this tag: '.implode(', ', $uses).'. Remove the tag from these filters first.',
            ]);
        }

        $tag->update(['name' => $name]);

        return $tag;
    }
}
