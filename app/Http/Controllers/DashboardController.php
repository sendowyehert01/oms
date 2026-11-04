<?php

namespace App\Http\Controllers;

use App\DailyPerformance;
use App\DashboardMetrics;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $dashboardMetrics, DailyPerformance $dailyPerformance): View|Response
    {
        $user = $request->user();

        try {
            $metrics = $dashboardMetrics->summary($user);
            $performance = $dailyPerformance->summary($user);
            $clients = Customer::query()->visibleTo($user)
                ->select('id', 'company_name', 'contact_person', 'created_at')->withCount('orders')
                ->orderByDesc('id')->paginate(5, ['*'], 'clients_page')->withQueryString();
            $orders = Order::query()
                ->when(! $user->isAdmin(), fn (Builder $query): Builder => $query->createdBy($user))
                ->select('id', 'customer_id', 'order_date', 'status', 'created_at')->with('customer:id,company_name')
                ->orderByDesc('id')->paginate(5, ['*'], 'orders_page')->withQueryString();
        } catch (QueryException $exception) {
            report($exception);

            return response()->view('orders.unavailable', [
                'title' => 'Unable to load dashboard',
                'message' => 'Dashboard totals could not be loaded. Please try again.',
                'returnToOrdersUrl' => route('orders.index'),
                'retryUrl' => route('dashboard'),
            ], 503);
        }

        return view('dashboard', compact('metrics', 'user', 'performance', 'clients', 'orders'));
    }
}
