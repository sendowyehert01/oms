<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrderItemProductionRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\OrderItemWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderItemProductionController extends Controller
{
    public function __invoke(UpdateOrderItemProductionRequest $request, Order $order, OrderItem $item, OrderItemWorkflow $workflow): JsonResponse|RedirectResponse
    {
        $data = [
            ...$request->validated(),
            'workflow_version' => (int) $request->validated('workflow_version'),
            'notes' => $request->validated('notes') ?? null,
            'quality_checked' => $request->boolean('quality_checked'),
        ];
        try {
            $updatedOrder = $workflow->update($order, $item, $request->user(), $data);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            if ($request->hasHeader('X-Order-Checklist-Fragment') && $request->expectsJson()) {
                return response()->json(['message' => 'The item progress could not be saved. Please try again.'], 503);
            }

            return to_route('orders.show', $order)->withInput()->with('error', 'The item progress could not be saved. Please try again.');
        }

        if ($request->hasHeader('X-Order-Checklist-Fragment') && $request->expectsJson()) {
            return response()->json(['message' => 'Item availability and progress saved successfully.', 'workflow_version' => $updatedOrder->workflow_version]);
        }

        return to_route('orders.show', $order)->with('success', 'Item availability and progress saved successfully.');
    }
}
