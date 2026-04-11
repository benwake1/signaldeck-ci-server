<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Pages;

use App\Models\AppSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class BrandingSettingsPage extends Page
{
    protected static ?string $navigationIcon  = null;
    protected static ?string $navigationLabel = 'Branding';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.branding-settings';
    protected static ?string $title           = 'Branding Settings';
    protected static ?string $slug            = 'settings/branding';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        // Hidden entirely in hosted mode — theme is locked to SignalDeck
        if (config('brand.is_hosted')) {
            return false;
        }

        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'brand_name'          => AppSetting::get('brand_name',          config('brand.name', '')),
            'brand_legal_name'    => AppSetting::get('brand_legal_name',    config('brand.legal_name', '')),
            'brand_primary_color'   => AppSetting::get('brand_primary_color',   config('brand.primary_color', '')),
            'brand_secondary_color' => AppSetting::get('brand_secondary_color', config('brand.secondary_color', '')),
            'brand_logo_path'     => AppSetting::get('brand_logo_path',     '') ?: null,
            'brand_logo_dark_path'=> AppSetting::get('brand_logo_dark_path','') ?: null,
            'brand_logo_height'   => AppSetting::get('brand_logo_height',   config('brand.logo_height', '2rem')),
            'brand_favicon_path'  => AppSetting::get('brand_favicon_path',  '') ?: null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identity')
                    ->description('Controls the name shown in the panel header and browser tab.')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        Forms\Components\TextInput::make('brand_name')
                            ->label('Brand Name')
                            ->placeholder('My Company'),

                        Forms\Components\TextInput::make('brand_legal_name')
                            ->label('Legal Name')
                            ->helperText('Used in the footer copyright notice.')
                            ->placeholder('My Company Ltd.'),
                    ])->columns(2),

                Forms\Components\Section::make('Colours')
                    ->icon('heroicon-o-swatch')
                    ->schema([
                        Forms\Components\ColorPicker::make('brand_primary_color')
                            ->label('Primary Colour')
                            ->helperText('Used for buttons, links, active states, and accents.'),

                        Forms\Components\ColorPicker::make('brand_secondary_color')
                            ->label('Secondary Colour')
                            ->helperText('Used for gradients and hover effects alongside the primary.'),
                    ])->columns(2),

                Forms\Components\Section::make('Logos & Favicon')
                    ->description('Upload PNG or SVG files. Logos are displayed at the configured height in the sidebar header.')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        Forms\Components\FileUpload::make('brand_logo_path')
                            ->label('Logo (Light Mode)')
                            ->image()
                            ->disk('public')
                            ->directory('brand')
                            ->imagePreviewHeight('64')
                            ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg']),

                        Forms\Components\FileUpload::make('brand_logo_dark_path')
                            ->label('Logo (Dark Mode)')
                            ->image()
                            ->disk('public')
                            ->directory('brand')
                            ->imagePreviewHeight('64')
                            ->helperText('Leave blank to use the light logo in dark mode.')
                            ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/jpeg']),

                        Forms\Components\FileUpload::make('brand_favicon_path')
                            ->label('Favicon')
                            ->image()
                            ->disk('public')
                            ->directory('brand')
                            ->imagePreviewHeight('32')
                            ->acceptedFileTypes(['image/x-icon', 'image/png', 'image/svg+xml']),

                        Forms\Components\TextInput::make('brand_logo_height')
                            ->label('Logo Height (CSS)')
                            ->placeholder('2rem')
                            ->helperText('Any valid CSS height value, e.g. 2rem, 40px.')
                            ->columnSpanFull(),
                    ])->columns(3),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AppSetting::set('brand_name',            $data['brand_name'] ?? '');
        AppSetting::set('brand_legal_name',    $data['brand_legal_name'] ?? '');
        AppSetting::set('brand_primary_color', $data['brand_primary_color'] ?? '');
        AppSetting::set('brand_secondary_color', $data['brand_secondary_color'] ?? '');
        AppSetting::set('brand_logo_height',   $data['brand_logo_height'] ?? '');

        // FileUpload returns an array even for single files — flatten to a string path
        foreach (['brand_logo_path', 'brand_logo_dark_path', 'brand_favicon_path'] as $key) {
            $val = $data[$key] ?? '';
            if (is_array($val)) {
                $val = reset($val) ?: '';
            }
            AppSetting::set($key, (string) $val);
        }

        // Bust the panel branding cache so AdminPanelProvider picks up changes
        Cache::forget('brand_settings');

        Notification::make()
            ->title('Branding settings saved')
            ->success()
            ->send();
    }
}
