<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Api\V1\UpdateProjectRequest;
use App\Http\Resources\V1\ProjectResource;
use App\Models\Project;
use App\Services\PlaywrightConfigReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Project::with('client')->withCount('testSuites');

        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->input('client_id'));
        }

        if ($request->filled('active')) {
            $query->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return ProjectResource::collection($query->latest()->paginate(25));
    }

    public function show(Project $project): ProjectResource
    {
        $project->load('client')->loadCount('testSuites');

        return new ProjectResource($project);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = Project::create($request->validated());

        return (new ProjectResource($project->load('client')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $project->update($request->validated());

        return new ProjectResource($project->fresh()->load('client'));
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    public function generateKey(Project $project): JsonResponse
    {
        $project->generateDeployKey();

        return response()->json([
            'message'    => 'Deploy key generated.',
            'public_key' => $project->deploy_key_public,
        ]);
    }

    public function discoverProjects(Project $project): JsonResponse
    {
        if (! $project->isPlaywright()) {
            return response()->json(['message' => 'Only Playwright projects support discovery.'], 422);
        }

        try {
            $service  = app(PlaywrightConfigReaderService::class);
            $projects = $service->discoverProjects($project);

            $project->update(['playwright_available_projects' => $projects]);

            return response()->json([
                'message'  => 'Projects discovered.',
                'projects' => $projects,
            ]);
        } catch (\Exception $e) {
            Log::error('Playwright project discovery failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Project discovery failed. Please check server logs.'], 500);
        }
    }
}
