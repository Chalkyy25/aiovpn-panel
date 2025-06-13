<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    public function run()
    {
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'ashley@aiovpn.co.uk',
            'role' => 'admin',
            'password' => bcrypt('securepassword'), // change as needed
        ]);
    }
}
