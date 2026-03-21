<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SettingsPage extends Page
{
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'General';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.settings';
    protected static ?string $title = 'General Settings';
    protected static ?string $slug = 'settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'notifications_enabled' => AppSetting::get('notifications_enabled', '1') === '1',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Notifications')
                    ->icon('heroicon-o-bell')
                    ->schema([
                        Forms\Components\Toggle::make('notifications_enabled')
                            ->label('Send email notifications when a test run completes')
                            ->helperText('Emails are sent to the user who triggered the test run.')
                            ->default(true),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AppSetting::set('notifications_enabled', $data['notifications_enabled'] ? '1' : '0');

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
