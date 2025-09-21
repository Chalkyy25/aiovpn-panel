<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name'            => 'Starter (1 Device) — Annual',
                'description'     => 'Single device plan, valid for 12 months.',
                'price_credits'   => 1,   // per month
                'max_connections' => 1,
                'duration_months' => 12,
                'is_featured'     => false,
                'is_active'       => true,
            ],
            [
                'name'            => 'Standard (3 Devices) — Annual',
                'description'     => 'Covers phone + TV + tablet for 12 months.',
                'price_credits'   => 3,
                'max_connections' => 3,
                'duration_months' => 12,
                'is_featured'     => true,  // highlight this one
                'is_active'       => true,
            ],
            [
                'name'            => 'Family (6 Devices) — Annual',
                'description'     => 'Perfect for households with multiple devices.',
                'price_credits'   => 6,
                'max_connections' => 6,
                'duration_months' => 12,
                'is_featured'     => false,
                'is_active'       => true,
            ],
            [
                'name'            => 'Premium (Unlimited Devices) — Annual',
                'description'     => 'Unlimited devices, valid for 12 months.',
                'price_credits'   => 12,
                'max_connections' => 0,   // 0 = Unlimited
                'duration_months' => 12,
                'is_featured'     => false,
                'is_active'       => true,
            ],
        ];

        foreach ($packages as $data) {
            Package::updateOrCreate(
                ['name' => $data['name']], // unique key
                $data
            );
        }
    }
}