<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the projects of the account, newest first. A project groups newsletters, sequences and automations. Each project has its id, its name and the number of newsletters, sequences, automations and visual automations in it. Use the id with update-project or delete-project. The list has no pagination.')]
class ListProjectsTool extends Tool
{
    protected string $name = 'list-projects';

    public function handle(Request $request): Response|ResponseFactory
    {
        $projects = Project::query()
            ->withCount(['newsletters', 'sequences', 'automations', 'visualAutomations'])
            ->latest('id')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'newsletters_count' => $project->newsletters_count,
                'sequences_count' => $project->sequences_count,
                'automations_count' => $project->automations_count,
                'visual_automations_count' => $project->visual_automations_count,
            ])
            ->all();

        return Response::structured([
            'projects' => $projects,
            'count' => count($projects),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
