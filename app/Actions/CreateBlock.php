<?php

namespace App\Actions;

use App\Models\Block;
use App\Models\User;

class CreateBlock
{
    /**
     * Create a block in the client of the user. The user is the author.
     *
     * The caller validates the HTML with the `SafeHtml` rule first.
     */
    public function handle(User $user, string $name, string $htmlContent): Block
    {
        return Block::create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'name' => $name,
            'html_content' => $htmlContent,
        ]);
    }
}
