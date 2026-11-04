<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrderItemProductionRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\OrderItemWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderItemProductionController extends Controller
{
    public function __invoke(UpdateOrderItemProductionRequest $request, Order $order, OrderItem $item, OrderItemWorkflow $workflow): RedirectResponse
    {
        $data = [
            ...$request->validated(),
            'workflow_version' => (int) $request->validated('workflow_version'),
            'notes' => $request->validated('notes') ?? null,
            'quality_checked' => $request->boolean('quality_checked'),
        ];
        try {
            $workflow->update($order, $item, $request->user(), $data);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return to_route('orders.show', $order)->withInput()->with('error', 'The item progress could not be saved. Please try again.');
        }

        return to_route('orders.show', $order)->with('success', 'Item availability and progress saved successfully.');
    }
}
