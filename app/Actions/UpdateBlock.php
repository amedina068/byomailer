<?php

namespace App\Actions;

use App\Models\Block;

class UpdateBlock
{
    /**
     * Replace the name and the HTML content of the block.
     *
     * The caller validates the HTML with the `SafeHtml` rule first.
     */
    public function handle(Block $block, string $name, string $htmlContent): Block
    {
        $block->update([
            'name' => $name,
            'html_content' => $htmlContent,
        ]);

        return $block;
    }
}
