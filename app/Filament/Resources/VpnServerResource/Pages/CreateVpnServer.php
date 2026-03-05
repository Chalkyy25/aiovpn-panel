<?php

namespace App\Filament\Resources\VpnServerResource\Pages;

use App\Filament\Resources\VpnServerResource;
use App\Jobs\DeployVpnServer;
use Filament\Notifications\Notification;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Schema;

class CreateVpnServer extends CreateRecord
{
    protected static string $resource = VpnServerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $allowedDeployment = ['queued', 'running', 'success', 'failed', 'pending', 'deployed'];
        $incoming = strtolower((string) ($data['deployment_status'] ?? ''));

        if (! in_array($incoming, $allowedDeployment, true)) {
            $data['deployment_status'] = 'queued';
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $server = $this->record;

        // Ensure a consistent starting state for background jobs.
        $payload = [
            'deployment_status' => 'queued',
            'status' => $server->status ?: 'pending',
            'is_deploying' => false,
        ];

        if (! Schema::hasColumn('vpn_servers', 'is_deploying')) {
            unset($payload['is_deploying']);
        }

        $server->forceFill($payload)->save();

        DeployVpnServer::dispatch($server);

        Notification::make()
            ->success()
            ->title('Server created')
            ->body('Deployment queued. Check the deployment log for progress.')
            ->send();
    }
}
