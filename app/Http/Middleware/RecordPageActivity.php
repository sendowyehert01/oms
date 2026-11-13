<?php

namespace App\Http\Middleware;

use App\AuditRecorder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordPageActivity
{
    private const array TABS = [
        'dashboard' => 'Dashboard',
        'production.index' => 'Production',
        'orders.index' => 'Orders',
        'orders.create' => 'Orders',
        'orders.creation.customer' => 'Orders',
        'orders.creation.details' => 'Orders',
        'orders.payments.create' => 'Orders',
        'orders.show' => 'Orders',
        'balances.index' => 'Balances',
        'exports.index' => 'Data Exports',
        'staff.index' => 'Staff accounts',
        'audit-logs.index' => 'Audit Logs',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->exists || ! $request->isMethod('GET') || ! $response->isSuccessful()
            || $request->expectsJson() || $request->hasHeader('X-Orders-Fragment') || $request->hasHeader('X-Order-Checklist-Fragment')
            || str_contains($request->header('Sec-Purpose', '').' '.$request->header('Purpose', ''), 'prefetch')) {
            return $response;
        }

        $routeName = $request->route()?->getName();
        $tab = self::TABS[$routeName] ?? null;

        if ($tab !== null) {
            $sessionKey = 'audit.current_tab.'.$actor->id;
            $isListRefresh = ($routeName === 'dashboard' || str_ends_with($routeName, '.index')) && $request->query->count() > 0;

            if (! $isListRefresh && $request->session()->get($sessionKey) !== $tab) {
                $this->audit->tabSwitched($actor, $tab);
            }

            $request->session()->put($sessionKey, $tab);
        }

        if ($routeName === 'orders.show' && ! $request->query->has('history_page')) {
            $order = Order::query()->find($request->route('order'));

            if ($order !== null) {
                $this->audit->orderViewed($actor, $order);
            }
        } elseif ($routeName === 'orders.payments.receipt') {
            $order = $request->route('order');
            $payment = $request->route('payment');

            if ($order instanceof Order && $payment instanceof Payment) {
                $this->audit->receiptViewed($actor, $order, $payment);
            }
        }

        return $response;
    }
}
