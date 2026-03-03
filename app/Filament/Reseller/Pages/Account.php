<?php

namespace App\Filament\Reseller\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;

class Account extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Account';

    protected static string $view = 'filament.reseller.pages.account';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('profile')
                ->label('Edit profile')
                ->icon('heroicon-o-pencil-square')
                ->url(route('profile.edit')),
        ];
    }

    public function getUserProperty()
    {
        return auth()->user();
    }
}
