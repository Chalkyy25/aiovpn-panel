<?php

namespace App\Filament\Resources\VpnServerResource\Pages;

use App\Filament\Resources\VpnServerResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

            Actions\Action::make('disableServer')
                ->label('Disable Server')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Disable server?')
                ->modalDescription('This keeps history but removes the server from active use.')
                ->action(function (): void {
                    $columns = array_flip(Schema::getColumnListing('vpn_servers'));
                    $updates = [];

                    if (isset($columns['status'])) {
                        $updates['status'] = 'offline';
                    }

                    if (isset($columns['is_online'])) {
                        $updates['is_online'] = false;
                    }

                    if (isset($columns['enabled'])) {
                        $updates['enabled'] = false;
                    }

                    if (isset($columns['monitoring_enabled'])) {
                        $updates['monitoring_enabled'] = false;
                    }

                    if (isset($columns['online_users'])) {
                        $updates['online_users'] = 0;
                    }

                    try {
                        $this->record->forceFill($updates)->save();
                    } catch (QueryException $exception) {
                        // Legacy enum schemas may not support "offline" and still expect "inactive".
                        $isStatusEnumFailure = str_contains(strtolower($exception->getMessage()), 'status')
                            && str_contains(strtolower($exception->getMessage()), 'offline');

                        if (array_key_exists('status', $updates) && $isStatusEnumFailure) {
                            Log::warning('Failed to set VPN server status to offline during disable; retrying with inactive.', [
                                'server_id' => $this->record->id,
                                'error' => $exception->getMessage(),
                            ]);
                            $updates['status'] = 'inactive';

                            try {
                                $this->record->forceFill($updates)->save();
                            } catch (QueryException $fallbackException) {
                                Notification::make()
                                    ->danger()
                                    ->title('Failed to disable server')
                                    ->body('The server state could not be updated. Please try again.')
                                    ->send();

                                throw $fallbackException;
                            }
                        } else {
                            throw $exception;
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title('Server disabled')
                        ->body('The server is now archived from active use while history is preserved.')
                        ->send();
                }),
        ];
    }
}
