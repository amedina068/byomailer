<?php

namespace App\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait ConfirmsLiveEdits
{
    /**
     * @return array<string, list<string>>
     */
    protected function confirmLiveValidationRules(): array
    {
        return [
            'confirm_live' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function confirmLiveSchema(JsonSchema $schema): array
    {
        return [
            'confirm_live' => $schema->boolean()
                ->description('Set to true only after the user explicitly confirms the change to a live record. A live record affects real contacts now. Leave it out for drafts.'),
        ];
    }

    /**
     * Return an error when a live record is edited without explicit confirmation.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function liveEditNeedsConfirmation(array $validated, string $record, string $impact): ?Response
    {
        if ((bool) ($validated['confirm_live'] ?? false)) {
            return null;
        }

        return Response::error("{$record} is live. {$impact} No change was saved. Show the user the changes, ask the user to confirm, then call this tool again with confirm_live set to true.");
    }

    /**
     * Lock the record and read the status column again. Return an error when
     * it changed after the confirmation check, so the tool saves nothing on a
     * record that went live without the confirmation of the user. Call it
     * inside the transaction of the write.
     */
    protected function statusChangedSinceCheck(Model $record, string $column, string $label): ?Response
    {
        $current = $record->newQuery()->lockForUpdate()->whereKey($record->getKey())->value($column);

        if ($current === $record->getOriginal($column)) {
            return null;
        }

        return Response::error("{$label} changed while this tool ran. No change was saved. Read it again, then call this tool again.");
    }

    /**
     * Describe a status change without saving it. The agent shows the preview
     * to the user and calls the tool again with confirm_live set to true.
     *
     * @param  array<string, mixed>  $preview
     */
    protected function statusChangePreview(array $preview): ResponseFactory
    {
        return Response::structured([
            ...$preview,
            'applied' => false,
            'message' => 'Preview only. No change was saved. Show this preview to the user, ask the user to confirm, then call this tool again with the same arguments and confirm_live set to true.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function confirmsLive(array $validated): bool
    {
        return (bool) ($validated['confirm_live'] ?? false);
    }
}
