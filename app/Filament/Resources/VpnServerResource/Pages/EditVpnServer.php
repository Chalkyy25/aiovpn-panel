<?php

namespace App\Filament\Resources\VpnServerResource\Pages;

use App\Filament\Resources\VpnServerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVpnServer extends EditRecord
{
    protected static string $resource = VpnServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('deploymentLog')
                ->label('Deployment Log')
                ->icon('heroicon-o-document-text')
                ->modalHeading(fn (): string => 'Deployment Log')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.modals.server-deployment-log', [
                    'serverId' => (int) $this->record->id,
                ])),

            Actions\DeleteAction::make(),
        ];
    }
}
