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
                    $updates = [];

                    if (Schema::hasColumn('vpn_servers', 'status')) {
                        $updates['status'] = 'offline';
                    }

                    if (Schema::hasColumn('vpn_servers', 'is_online')) {
                        $updates['is_online'] = false;
                    }

                    if (Schema::hasColumn('vpn_servers', 'enabled')) {
                        $updates['enabled'] = false;
                    }

                    if (Schema::hasColumn('vpn_servers', 'monitoring_enabled')) {
                        $updates['monitoring_enabled'] = false;
                    }

                    if (Schema::hasColumn('vpn_servers', 'online_users')) {
                        $updates['online_users'] = 0;
                    }

                    try {
                        $this->record->forceFill($updates)->save();
                    } catch (QueryException $exception) {
                        // Legacy enum schemas may not support "offline" and still expect "inactive".
                        if (array_key_exists('status', $updates)) {
                            Log::warning('Failed to set VPN server status to offline during disable; retrying with inactive.', [
                                'server_id' => $this->record->id,
                                'error' => $exception->getMessage(),
                            ]);
                            $updates['status'] = 'inactive';
                            $this->record->forceFill($updates)->save();
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
