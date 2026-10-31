<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        return Order::query()
            ->select('id', 'customer_id', 'order_date', 'status')
            ->with(['customer:id,company_name', 'items:id,order_id,quantity,unit_price'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'number' => $this->number($order->id, $order->order_date->toDateString()),
                'customer_name' => $order->customer->company_name,
                'order_date' => $order->order_date->toDateString(),
                'status' => $order->status,
                'total_amount_in_cents' => $order->items->sum(fn (OrderItem $item): int => $this->itemSubtotalInCents($item)),
            ]);
    }

    /**
     * @return array{id: int, number: string, customer_name: string, order_date: string, due_date: string, status: string, total_amount_in_cents: int, customer: array{id: int, name: string, contact_person: string, address: string, contact_number: string}, items: array<int, array{product_name: string, quantity: int, unit_price_in_cents: int, subtotal_in_cents: int}>}|null
     */
    public function find(int $orderId): ?array
    {
        if (! Schema::hasTable('orders')) {
            return null;
        }

        $order = Order::query()->with(['customer', 'items'])->find($orderId);

        if ($order === null) {
            return null;
        }

        $items = $order->items->map(fn (OrderItem $item): array => [
            'product_name' => $item->product_name,
            'quantity' => (int) $item->quantity,
            'unit_price_in_cents' => $this->priceInCents((string) $item->unit_price),
            'subtotal_in_cents' => $this->itemSubtotalInCents($item),
        ]);

        return [
            'id' => $order->id,
            'number' => $this->number($order->id, $order->order_date->toDateString()),
            'customer_name' => $order->customer->company_name,
            'order_date' => $order->order_date->toDateString(),
            'due_date' => $order->due_date->toDateString(),
            'status' => $order->status,
            'total_amount_in_cents' => $items->sum('subtotal_in_cents'),
            'customer' => [
                'id' => $order->customer_id,
                'name' => $order->customer->company_name,
                'contact_person' => $order->customer->contact_person,
                'address' => $order->customer->address,
                'contact_number' => $order->customer->contact_number,
            ],
            'items' => $items->all(),
        ];
    }

    public function hasCustomers(): bool
    {
        return Schema::hasTable('customers') && Customer::query()->exists();
    }

    /**
     * @return array{status: 'deleted'|'missing'|'protected', number?: string}
     */
    public function delete(int $orderId): array
    {
        return DB::transaction(function () use ($orderId): array {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first(['id', 'order_date']);

            if ($order === null) {
                return ['status' => 'missing'];
            }

            if ($order->payments()->exists()) {
                return ['status' => 'protected'];
            }

            $order->delete();

            return ['status' => 'deleted', 'number' => $this->number($order->id, $order->order_date->toDateString())];
        });
    }

    /**
     * @param  array{submission_key: string, customer_id?: int|string|null, customer_name?: string, contact_person?: string, address?: string, contact_number?: string, order_date: string, due_date: string, status: string, items: array<int, array{product_name: string, quantity: int|string, unit_price: int|float|string}>}  $data
     */
    public function create(array $data): int
    {
        $existingOrderId = Order::query()->where('submission_key', $data['submission_key'])->value('id');

        if ($existingOrderId !== null) {
            return (int) $existingOrderId;
        }

        try {
            return DB::transaction(function () use ($data): int {
                $customer = isset($data['customer_id']) ? Customer::query()->findOrFail($data['customer_id']) : Customer::query()->create([
                    'company_name' => $data['customer_name'],
                    'contact_person' => $data['contact_person'],
                    'address' => $data['address'],
                    'contact_number' => $data['contact_number'],
                ]);

                $order = $customer->orders()->create([
                    'order_date' => $data['order_date'],
                    'due_date' => $data['due_date'],
                    'status' => $data['status'],
                    'submission_key' => $data['submission_key'],
                ]);

                $order->items()->createMany(array_map(fn (array $item): array => [
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ], $data['items']));

                return $order->id;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existingOrderId = Order::query()->where('submission_key', $data['submission_key'])->value('id');

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

    private function itemSubtotalInCents(OrderItem $item): int
    {
        return (int) $item->quantity * $this->priceInCents((string) $item->unit_price);
    }
}
