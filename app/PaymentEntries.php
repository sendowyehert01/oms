<?php

namespace App;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentEntries
{
    public function __construct(private readonly PaymentReceiptStorage $receiptStorage) {}

    /**
     * @return array{total_amount_in_cents: int, total_paid_in_cents: int, balance_in_cents: int, status: string, payments: array<int, array{id: int, date: string, amount_in_cents: int, type: string, receipt_name: ?string, receipt_url: ?string}>}
     */
    public function summary(Order $order): array
    {
        $order->loadMissing(['items', 'payments']);
        $totalInCents = $order->items->sum(fn (OrderItem $item): int => $item->subtotalInCents());
        $paidInCents = $order->payments->sum(fn (Payment $payment): int => MoneyAmount::inCents($payment->amount));

        return [
            'total_amount_in_cents' => $totalInCents,
            'total_paid_in_cents' => $paidInCents,
            'balance_in_cents' => max(0, $totalInCents - $paidInCents),
            'status' => $paidInCents >= $totalInCents ? 'Fully paid' : ($paidInCents > 0 ? 'Partially paid' : 'Unpaid'),
            'payments' => $order->payments->sortBy('payment_date')->values()->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'date' => $payment->payment_date->toDateString(),
                'amount_in_cents' => MoneyAmount::inCents($payment->amount),
                'type' => Payment::TYPES[$payment->payment_type] ?? $payment->payment_type,
                'receipt_name' => $payment->receipt_name,
                'receipt_url' => $payment->receipt_path === null ? null : route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]),
            ])->all(),
        ];
    }

    /** @param array{submission_key: string, amount: string|int|float, payment_date: string, payment_type: string, receipt?: ?UploadedFile} $data */
    public function create(int $orderId, array $data): Payment
    {
        $receipt = $data['receipt'] ?? null;
        $storedReceipt = null;

        try {
            return DB::transaction(function () use ($orderId, $data, $receipt, &$storedReceipt): Payment {
                $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
                $amountInCents = MoneyAmount::inCents((string) $data['amount']);
                $existingPayment = Payment::query()->where('submission_key', $data['submission_key'])->first();

                if ($existingPayment !== null) {
                    if ($existingPayment->order_id !== $order->id
                        || MoneyAmount::inCents($existingPayment->amount) !== $amountInCents
                        || $existingPayment->payment_date->toDateString() !== $data['payment_date']
                        || $existingPayment->payment_type !== $data['payment_type']
                        || ($receipt !== null && $existingPayment->receipt_sha256 !== hash('sha256', $receipt->getContent()))) {
                        throw ValidationException::withMessages(['submission_key' => 'This payment form was already used. Reload the page to record a new payment.']);
                    }

                    return $existingPayment;
                }

                $balanceInCents = $this->summary($order)['balance_in_cents'];

                if ($balanceInCents === 0) {
                    throw ValidationException::withMessages(['amount' => 'This order has no remaining balance.']);
                }

                if ($amountInCents > $balanceInCents) {
                    throw ValidationException::withMessages(['amount' => 'The amount must not exceed the remaining balance.']);
                }

                if ($data['payment_type'] === 'full_payment' && $amountInCents !== $balanceInCents) {
                    throw ValidationException::withMessages(['amount' => 'Fully Paid must equal the remaining balance.']);
                }

                if ($data['payment_type'] === 'down_payment' && $amountInCents === $balanceInCents) {
                    throw ValidationException::withMessages(['payment_type' => 'Choose Fully Paid when settling the remaining balance.']);
                }

                if ($receipt !== null) {
                    $storedReceipt = $this->receiptStorage->store($receipt);
                }

                return $order->payments()->create([
                    'submission_key' => $data['submission_key'],
                    'amount' => MoneyAmount::decimal($amountInCents),
                    'payment_date' => $data['payment_date'],
                    'payment_type' => $data['payment_type'],
                    ...($storedReceipt ?? []),
                ]);
            }, attempts: $receipt === null ? 3 : 1);
        } catch (Throwable $exception) {
            if ($storedReceipt !== null) {
                $this->receiptStorage->delete($storedReceipt);
            }

            throw $exception;
        }
    }
}
