<?php

namespace App\Http\Controllers;

use App\DashboardMetrics;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $dashboardMetrics): View|Response
    {
        try {
            $metrics = $dashboardMetrics->summary();
        } catch (QueryException $exception) {
            report($exception);

            return response()->view('orders.unavailable', [
                'title' => 'Unable to load dashboard',
                'message' => 'Dashboard totals could not be loaded. Please try again.',
                'returnToOrdersUrl' => route('orders.index'),
                'retryUrl' => route('dashboard'),
            ], 503);
        }

        return view('dashboard', ['metrics' => $metrics, 'user' => $request->user()]);
    }
}
