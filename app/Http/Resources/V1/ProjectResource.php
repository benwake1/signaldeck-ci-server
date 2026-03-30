<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'repo_url' => $this->repo_url,
            'repo_provider' => $this->repo_provider,
            'default_branch' => $this->default_branch,
            'has_deploy_key' => !empty($this->getRawOriginal('deploy_key_public')),
            'runner_type' => $this->runner_type,
            'playwright_available_projects' => $this->playwright_available_projects,
            'active' => $this->active,
            'pass_rate' => $this->pass_rate,
            'latest_run' => new TestRunResource($this->whenLoaded('latestRun')),
            'client' => new ClientResource($this->whenLoaded('client')),
            'test_suites' => TestSuiteResource::collection($this->whenLoaded('testSuites')),
            'test_runs' => TestRunResource::collection($this->whenLoaded('testRuns')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
