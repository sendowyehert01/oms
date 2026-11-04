<?php

namespace App\Http\Controllers;

use App\OrderStatus;
use App\ProductionBoard;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductionBoardController extends Controller
{
    public function __invoke(Request $request, ProductionBoard $board): View|Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'customer_id' => ['nullable', 'integer', 'min:1'], 'created_by' => ['nullable', 'integer', 'min:1'],
            'target_delivery_date' => ['nullable', 'date_format:Y-m-d'], 'customer_search' => ['nullable', 'string', 'max:255'],
            'creator_search' => ['nullable', 'string', 'max:255'],
            'late' => ['nullable', 'boolean'], 'view' => ['nullable', Rule::in(['board', 'list'])],
        ]);
        try {
            $data = $board->summary($request->user(), $filters);
        } catch (QueryException $exception) {
            report($exception);

            return response()->view('orders.unavailable', [
                'title' => 'Unable to load production', 'message' => 'Production progress could not be loaded. Please try again.',
                'returnToOrdersUrl' => route('orders.index'), 'retryUrl' => route('production.index'),
            ], 503);
        }

        return view('production.index', [...$data, 'filters' => $filters, 'board' => $board]);
    }
}
