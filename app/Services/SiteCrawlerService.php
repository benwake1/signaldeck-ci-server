<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\DTOs\CrawlResult;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SiteCrawlerService
{
    public function crawl(string $url, array $options = []): CrawlResult
    {
        UrlSafetyValidator::validate($url);

        $timeout = $options['timeout'] ?? 30000;
        $nodePath = env('NODE_PATH', 'node');
        $scriptPath = resource_path('scripts/crawl-page.cjs');

        $result = Process::timeout(60)->run([
            $nodePath,
            $scriptPath,
            $url,
            "--timeout={$timeout}",
        ]);

        if ($result->failed()) {
            Log::error('Site crawl failed', [
                'url' => $url,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);
            throw new \RuntimeException("Failed to crawl {$url}: " . $result->errorOutput());
        }

        $data = json_decode($result->output(), true);

        if (!is_array($data)) {
            throw new \RuntimeException("Crawl returned invalid JSON for {$url}");
        }

        return CrawlResult::fromArray($data);
    }
}
