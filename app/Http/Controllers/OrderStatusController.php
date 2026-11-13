<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\OrderWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderStatusController extends Controller
{
    public function __invoke(UpdateOrderStatusRequest $request, Order $order, OrderWorkflow $workflow): JsonResponse|RedirectResponse
    {
        $data = [
            ...$request->validated(),
            'workflow_version' => (int) $request->validated('workflow_version'),
            'notes' => $request->validated('notes') ?? null,
            'quality_checked' => $request->boolean('quality_checked'),
        ];
        try {
            $updatedOrder = $workflow->update($order, $request->user(), $data);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            if ($request->hasHeader('X-Order-Checklist-Fragment') && $request->expectsJson()) {
                return response()->json(['message' => 'The status could not be saved. Please try again.'], 503);
            }

            return to_route('orders.show', $order)->withInput()->with('error', 'The status could not be saved. Please try again.');
        }

        if ($request->hasHeader('X-Order-Checklist-Fragment') && $request->expectsJson()) {
            return response()->json(['message' => 'Order progress saved successfully.', 'workflow_version' => $updatedOrder->workflow_version]);
        }

        return to_route('orders.show', $order)->with('success', 'Order progress saved successfully.');
    }
}
