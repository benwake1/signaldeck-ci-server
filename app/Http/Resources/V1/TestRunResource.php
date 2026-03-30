<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestRunResource extends JsonResource
{
    /**
     * Flag to explicitly include log_output in the response.
     */
    public static bool $includeLogOutput = false;

    public function toArray(Request $request): array
    {
        $includeLog = static::$includeLogOutput
            || $this->resource->relationLoaded('testResults');

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'test_suite_id' => $this->test_suite_id,
            'runner_type' => $this->runner_type,
            'triggered_by' => $this->triggered_by,
            'status' => $this->status,
            'branch' => $this->branch,
            'commit_sha' => $this->commit_sha,
            'total_tests' => $this->total_tests,
            'passed_tests' => $this->passed_tests,
            'failed_tests' => $this->failed_tests,
            'pending_tests' => $this->pending_tests,
            'duration_ms' => $this->duration_ms,
            'log_output' => $this->when($includeLog, $this->log_output),
            'error_message' => $this->error_message,
            'report_html_path' => $this->report_html_path,
            'report_share_url' => $this->report_share_url,
            'merged_json_path' => $this->merged_json_path,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'pass_rate' => $this->pass_rate,
            'duration_formatted' => $this->duration_formatted,
            'status_colour' => $this->status_colour,
            'is_complete' => $this->is_complete,
            'is_running' => $this->is_running,
            'project' => new ProjectResource($this->whenLoaded('project')),
            'test_suite' => new TestSuiteResource($this->whenLoaded('testSuite')),
            'triggered_by_user' => new UserResource($this->whenLoaded('triggeredBy')),
            'test_results' => TestResultResource::collection($this->whenLoaded('testResults')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
