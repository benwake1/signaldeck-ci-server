<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestS3Connection extends Command
{
    protected $signature   = 'app:test-s3-connection';
    protected $description = 'Verify S3 credentials and bucket access';

    public function handle(): int
    {
        $bucket = AppSetting::get('s3_bucket');

        if (!$bucket) {
            $this->error('No S3 bucket configured in Settings.');
            return 1;
        }

        $this->line('Configured settings:');
        $this->table(['Key', 'Value'], [
            ['s3_bucket',         $bucket],
            ['s3_region',         AppSetting::get('s3_region') ?: '(not set)'],
            ['s3_key',            AppSetting::get('s3_key')    ?: '(not set)'],
            ['s3_secret',         AppSetting::get('s3_secret') ? '***' : '(not set)'],
            ['s3_endpoint',       AppSetting::get('s3_endpoint') ?: '(AWS default)'],
            ['s3_use_path_style', AppSetting::get('s3_use_path_style') ? 'true' : 'false'],
        ]);

        $this->line('Testing write...');
        try {
            Storage::disk('s3')->put('_test-connection.txt', 'ok');
            $this->info('Write OK');
        } catch (\Throwable $e) {
            $this->error('Write failed: ' . $e->getMessage());
            return 1;
        }

        $this->line('Testing read...');
        try {
            $contents = Storage::disk('s3')->get('_test-connection.txt');
            if ($contents !== 'ok') {
                $this->error('Read returned unexpected content.');
                return 1;
            }
            $this->info('Read OK');
        } catch (\Throwable $e) {
            $this->error('Read failed: ' . $e->getMessage());
            return 1;
        }

        $this->line('Cleaning up...');
        Storage::disk('s3')->delete('_test-connection.txt');

        $this->info('S3 connection is working correctly.');
        return 0;
    }
}
