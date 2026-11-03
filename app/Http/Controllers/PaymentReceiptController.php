<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\PaymentReceiptStorage;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PaymentReceiptController extends Controller
{
    public function show(Order $order, Payment $payment, PaymentReceiptStorage $receiptStorage): StreamedResponse|Response
    {
        abort_unless($payment->order_id === $order->id && $payment->receipt_path !== null, 404);

        try {
            $contents = $receiptStorage->contents($payment);
        } catch (Throwable $exception) {
            report($exception);

            return response()->view('orders.unavailable', [
                'title' => 'Unable to download receipt',
                'message' => 'The receipt could not be downloaded. Please try again.',
                'returnToOrdersUrl' => route('orders.show', ['order' => $order]),
                'retryUrl' => route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]),
            ], 503);
        }

        abort_if($contents === null, 404);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $payment->receipt_name, [
            'Content-Type' => $payment->receipt_mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
