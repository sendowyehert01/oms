<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\OrderEntries;
use App\SampleOrders;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class OrderController extends Controller
{
    public function index(Request $request, SampleOrders $sampleOrders, OrderEntries $orderEntries): View|string
    {
        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $searchTerm = mb_strtolower($search);

        $savedOrders = $orderEntries->all();
        $isSampleData = $savedOrders->isEmpty() && ! $orderEntries->hasCustomers();

        $matchingOrders = ($isSampleData ? $sampleOrders->all($request->session()->get('orders.deleted_samples', [])) : $savedOrders)
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

        $view = view('orders.index', compact('orders', 'search', 'isSampleData'));

        return $request->hasHeader('X-Orders-Fragment') ? $view->fragment('orders-list') : $view;
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
            $details = $orderId === false ? null : ($isSampleData ? $sampleOrders->find($orderId, $request->session()->get('orders.deleted_samples', [])) : $orderEntries->find($orderId));
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

    public function destroy(Request $request, string $order, OrderEntries $orderEntries, SampleOrders $sampleOrders): JsonResponse|RedirectResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']], [
            'confirmed.required' => 'Confirm the order deletion before proceeding.',
            'confirmed.accepted' => 'Confirm the order deletion before proceeding.',
        ]);

        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $listUrl = route('orders.index', ['search' => $search, 'page' => $page]);
        $orderId = filter_var($order, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        try {
            if ($request->routeIs('orders.samples.destroy')) {
                $orderNumber = $orderId === false ? null : $sampleOrders->delete($orderId, $request->session());
                $result = $orderNumber === null ? ['status' => 'missing'] : ['status' => 'deleted', 'number' => $orderNumber];
            } else {
                $result = $orderId === false ? ['status' => 'missing'] : $orderEntries->delete($orderId);
            }
        } catch (QueryException $exception) {
            report($exception);

            return $this->deletionError($request, $listUrl, 'The order could not be deleted. Please try again.', 503);
        }

        if ($result['status'] === 'missing') {
            return $this->deletionError($request, $listUrl, 'The order does not exist or has already been deleted.', 404);
        }

        if ($result['status'] === 'protected') {
            return $this->deletionError($request, $listUrl, 'Orders with recorded payments cannot be deleted.', 409);
        }

        $message = 'Order '.$result['number'].' deleted successfully.';
        $request->session()->flash('success', $message);

        if (! $request->expectsJson()) {
            return redirect()->to($listUrl);
        }

        try {
            $request->headers->set('X-Orders-Fragment', '1');
            $html = $this->index($request, $sampleOrders, $orderEntries);
        } catch (Throwable $exception) {
            report($exception);
            $html = null;
        }

        return response()->json(['message' => $message, 'html' => $html, 'redirect_url' => $listUrl]);
    }

    private function deletionError(Request $request, string $listUrl, string $message, int $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : redirect()->to($listUrl)->with('error', $message);
    }
}
