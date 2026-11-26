<?php

namespace App\Support\Liquid;

use Keepsuit\Liquid\Extensions\Extension;

class EmailExtension extends Extension
{
    public function __construct(private bool $html) {}

    public function getFiltersProviders(): array
    {
        return [OutputFilters::class];
    }

    public function getNodeVisitors(): array
    {
        return [new EscapeOutput($this->html)];
    }
}
