<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Order::factory()->count(5)->hasItems(2)->hasPayments(1)->create();
    }
}
