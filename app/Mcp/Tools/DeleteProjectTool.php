<?php

namespace App\Mcp\Tools;

use App\Actions\DeleteProject;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a project. The newsletters, sequences and automations in the project stay, but they are no longer in the project. The first call saves nothing. It returns a preview with the number of records in the project. Show the preview to the user, and after the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. Find project ids with list-projects.')]
class DeleteProjectTool extends Tool
{
    use ConfirmsLiveEdits;

    protected string $name = 'delete-project';

    public function handle(Request $request, DeleteProject $deleteProject): Response|ResponseFactory
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            ...$this->confirmLiveValidationRules(),
        ]);

        $project = Project::query()
            ->withCount(['newsletters', 'sequences', 'automations', 'visualAutomations'])
            ->find($validated['project_id']);

        if ($project === null) {
            return Response::error("No project found with id {$validated['project_id']}.");
        }

        if (! $this->confirmsLive($validated)) {
            return $this->statusChangePreview([
                ...$this->formatProject($project),
                'change' => 'delete',
                'effects' => [
                    "The project is removed from {$project->newsletters_count} newsletters, {$project->sequences_count} sequences, {$project->automations_count} automations and {$project->visual_automations_count} visual automations.",
                    'These records stay. Their status, audience and mails do not change.',
                ],
            ]);
        }

        $deleteProject->handle($project);

        return Response::structured([
            ...$this->formatProject($project),
            'applied' => true,
            'message' => "Project #{$project->id} deleted.",
        ]);
    }

    /**
     * @return array{id: int, name: string, newsletters_count: int, sequences_count: int, automations_count: int, visual_automations_count: int}
     */
    private function formatProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'newsletters_count' => (int) $project->newsletters_count,
            'sequences_count' => (int) $project->sequences_count,
            'automations_count' => (int) $project->automations_count,
            'visual_automations_count' => (int) $project->visual_automations_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Id of the project. Use list-projects to find project ids.')
                ->required(),
            'confirm_live' => $schema->boolean()
                ->description('Leave it out to get a preview. Set it to true only after the user confirms the preview.'),
        ];
    }
}
