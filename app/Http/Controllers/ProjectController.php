<?php

namespace App\Http\Controllers;

use App\Actions\CreateProject;
use App\Actions\DeleteProject;
use App\Actions\UpdateProject;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::query()
            ->withCount(['newsletters', 'sequences', 'automations', 'visualAutomations'])
            ->latest('id')
            ->get();

        return Inertia::render('projects/Index', [
            'projects' => ProjectResource::collection($projects)->resolve($request),
        ]);
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject): RedirectResponse
    {
        $createProject->handle($request->user(), $request->validated('name'));

        return back();
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): RedirectResponse
    {
        $updateProject->handle($project, $request->validated('name'));

        return back();
    }

    public function destroy(Project $project, DeleteProject $deleteProject): RedirectResponse
    {
        $deleteProject->handle($project);

        return back();
    }
}
