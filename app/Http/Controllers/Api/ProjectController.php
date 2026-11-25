<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateProject;
use App\Actions\DeleteProject;
use App\Actions\UpdateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Requests\Api\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Projects', 'A project groups newsletters, sequences and automations.', weight: 11)]
class ProjectController extends Controller
{
    /**
     * List projects.
     *
     * Returns the projects of your client, newest first, with the number of
     * newsletters, sequences and automations in each one.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ]);

        $projects = Project::query()
            ->withCount(['newsletters', 'sequences', 'automations', 'visualAutomations'])
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return ProjectResource::collection($projects);
    }

    /**
     * Get a project.
     *
     * Returns one project with the number of newsletters, sequences and
     * automations in it.
     */
    public function show(Request $request, int $projectId): JsonResponse
    {
        $project = Project::query()
            ->withCount(['newsletters', 'sequences', 'automations', 'visualAutomations'])
            ->findOrFail($projectId);

        return ProjectResource::make($project)->response();
    }

    /**
     * Create a project.
     *
     * Creates a project with the given name.
     */
    public function store(StoreProjectRequest $request, CreateProject $createProject): JsonResponse
    {
        $project = $createProject->handle($request->user(), $request->validated('name'));

        return ProjectResource::make($project)->response()->setStatusCode(201);
    }

    /**
     * Rename a project.
     *
     * Changes the name of a project.
     */
    public function update(UpdateProjectRequest $request, int $projectId, UpdateProject $updateProject): JsonResponse
    {
        $project = $updateProject->handle(Project::query()->findOrFail($projectId), $request->validated('name'));

        return ProjectResource::make($project)->response();
    }

    /**
     * Delete a project.
     *
     * Deletes the project. The newsletters, sequences and automations in it are
     * not deleted.
     */
    public function destroy(int $projectId, DeleteProject $deleteProject): Response
    {
        $deleteProject->handle(Project::query()->findOrFail($projectId));

        return response()->noContent();
    }
}
