<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationGroup = 'Management';
    protected static ?int $navigationSort = 10;
    protected static ?string $navigationLabel = 'Users';

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\Select::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'pm'    => 'Project Manager',
                    ])
                    ->required()
                    ->default('pm'),

                Forms\Components\TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep current password.' : null),

                Forms\Components\TextInput::make('slack_user_id')
                    ->label('Slack User ID')
                    ->placeholder('U12345ABCDE')
                    ->helperText('Optional. Leave blank to auto-resolve from the user\'s email address. Format: U followed by alphanumeric characters.')
                    ->nullable(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('role')
                    ->colors([
                        'danger' => 'admin',
                        'info'   => 'pm',
                    ])
                    ->formatStateUsing(fn ($state) => match($state) {
                        'admin' => 'Admin',
                        'pm'    => 'Project Manager',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('slack_user_id')
                    ->label('Slack ID')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Tables\Actions\DeleteAction $action, User $record) {
                        // Prevent deleting yourself
                        if ($record->id === auth()->id()) {
                            \Filament\Notifications\Notification::make()
                                ->title('You cannot delete your own account.')
                                ->danger()
                                ->send();
                            $action->halt(); // correct Filament v3 pattern
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
