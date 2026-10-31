<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\OrderEntries;
use App\SampleOrders;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request, SampleOrders $sampleOrders, OrderEntries $orderEntries): View
    {
        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $searchTerm = mb_strtolower($search);

        $savedOrders = $orderEntries->all();
        $isSampleData = $savedOrders->isEmpty();

        $matchingOrders = ($isSampleData ? $sampleOrders->all() : $savedOrders)
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

        return view('orders.index', compact('orders', 'search', 'isSampleData'));
    }

    public function create(): View
    {
        $customers = DB::table('customers')->select('id', 'company_name')->orderBy('company_name')->get();
        $statuses = OrderEntries::STATUSES;
        $oldSubmissionKey = old('submission_key');
        $submissionKey = is_string($oldSubmissionKey) && Str::isUuid($oldSubmissionKey)
            ? $oldSubmissionKey
            : (string) Str::uuid();

        return view('orders.create', compact('customers', 'statuses', 'submissionKey'));
    }

    public function store(StoreOrderRequest $request, OrderEntries $orderEntries): RedirectResponse
    {
        $data = $request->validated();
        $orderId = $orderEntries->create($data);
        $orderDate = DB::table('orders')->where('id', $orderId)->value('order_date');
        $orderNumber = $orderEntries->number($orderId, $orderDate);

        return to_route('orders.index')->with('success', 'Order '.$orderNumber.' created successfully.');
    }

    public function show(Request $request, string $order, OrderEntries $orderEntries, SampleOrders $sampleOrders): View|Response
    {
        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $listQuery = ['search' => $search, 'page' => $page];
        $returnToOrdersUrl = route('orders.index', $listQuery);
        $isSampleData = $request->routeIs('orders.samples.show');
        $orderId = filter_var($order, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        try {
            $details = $orderId === false ? null : ($isSampleData ? $sampleOrders->find($orderId) : $orderEntries->find($orderId));
        } catch (QueryException $exception) {
            report($exception);

            return response()->view('orders.unavailable', [
                'title' => 'Unable to load order',
                'message' => 'Order details could not be loaded. Please try again.',
                'returnToOrdersUrl' => $returnToOrdersUrl,
                'retryUrl' => route('orders.show', ['order' => $order] + $listQuery),
            ], 503);
        }

        if ($details === null) {
            return response()->view('orders.unavailable', [
                'title' => 'Order not found',
                'message' => 'The requested order does not exist or is no longer available.',
                'returnToOrdersUrl' => $returnToOrdersUrl,
                'retryUrl' => null,
            ], 404);
        }

        return view('orders.show', ['order' => $details, 'isSampleData' => $isSampleData, 'returnToOrdersUrl' => $returnToOrdersUrl]);
    }
}
