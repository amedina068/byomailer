<?php

namespace App\Mcp\Tools;

use App\Actions\DeleteTag;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Models\Automation;
use App\Models\Tag;
use App\Models\VisualAutomation;
use App\Support\TagUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a tag and remove it from each contact. The removal runs the live automations that start when a contact loses the tag, and it can change the audience of live sequences. The first call saves nothing. It returns a preview with the contacts that have the tag and the live automations that the removal starts. Show the preview to the user, and after the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. If a saved filter of a newsletter that can still send, a sequence, a sequence step or a segment uses the tag, the delete is refused, as in the dashboard. Find tag ids with list-tags.')]
class DeleteTagTool extends Tool
{
    use ConfirmsLiveEdits;

    protected string $name = 'delete-tag';

    public function handle(Request $request, DeleteTag $deleteTag): Response|ResponseFactory
    {
        $validated = $request->validate([
            'tag_id' => ['required', 'integer'],
            ...$this->confirmLiveValidationRules(),
        ]);

        $tag = Tag::query()->withCount('contacts')->find($validated['tag_id']);

        if ($tag === null) {
            return Response::error("No tag found with id {$validated['tag_id']}.");
        }

        if (($uses = TagUsage::filtersOf($tag)) !== []) {
            return Response::error("Tag #{$tag->id} cannot be deleted. Saved filters use this tag: ".implode(', ', $uses).'. Remove the tag from these filters first.');
        }

        if (! $this->confirmsLive($validated)) {
            return $this->statusChangePreview([
                ...$this->formatTag($tag),
                'change' => 'delete',
                ...$this->impact($tag),
            ]);
        }

        $deleteTag->handle($tag);

        return Response::structured([
            ...$this->formatTag($tag),
            'applied' => true,
            'message' => "Tag #{$tag->id} deleted. It was removed from {$tag->contacts_count} contacts.",
        ]);
    }

    /**
     * @return array{id: int, name: string, contacts_count: int}
     */
    private function formatTag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'contacts_count' => (int) $tag->contacts_count,
        ];
    }

    /**
     * The live automations that start when a contact loses this tag. A visual
     * automation trigger without a tag starts on each removed tag.
     *
     * @return array{live_automation_ids: list<int>, live_visual_automation_ids: list<int>, effects: list<string>}
     */
    private function impact(Tag $tag): array
    {
        $automationIds = Automation::query()
            ->live()
            ->where('type', Automation::TYPE_SIMPLE)
            ->where('condition->type', 'removed_tag')
            ->get(['id', 'condition'])
            ->filter(fn (Automation $automation): bool => (int) ($automation->condition['value'] ?? 0) === $tag->id)
            ->modelKeys();

        $visualAutomationIds = VisualAutomation::query()
            ->live()
            ->get(['id', 'workflow'])
            ->filter(fn (VisualAutomation $automation): bool => $automation->isTriggeredBy('removed_tag', [$tag->id]))
            ->modelKeys();

        $effects = [
            "{$tag->contacts_count} contacts lose the tag. The tag cannot be restored.",
            'Live sequences with tag filters can change their audience.',
        ];

        if ($automationIds !== []) {
            $effects[] = 'Live automations '.implode(', ', $automationIds).' run for each contact that loses the tag.';
        }

        if ($visualAutomationIds !== []) {
            $effects[] = 'Live visual automations '.implode(', ', $visualAutomationIds).' enroll each contact that loses the tag.';
        }

        return [
            'live_automation_ids' => array_values($automationIds),
            'live_visual_automation_ids' => array_values($visualAutomationIds),
            'effects' => $effects,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tag_id' => $schema->integer()
                ->description('Id of the tag. Use list-tags to find tag ids.')
                ->required(),
            'confirm_live' => $schema->boolean()
                ->description('Leave it out to get a preview. Set it to true only after the user confirms the preview.'),
        ];
    }
}
