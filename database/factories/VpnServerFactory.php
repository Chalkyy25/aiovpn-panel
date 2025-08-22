<?php

namespace Database\Factories;

use App\Models\VpnServer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class VpnServerFactory extends Factory
{
    protected $model = VpnServer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'ip_address' => $this->faker->ipv4(),
            'ssh_user' => $this->faker->word(),
            'ssh_port' => $this->faker->randomNumber(),
            'ssh_type' => $this->faker->word(),
            'ssh_key' => $this->faker->word(),
            'ssh_password' => bcrypt($this->faker->password()),
            'protocol' => $this->faker->word(),
            'port' => $this->faker->word(),
            'transport' => $this->faker->word(),
            'dns' => $this->faker->word(),
            'location' => $this->faker->word(),
            'group' => $this->faker->word(),
            'enable_ipv6' => $this->faker->boolean(),
            'enable_logging' => $this->faker->boolean(),
            'enable_proxy' => $this->faker->boolean(),
            'header1' => $this->faker->boolean(),
            'header2' => $this->faker->boolean(),
            'status' => $this->faker->word(),
            'deployment_status' => $this->faker->word(),
            'deployment_log' => $this->faker->word(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
            'is_deploying' => $this->faker->boolean(),
        ];
    }
}
