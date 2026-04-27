<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

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
            'has_deploy_key'      => !empty($this->getRawOriginal('deploy_key_public')),
            'webhook_url'         => $request->user()?->isAdmin()
                                         ? route('api.v1.webhook.trigger', [], true)
                                         : null,
            'webhook_secret_set'  => $request->user()?->isAdmin()
                                         ? !empty($this->getRawOriginal('webhook_secret'))
                                         : null,
            'runner_type' => $this->runner_type,
            'playwright_available_projects' => $this->playwright_available_projects,
            'active' => $this->active,
            'pass_rate' => $this->pass_rate,
            'recent_pass_rate' => $this->when(
                $this->relationLoaded('testRuns'),
                function () {
                    $runs = $this->testRuns->take(10);
                    return $runs->count() > 0
                        ? round($runs->where('status', 'passing')->count() / $runs->count() * 100)
                        : null;
                }
            ),
            'latest_run' => $this->when(
                $this->relationLoaded('latestRun') || $this->relationLoaded('testRuns'),
                function () {
                    if ($this->relationLoaded('latestRun') && $this->latestRun) {
                        return new TestRunResource($this->latestRun);
                    }
                    $first = $this->testRuns->first();
                    return $first ? new TestRunResource($first) : null;
                }
            ),
            'client' => new ClientResource($this->whenLoaded('client')),
            'test_suites' => TestSuiteResource::collection($this->whenLoaded('testSuites')),
            'test_runs' => TestRunResource::collection($this->whenLoaded('testRuns')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
