<?php

namespace App\Http\Controllers;

use App\SampleOrders;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request, SampleOrders $sampleOrders): View
    {
        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $searchTerm = mb_strtolower($search);

        $matchingOrders = $sampleOrders->all()
            ->filter(fn (array $order): bool => $search === ''
                || str_contains(mb_strtolower($order['number']), $searchTerm)
                || str_contains((string) $order['id'], $searchTerm)
                || str_contains(mb_strtolower($order['customer_name']), $searchTerm))
            ->values();

        $perPage = 10;
        $lastPage = max(1, (int) ceil($matchingOrders->count() / $perPage));
        $currentPage = min(LengthAwarePaginator::resolveCurrentPage(), $lastPage);

        $orders = new LengthAwarePaginator(
            $matchingOrders->forPage($currentPage, $perPage)->values(),
            $matchingOrders->count(),
            $perPage,
            $currentPage,
            ['path' => route('orders.index')],
        );

        $orders->appends(['search' => $search]);

        return view('orders.index', compact('orders', 'search'));
    }
}
