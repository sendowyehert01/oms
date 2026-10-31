<?php

namespace App;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

class OrderEntries
{
    public const array STATUSES = ['Pending', 'In production', 'Ready for delivery', 'Completed'];

    /**
     * @return Collection<int, array{id: int, number: string, customer_name: string, order_date: string, status: string, total_amount_in_cents: int}>
     */
    public function all(): Collection
    {
        if (! Schema::hasTable('orders')) {
            return collect();
        }

        $orders = DB::table('orders')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->select('orders.id', 'orders.order_date', 'orders.status', 'customers.company_name')
            ->orderByDesc('orders.id')
            ->get();

        $itemsByOrder = DB::table('order_items')
            ->select('order_id', 'quantity', 'unit_price')
            ->whereIn('order_id', $orders->pluck('id'))
            ->get()
            ->groupBy('order_id');

        return $orders->map(fn (stdClass $order): array => [
            'id' => (int) $order->id,
            'number' => $this->number((int) $order->id, $order->order_date),
            'customer_name' => $order->company_name,
            'order_date' => $order->order_date,
            'status' => $order->status,
            'total_amount_in_cents' => $itemsByOrder->get($order->id, collect())
                ->sum(fn (stdClass $item): int => (int) $item->quantity * $this->priceInCents((string) $item->unit_price)),
        ]);
    }

    /**
     * @param  array{submission_key: string, customer_id?: int|string|null, customer_name?: string, contact_person?: string, address?: string, contact_number?: string, order_date: string, due_date: string, status: string, items: array<int, array{product_name: string, quantity: int|string, unit_price: int|float|string}>}  $data
     */
    public function create(array $data): int
    {
        $existingOrderId = DB::table('orders')->where('submission_key', $data['submission_key'])->value('id');

        if ($existingOrderId !== null) {
            return (int) $existingOrderId;
        }

        try {
            return DB::transaction(function () use ($data): int {
                $timestamp = now();
                $customerId = $data['customer_id'] ?? DB::table('customers')->insertGetId([
                    'company_name' => $data['customer_name'],
                    'contact_person' => $data['contact_person'],
                    'address' => $data['address'],
                    'contact_number' => $data['contact_number'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $orderId = DB::table('orders')->insertGetId([
                    'customer_id' => $customerId,
                    'order_date' => $data['order_date'],
                    'due_date' => $data['due_date'],
                    'status' => $data['status'],
                    'submission_key' => $data['submission_key'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                DB::table('order_items')->insert(array_map(fn (array $item): array => [
                    'order_id' => $orderId,
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], $data['items']));

                return $orderId;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existingOrderId = DB::table('orders')->where('submission_key', $data['submission_key'])->value('id');

            if ($existingOrderId === null) {
                throw $exception;
            }

            return (int) $existingOrderId;
        }
    }

    public function number(int $orderId, string $orderDate): string
    {
        return sprintf('ORD-%s-%04d', substr($orderDate, 0, 4), $orderId);
    }

    private function priceInCents(string $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
