<?php

namespace App\Filament\Actions;

use App\Models\TestRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ShareReportAction
{
    public static function make(): Action
    {
        return Action::make('share_report')
            ->label('Share Report Link')
            ->icon('heroicon-o-share')
            ->color('gray')
            ->action(function (TestRun $record) {
                $expiry = now()->addDays(30)->timestamp;
                $token  = hash_hmac('sha256', "report-{$record->id}-{$expiry}", config('app.key'));
                $url    = route('reports.share', [
                    'testRun' => $record->id,
                    'token'   => $token,
                    'expires' => $expiry,
                ]);

                Notification::make()
                    ->title('Shareable link generated!')
                    ->body('Send this link to your client — no login required. Expires in 30 days.')
                    ->info()
                    ->send();

                return $url;
            })
            ->visible(fn (TestRun $record) => $record->report_html_path !== null);
    }
}
