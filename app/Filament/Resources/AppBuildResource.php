<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppBuildResource\Pages;
use App\Models\AppBuild;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class AppBuildResource extends Resource
{
    protected static ?string $model = AppBuild::class;

    protected static ?string $navigationGroup = 'Apps';
    protected static ?string $navigationLabel = 'App Builds';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Build Details')
                ->schema([
                    Forms\Components\TextInput::make('version_code')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Android versionCode, e.g. 217'),

                    Forms\Components\TextInput::make('version_name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Android versionName, e.g. 1.0.0'),

                    Forms\Components\FileUpload::make('apk_path')
                        ->label('APK File')
                        ->disk('local')
                        ->directory('app-updates')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.android.package-archive',
                            'application/octet-stream',
                            'application/zip',
                        ])
                        ->downloadable()
                        ->openable(false)
                        ->previewable(false)
                        ->preserveFilenames()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Upload the APK file for this build.'),

                    Forms\Components\Textarea::make('release_notes')
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Forms\Components\Section::make('Update Settings')
                ->schema([
                    Forms\Components\Toggle::make('mandatory')
                        ->label('Mandatory update')
                        ->default(false),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active build')
                        ->default(true)
                        ->helperText('If enabled, all other builds will be deactivated automatically.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('System')
                ->schema([
                    Forms\Components\Placeholder::make('stored_apk_path')
                        ->label('Stored APK Path')
                        ->content(fn ($record) => $record?->apk_path ?: 'Will be set automatically after upload'),

                    Forms\Components\Placeholder::make('stored_sha256')
                        ->label('SHA256')
                        ->content(fn ($record) => $record?->sha256 ?: 'Will be generated automatically after upload'),
                ])
                ->visible(fn (?AppBuild $record) => filled($record)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('version_code', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('version_code')
                    ->label('Code')
                    ->sortable(),

                Tables\Columns\TextColumn::make('version_name')
                    ->label('Version')
                    ->searchable(),

                Tables\Columns\TextColumn::make('apk_path')
                    ->label('APK Path')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->apk_path),

                Tables\Columns\IconColumn::make('mandatory')
                    ->boolean()
                    ->label('Mandatory'),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),

                Tables\Columns\TextColumn::make('sha256')
                    ->label('SHA256')
                    ->limit(16)
                    ->tooltip(fn ($record) => $record->sha256)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active build'),
                Tables\Filters\TernaryFilter::make('mandatory')
                    ->label('Mandatory update'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (AppBuild $record) => ! $record->is_active)
                    ->action(function (AppBuild $record): void {
                        AppBuild::query()->update(['is_active' => false]);
                        $record->update(['is_active' => true]);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function ($records): void {
                            foreach ($records as $record) {
                                if ($record->apk_path && Storage::disk('local')->exists($record->apk_path)) {
                                    Storage::disk('local')->delete($record->apk_path);
                                }
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppBuilds::route('/'),
            'create' => Pages\CreateAppBuild::route('/create'),
            'edit' => Pages\EditAppBuild::route('/{record}/edit'),
        ];
    }
}
