<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\OrderDrafts;
use App\OrderEntries;
use App\PaymentEntries;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OrderController extends Controller
{
    public function index(Request $request, OrderEntries $orderEntries): View|string|RedirectResponse
    {
        if ($request->user()->isProductionMonitor()) {
            return to_route('production.index');
        }

        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $searchTerm = mb_strtolower($search);

        $matchingOrders = $orderEntries->all($request->user())
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

        $view = view('orders.index', compact('orders', 'search'));

        return $request->hasHeader('X-Orders-Fragment') ? $view->fragment('orders-list') : $view;
    }

    public function store(StoreOrderRequest $request, OrderEntries $orderEntries, OrderDrafts $drafts): RedirectResponse
    {
        $data = $request->validated();

        try {
            $orderId = $orderEntries->create($data, $request->user());
        } catch (AuthorizationException|ModelNotFoundException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($drafts->find($request->session(), $data['submission_key']) === null) {
                throw $exception;
            }

            report($exception);

            return to_route('orders.creation.details', ['draft' => $data['submission_key']])
                ->withInput()->with('error', 'The order could not be saved. Please try again.');
        }

        $drafts->forget($request->session(), $data['submission_key']);
        $orderDate = Order::query()->findOrFail($orderId)->order_date->toDateString();
        $orderNumber = $orderEntries->number($orderId, $orderDate);

        return to_route('orders.payments.create', ['order' => $orderId])->with('success', 'Order '.$orderNumber.' created successfully.');
    }

    public function show(Request $request, string $order, OrderEntries $orderEntries, PaymentEntries $paymentEntries): View|Response
    {
        $search = $request->query('search', '');
        $search = is_string($search) ? trim($search) : '';
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $listQuery = ['search' => $search, 'page' => $page];
        $returnToOrdersUrl = route('orders.index', $listQuery);
        $orderId = filter_var($order, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        try {
            if ($request->user()->isProductionMonitor()) {
                $productionOrder = Order::query()->visibleTo($request->user())->with(['customer:id,company_name', 'creator:id,name', 'items'])->findOrFail($orderId);
                Gate::authorize('view', $productionOrder);

                return view('production.show', [
                    'order' => $productionOrder,
                    'orderNumber' => $orderEntries->number($productionOrder->id, $productionOrder->order_date->toDateString()),
                    'statusHistory' => $productionOrder->statusHistory()->where('event_type', '!=', 'payment_due_date_changed')->paginate(10, ['*'], 'history_page')->withQueryString(),
                ]);
            }

            $details = $orderId === false ? null : $orderEntries->find($orderId, $request->user());
            $workflowOrder = $details !== null ? Order::query()->findOrFail($orderId) : null;
            $paymentSummary = $workflowOrder !== null ? $paymentEntries->summary($workflowOrder) : null;
            $statusHistory = $workflowOrder?->statusHistory()->paginate(10, ['*'], 'history_page')->withQueryString();
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

        return view('orders.show', ['order' => $details, 'returnToOrdersUrl' => $returnToOrdersUrl, 'paymentSummary' => $paymentSummary, 'workflowOrder' => $workflowOrder, 'statusHistory' => $statusHistory]);
    }

    public function destroy(Request $request, string $order, OrderEntries $orderEntries): JsonResponse|RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            Gate::authorize('delete', Order::query()->findOrFail($order));
        }

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
            $result = $orderId === false ? ['status' => 'missing'] : $orderEntries->delete($orderId, $request->user());
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
            $html = $this->index($request, $orderEntries);
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
