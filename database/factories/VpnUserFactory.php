<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VpnUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class VpnUserFactory extends Factory
{
    protected $model = VpnUser::class;

    public function definition(): array
    {
        return [
            'is_online' => $this->faker->boolean(),
            'last_seen_at' => Carbon::now(),
            'username' => $this->faker->userName(),
            'password' => bcrypt($this->faker->password()),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'wireguard_private_key' => $this->faker->word(),
            'wireguard_public_key' => $this->faker->word(),
            'wireguard_address' => $this->faker->address(),
            'plain_password' => bcrypt($this->faker->password()),
            'device_name' => $this->faker->name(),

            'client_id' => User::factory(),
        ];
    }
}
