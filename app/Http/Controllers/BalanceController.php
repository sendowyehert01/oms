<?php

namespace App\Http\Controllers;

use App\OutstandingBalances;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BalanceController extends Controller
{
    public function __invoke(Request $request, OutstandingBalances $balances): View|Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'customer_id' => ['nullable', 'integer', 'min:1'],
            'due' => ['nullable', Rule::in(['overdue', 'today', 'unscheduled'])],
        ]);
        try {
            $data = $balances->summary($request->user(), $filters);
        } catch (QueryException $exception) {
            report($exception);

            return response()->view('orders.unavailable', [
                'title' => 'Unable to load balances', 'message' => 'Outstanding balances could not be loaded. Please try again.',
                'returnToOrdersUrl' => route('orders.index'), 'retryUrl' => route('balances.index'),
            ], 503);
        }

        return view('balances.index', ['data' => $data, 'user' => $request->user(), 'filters' => $filters]);
    }
}
