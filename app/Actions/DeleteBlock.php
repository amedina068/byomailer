<?php

namespace App\Actions;

use App\Models\Block;

class DeleteBlock
{
    /**
     * Delete the block.
     */
    public function handle(Block $block): void
    {
        $block->delete();
    }
}
