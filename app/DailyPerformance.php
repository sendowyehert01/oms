<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DailyPerformance
{
    /**
     * @return array{timezone: string, start: string, end: string, days: list<array{date: string, clients: int, orders: int}>}
     */
    public function summary(User $user): array
    {
        $timezone = config('app.reporting_timezone');
        $lastDay = CarbonImmutable::now($timezone)->startOfDay();
        $firstDay = $lastDay->subDays(29);
        $days = [];
        $dateCases = [];
        $bindings = [];

        for ($day = $firstDay; $day <= $lastDay; $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = ['date' => $date, 'clients' => 0, 'orders' => 0];
            $dateCases[] = 'WHEN created_at >= ? AND created_at < ? THEN ?';
            $bindings[] = $day->utc()->toDateTimeString();
            $bindings[] = $day->addDay()->utc()->toDateTimeString();
            $bindings[] = $date;
        }

        $start = $firstDay->utc()->toDateTimeString();
        $end = $lastDay->addDay()->utc()->toDateTimeString();
        $dateExpression = 'CASE '.implode(' ', $dateCases).' END AS activity_date';

        foreach (['clients' => Customer::query(), 'orders' => Order::query()] as $metric => $query) {
            $counts = $query
                ->when(! $user->isAdmin(), fn (Builder $query): Builder => $query->createdBy($user))
                ->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->selectRaw($dateExpression, $bindings)->selectRaw('COUNT(*) AS creation_count')
                ->groupBy('activity_date')->pluck('creation_count', 'activity_date');

            foreach ($days as &$dayCounts) {
                $dayCounts[$metric] = (int) $counts->get($dayCounts['date'], 0);
            }
            unset($dayCounts);
        }

        return ['timezone' => $timezone, 'start' => $firstDay->toDateString(), 'end' => $lastDay->toDateString(), 'days' => $days];
    }
}
