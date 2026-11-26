<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateProject;
use App\Concerns\ProjectValidationRules;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Rename a project. Project names are unique per account, and the comparison ignores the letter case. A name that another project has returns a validation error. Find project ids with list-projects.')]
class UpdateProjectTool extends Tool
{
    use ProjectValidationRules;

    protected string $name = 'update-project';

    public function handle(Request $request, UpdateProject $updateProject): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['project_id' => ['required', 'integer']]);

        $project = Project::query()->find($validated['project_id']);

        if ($project === null) {
            return Response::error("No project found with id {$validated['project_id']}.");
        }

        $name = $request->validate($this->projectRules($user->client_id, $project->id))['name'];

        $updateProject->handle($project, $name);

        return Response::structured([
            'id' => $project->id,
            'name' => $project->name,
            'message' => "Project #{$project->id} renamed.",
        ]);
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
            'name' => $schema->string()
                ->description('The new name. It must be unique in the account.')
                ->required(),
        ];
    }
}
