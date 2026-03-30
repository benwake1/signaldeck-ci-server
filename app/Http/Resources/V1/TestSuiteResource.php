<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestSuiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'spec_pattern' => $this->spec_pattern,
            'branch_override' => $this->branch_override,
            'effective_branch' => $this->effective_branch,
            'playwright_projects' => $this->playwright_projects,
            'playwright_workers' => $this->playwright_workers,
            'playwright_retries' => $this->playwright_retries,
            'timeout_minutes' => $this->timeout_minutes,
            'env_variables' => empty($this->env_variables) ? null : $this->env_variables,
            'active' => $this->active,
            'latest_run' => new TestRunResource($this->whenLoaded('latestRun')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'test_runs' => TestRunResource::collection($this->whenLoaded('testRuns')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
