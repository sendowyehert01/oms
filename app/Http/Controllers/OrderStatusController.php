<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrderStatusRequest;
use App\Models\Order;
use App\OrderWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderStatusController extends Controller
{
    public function __invoke(UpdateOrderStatusRequest $request, Order $order, OrderWorkflow $workflow): RedirectResponse
    {
        $data = [
            ...$request->validated(),
            'workflow_version' => (int) $request->validated('workflow_version'),
            'notes' => $request->validated('notes') ?? null,
            'quality_checked' => $request->boolean('quality_checked'),
        ];
        try {
            $workflow->update($order, $request->user(), $data);
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return to_route('orders.show', $order)->withInput()->with('error', 'The status could not be saved. Please try again.');
        }

        return to_route('orders.show', $order)->with('success', 'Order progress saved successfully.');
    }
}
