<?php

namespace App\Actions;

use App\Models\Newsletter;
use Illuminate\Support\Facades\DB;

class UpdateNewsletterLabels
{
    /**
     * The keys that `handle()` reads from `$changes`.
     */
    public const FIELDS = ['title', 'project_ids'];

    /**
     * Change the title and the projects of a newsletter in any status. They
     * are labels: they do not change what the newsletter sends. A null title
     * keeps the current title.
     *
     * @param  array{title?: ?string, project_ids?: ?array<int, int|string>}  $changes
     */
    public function handle(Newsletter $newsletter, array $changes): Newsletter
    {
        DB::transaction(function () use ($newsletter, $changes): void {
            if (($changes['title'] ?? null) !== null) {
                $newsletter->forceFill(['title' => $changes['title']])->save();
            }

            if (array_key_exists('project_ids', $changes)) {
                $newsletter->syncProjects(array_map('intval', $changes['project_ids'] ?? []));
            }
        });

        return $newsletter;
    }
}
