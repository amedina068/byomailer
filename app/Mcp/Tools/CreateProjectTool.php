<?php

namespace App\Mcp\Tools;

use App\Actions\CreateProject;
use App\Concerns\ProjectValidationRules;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a project. A project groups newsletters, sequences and automations. Project names are unique per account, and the comparison ignores the letter case. A duplicate name returns a validation error. Returns the new project id and name.')]
class CreateProjectTool extends Tool
{
    use ProjectValidationRules;

    protected string $name = 'create-project';

    public function handle(Request $request, CreateProject $createProject): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate($this->projectRules($user->client_id));

        $project = $createProject->handle($user, $validated['name']);

        return Response::structured([
            'id' => $project->id,
            'name' => $project->name,
            'message' => "Project #{$project->id} created.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Name of the project. It must be unique in the account.')
                ->required(),
        ];
    }
}
