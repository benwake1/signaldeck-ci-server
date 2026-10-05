<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services\Ai;

use Psr\Http\Message\StreamInterface;

trait ReadsServerSentEvents
{
    /**
     * Yields the raw payload of each "data: ..." line in an SSE body.
     */
    private function sseDataLines(StreamInterface $body): \Generator
    {
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // keep the incomplete last line

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }

                yield ltrim(substr($line, 5));
            }
        }

        $line = trim($buffer);
        if (str_starts_with($line, 'data:')) {
            yield ltrim(substr($line, 5));
        }
    }

    /** "AI generation failed: 404 - model 'x' not found", using the provider's own error text when present. */
    protected function failureMessage(\Illuminate\Http\Client\Response $response): string
    {
        $error = $response->json('error');
        $detail = is_array($error) ? ($error['message'] ?? null) : (is_string($error) ? $error : null);

        return 'AI generation failed: ' . $response->status() . ($detail ? ' - ' . \Illuminate\Support\Str::limit($detail, 200) : '');
    }
}
