<?php

namespace App\Actions;

use App\Models\Contact;
use App\Models\Tag;
use App\Support\TagUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteTag
{
    /**
     * Delete the tag. A tag that a saved filter uses is refused, like a
     * rename. Each contact loses the tag through syncTags(), so the
     * `removed_tag` automations and the sequence tag filters run.
     *
     * @throws ValidationException
     */
    public function handle(Tag $tag): void
    {
        if (($uses = TagUsage::filtersOf($tag)) !== []) {
            throw ValidationException::withMessages([
                'tag' => 'Saved filters use this tag: '.implode(', ', $uses).'. Remove the tag from these filters first.',
            ]);
        }

        DB::transaction(function () use ($tag): void {
            $tag->contacts()->lazyById(200, 'contacts.id')->each(function (Contact $contact) use ($tag): void {
                $contact->syncTags($contact->tags()->whereKeyNot($tag->id)->pluck('tags.id')->all());
            });

            $tag->delete();
        });
    }
}
