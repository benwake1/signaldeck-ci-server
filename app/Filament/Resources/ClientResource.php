<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationGroup = 'Management';

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Client Details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Forms\Set $set) =>
                            $set('slug', \Illuminate\Support\Str::slug($state))
                        ),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('contact_name')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('contact_email')
                        ->email()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('website')
                        ->url()
                        ->maxLength(255),
                ])->columns(2),

            Forms\Components\Section::make('Branding')
                ->description('Used to customise exported test reports sent to this client.')
                ->schema([
                    Forms\Components\FileUpload::make('logo_path')
                        ->label('Client Logo')
                        ->image()
                        ->directory('clients/logos')
                        ->imagePreviewHeight('80')
                        ->columnSpanFull(),

                    Forms\Components\ColorPicker::make('primary_colour')
                        ->label('Primary Colour')
                        ->default('#1e40af'),

                    Forms\Components\ColorPicker::make('secondary_colour')
                        ->label('Secondary Colour')
                        ->default('#3b82f6'),

                    Forms\Components\ColorPicker::make('accent_colour')
                        ->label('Accent Colour')
                        ->default('#f59e0b'),

                    Forms\Components\Textarea::make('report_footer_text')
                        ->label('Report Footer Text')
                        ->placeholder('e.g. This report is confidential and prepared exclusively for [Client Name].')
                        ->rows(3)
                        ->columnSpanFull(),
                ])->columns(3),

            Forms\Components\Toggle::make('active')
                ->label('Active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_path')
                    ->label('Logo')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name=' . urlencode($record->name) . '&color=ffffff&background=1e40af'),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('projects_count')
                    ->label('Projects')
                    ->counts('projects')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('contact_name')
                    ->label('Contact')
                    ->searchable(),

                Tables\Columns\TextColumn::make('contact_email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\IconColumn::make('active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProjectsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
