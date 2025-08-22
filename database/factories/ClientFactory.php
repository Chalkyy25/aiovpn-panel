<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\VpnServer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'username' => $this->faker->userName(),
            'password' => bcrypt($this->faker->password()),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),

            'vpn_server_id' => VpnServer::factory(),
        ];
    }
}
