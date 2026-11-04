<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Order;
use App\Models\Payment;
use App\OrderEntries;
use App\PaymentEntries;
use App\PaymentReceiptStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PaymentController extends Controller
{
    public function create(Request $request, Order $order, PaymentEntries $paymentEntries, OrderEntries $orderEntries, PaymentReceiptStorage $receiptStorage): View
    {
        Gate::authorize('recordPayment', $order);
        $order->load('customer');
        $summary = $paymentEntries->summary($order);
        $orderNumber = $orderEntries->number($order->id, $order->order_date->toDateString());
        $submissionKey = $request->old('submission_key');
        $submissionKey = is_string($submissionKey) && Str::isUuid($submissionKey) ? $submissionKey : (string) Str::uuid();
        $paymentTypes = Payment::TYPES;
        $receiptUploadsAvailable = $receiptStorage->available();

        return view('orders.payment', compact('order', 'summary', 'orderNumber', 'submissionKey', 'paymentTypes', 'receiptUploadsAvailable'));
    }

    public function store(StorePaymentRequest $request, Order $order, PaymentEntries $paymentEntries): RedirectResponse
    {
        try {
            $paymentEntries->create($order->id, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('orders.payments.create', ['order' => $order]));
        } catch (Throwable $exception) {
            report($exception);

            return to_route('orders.payments.create', ['order' => $order])
                ->withInput()->with('error', 'The payment could not be saved. Please try again.');
        }

        return to_route('orders.show', ['order' => $order])->with('success', 'Payment recorded successfully.');
    }
}
