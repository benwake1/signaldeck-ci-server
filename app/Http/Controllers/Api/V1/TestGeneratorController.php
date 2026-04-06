<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TestGeneratorService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TestGeneratorController extends Controller
{
    public function generate(Request $request, TestGeneratorService $service): BinaryFileResponse
    {
        $validated = $request->validate([
            'framework'        => ['required', 'in:cypress,playwright'],
            'platform'         => ['required', 'in:magento-hyva,magento-luma,generic'],
            'scenarios'        => ['required', 'array', 'min:1'],
            'scenarios.*'      => ['string', 'in:homepage,category,product,add_to_cart,cart,guest_checkout,account_registration,auth,search'],
            'base_url'         => ['required', 'url'],
            'admin_url'        => ['nullable', 'url'],
            'test_email'       => ['required', 'email'],
            'test_password'    => ['required', 'min:6'],
            'timeout_seconds'  => ['integer', 'min:5', 'max:120'],
            'headless'         => ['boolean'],
            'pw_workers'       => ['integer', 'min:1', 'max:16'],
            'pw_retries'       => ['integer', 'min:0', 'max:5'],
        ]);

        $config = [
            'framework'       => $validated['framework'],
            'platform'        => $validated['platform'],
            'scenarios'       => $validated['scenarios'],
            'base_url'        => $validated['base_url'],
            'admin_url'       => $validated['admin_url'] ?? null,
            'test_email'      => $validated['test_email'],
            'test_password'   => $validated['test_password'],
            'timeout_seconds' => $validated['timeout_seconds'] ?? 30,
            'headless'        => $validated['headless'] ?? true,
            'pw_workers'      => $validated['pw_workers'] ?? 4,
            'pw_retries'      => $validated['pw_retries'] ?? 2,
        ];

        $zipPath  = $service->generate($config);
        $filename = $config['framework'] . '-tests-' . now()->format('Y-m-d') . '.zip';

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
