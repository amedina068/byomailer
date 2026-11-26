<?php

namespace App\Mcp\Concerns;

use App\Mcp\Tools\UpdateAccountProfileTool;
use App\Services\SendableContentCheck;

trait FormatsComplianceWarnings
{
    /**
     * The dashboard warning tells the user to open Settings, Profile. An AI
     * client can also set the postal address with a tool, so the MCP text
     * names that tool. The other warnings do not change.
     *
     * @param  array<int|string, string>  $warnings
     * @return list<string>
     */
    protected function mcpWarnings(array $warnings): array
    {
        return array_values(array_map(
            fn (string $warning): string => $warning === SendableContentCheck::POSTAL_ADDRESS_WARNING
                ? UpdateAccountProfileTool::POSTAL_ADDRESS_WARNING
                : $warning,
            $warnings,
        ));
    }
}
