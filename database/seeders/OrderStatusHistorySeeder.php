<?php

namespace Database\Seeders;

use App\Models\OrderStatusHistory;
use Illuminate\Database\Seeder;

class OrderStatusHistorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        OrderStatusHistory::factory()->count(3)->create();
    }
}
