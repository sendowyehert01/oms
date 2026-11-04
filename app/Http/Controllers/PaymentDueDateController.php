<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePaymentDueDateRequest;
use App\Models\Order;
use App\Models\User;
use App\OrderWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentDueDateController extends Controller
{
    public function __invoke(UpdatePaymentDueDateRequest $request, Order $order, OrderWorkflow $workflow): RedirectResponse
    {
        $data = [...$request->validated(), 'action' => 'set_payment_due_date'];
        try {
            $workflow->perform($order, $request->user(), $data, function (Order $locked, User $actor, array $data) use ($workflow): void {
                $before = $locked->payment_due_date?->toDateString() ?? 'No due date';
                $locked->payment_due_date = $data['payment_due_date'];
                $notes = 'Payment due date: '.$before.' → '.($data['payment_due_date'] ?? 'No due date');
                $workflow->record($locked, $actor, $data, 'payment_due_date_changed', $locked->status, $locked->status, eventNotes: $notes);
            });
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return to_route('orders.show', $order)->withInput()->with('error', 'The payment due date could not be saved. Please try again.');
        }

        return to_route('orders.show', $order)->with('success', 'Payment due date saved.');
    }
}
