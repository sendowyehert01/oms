<?php

namespace App;

use Illuminate\Support\Collection;

class SampleOrders
{
    /**
     * @return Collection<int, array{id: int, number: string, customer_name: string, order_date: string, status: string, total_amount_in_cents: int}>
     */
    public function all(): Collection
    {
        return collect([
            [1024, 'Northstar Packaging', '2026-10-31', 'In production', 1_850_000],
            [1023, 'Harbor Foods', '2026-10-30', 'Pending', 425_050],
            [1022, 'Summit Packaging', '2026-10-30', 'Ready for delivery', 932_000],
            [1021, 'Cedar Packaging', '2026-10-29', 'Completed', 1_260_000],
            [1020, 'Northstar Packaging', '2026-10-28', 'Pending', 780_000],
            [1019, 'Bloom Retail', '2026-10-28', 'In production', 356_075],
            [1018, 'Summit Packaging', '2026-10-27', 'Completed', 2_140_000],
            [1017, 'Cedar Packaging', '2026-10-26', 'Ready for delivery', 645_000],
            [1016, 'Northstar Packaging', '2026-10-25', 'In production', 1_120_000],
            [1015, 'Harbor Foods', '2026-10-25', 'Completed', 498_000],
            [1014, 'Summit Packaging', '2026-10-24', 'Pending', 867_050],
            [1013, 'Cedar Packaging', '2026-10-23', 'Completed', 734_000],
            [1012, 'Northstar Packaging', '2026-10-22', 'Ready for delivery', 1_530_000],
            [1011, 'Bloom Retail', '2026-10-21', 'Completed', 295_000],
            [1010, 'Summit Packaging', '2026-10-20', 'In production', 1_780_000],
            [1009, 'Cedar Packaging', '2026-10-19', 'Pending', 612_025],
            [1008, 'Northstar Packaging', '2026-10-18', 'Completed', 940_000],
            [1007, 'Harbor Foods', '2026-10-17', 'Completed', 1_085_000],
            [1006, 'Summit Packaging', '2026-10-16', 'Ready for delivery', 823_000],
            [1005, 'Cedar Packaging', '2026-10-15', 'Completed', 475_000],
            [1004, 'Northstar Packaging', '2026-10-14', 'Completed', 1_320_000],
            [1003, 'Bloom Retail', '2026-10-13', 'Completed', 389_050],
            [1002, 'Summit Packaging', '2026-10-12', 'Completed', 1_680_000],
            [1001, 'Cedar Packaging', '2026-10-11', 'Completed', 557_000],
        ])->map(fn (array $order): array => [
            'id' => $order[0],
            'number' => sprintf('ORD-2026-%04d', $order[0]),
            'customer_name' => $order[1],
            'order_date' => $order[2],
            'status' => $order[3],
            'total_amount_in_cents' => $order[4],
        ]);
    }
}
