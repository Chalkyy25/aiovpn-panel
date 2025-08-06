<?php

namespace Database\Factories;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Models\VpnUserConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class VpnUserConnectionFactory extends Factory
{
    protected $model = VpnUserConnection::class;

    public function definition(): array
    {
        $isConnected = $this->faker->boolean();
        $connectedAt = $this->faker->dateTimeBetween('-1 week', 'now');

        return [
            'vpn_user_id' => VpnUser::factory(),
            'vpn_server_id' => VpnServer::factory(),
            'is_connected' => $isConnected,
            'client_ip' => $this->faker->ipv4(),
            'virtual_ip' => $this->faker->localIpv4(),
            'connected_at' => $connectedAt,
            'disconnected_at' => $isConnected ? null : $this->faker->dateTimeBetween($connectedAt, 'now'),
            'bytes_received' => $this->faker->numberBetween(0, 1000000000),
            'bytes_sent' => $this->faker->numberBetween(0, 1000000000),
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_connected' => true,
            'disconnected_at' => null,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_connected' => false,
            'disconnected_at' => $this->faker->dateTimeBetween($attributes['connected_at'] ?? '-1 hour', 'now'),
        ]);
    }
}
