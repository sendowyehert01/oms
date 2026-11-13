<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrderScheduleRequest;
use App\Models\Order;
use App\Models\User;
use App\OrderWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderScheduleController extends Controller
{
    public function __invoke(UpdateOrderScheduleRequest $request, Order $order, OrderWorkflow $workflow): JsonResponse|RedirectResponse
    {
        $data = [...$request->validated(), 'action' => 'schedule_delivery'];
        try {
            $updatedOrder = $workflow->perform($order, $request->user(), $data, function (Order $locked, User $actor, array $data) use ($workflow): void {
                if (in_array($locked->status, ['Delivered', 'Completed'], true)) {
                    throw ValidationException::withMessages(['target_delivery_date' => 'Reopen the order before changing a historical delivery schedule.']);
                }
                $previousDate = $locked->target_delivery_date?->toDateString() ?? 'Not scheduled';
                $locked->target_delivery_date = $data['target_delivery_date'];
                $notes = 'Target delivery: '.$previousDate.' → '.($data['target_delivery_date'] ?? 'Not scheduled');
                $workflow->record($locked, $actor, $data, 'delivery_scheduled', $locked->status, $locked->status, eventNotes: $notes);
            }, ability: 'scheduleDelivery');
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            if ($request->hasHeader('X-Order-Checklist-Fragment') && $request->expectsJson()) {
                return response()->json(['message' => 'The delivery schedule could not be saved. Please try again.'], 503);
            }

            return to_route('orders.show', $order)->withInput()->with('error', 'The delivery schedule could not be saved. Please try again.');
        }

        if ($request->hasHeader('X-Order-Checklist-Fragment') && $request->expectsJson()) {
            return response()->json(['message' => 'Delivery schedule saved.', 'workflow_version' => $updatedOrder->workflow_version]);
        }

        return to_route('orders.show', $order)->with('success', 'Delivery schedule saved.');
    }
}
