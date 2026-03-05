<?php

namespace App\Filament\Resources\VpnServerResource\Pages;

use App\Filament\Resources\VpnServerResource;
use App\Jobs\DeployVpnServer;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateVpnServer extends CreateRecord
{
    protected static string $resource = VpnServerResource::class;

    protected function afterCreate(): void
    {
        $server = $this->record;

        // Ensure a consistent starting state for background jobs.
        $server->forceFill([
            'deployment_status' => 'queued',
            'status' => $server->status ?: 'pending',
            'is_deploying' => false,
        ])->save();

        DeployVpnServer::dispatch($server);

        Notification::make()
            ->success()
            ->title('Server created')
            ->body('Deployment queued. Check the deployment log for progress.')
            ->send();
    }
}
