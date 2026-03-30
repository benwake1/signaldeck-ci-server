<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_run_id' => $this->test_run_id,
            'spec_file' => $this->spec_file,
            'suite_title' => $this->suite_title,
            'test_title' => $this->test_title,
            'full_title' => $this->full_title,
            'status' => $this->status,
            'duration_ms' => $this->duration_ms,
            'duration_formatted' => $this->duration_formatted,
            'error_message' => $this->error_message,
            'error_stack' => $this->error_stack,
            'test_code' => $this->test_code,
            'screenshot_paths' => $this->screenshot_paths,
            'screenshot_urls' => $this->screenshot_urls,
            'video_path' => $this->video_path,
            'video_url' => $this->video_url,
            'attempt' => $this->attempt,
            'test_run' => $this->whenLoaded('testRun', fn () => [
                'id'         => $this->testRun->id,
                'status'     => $this->testRun->status,
                'branch'     => $this->testRun->branch,
                'created_at' => $this->testRun->created_at,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
