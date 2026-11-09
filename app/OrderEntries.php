<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class OrderEntries
{
    public const array STATUSES = [OrderStatus::Pending->value, OrderStatus::InProduction->value, OrderStatus::ReadyForDelivery->value, OrderStatus::Delivered->value, OrderStatus::Completed->value];

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return Collection<int, array{id: int, number: string, customer_name: string, order_date: string, status: string, total_amount_in_cents: int}>
     */
    public function all(User $user): Collection
    {
        Gate::forUser($user)->authorize('viewAny', Order::class);
        Gate::forUser($user)->authorize('view-financials');

        if (! Schema::hasTable('orders')) {
            return collect();
        }

        return Order::query()
            ->visibleTo($user)
            ->select('id', 'customer_id', 'order_date', 'status')
            ->with(['customer:id,company_name', 'items:id,order_id,quantity,unit,unit_price'])
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
     * @return array{id: int, number: string, customer_name: string, order_date: string, terms_of_payment: ?string, due_date: ?string, status: string, total_amount_in_cents: int, customer: array{id: int, name: string, contact_person: string, address: string, contact_number: string}, items: array<int, array{product_name: string, size: ?string, customization_notes: ?string, material_color: ?string, print_design_colors: ?string, sealing: ?string, sealing_other: ?string, puncher: ?string, puncher_other: ?string, quantity: string, measurement: string, unit_price_in_cents: int, subtotal_in_cents: int}>}|null
     */
    public function find(int $orderId, User $user): ?array
    {
        if (! Schema::hasTable('orders')) {
            return null;
        }

        $order = Order::query()->visibleTo($user)->with(['customer', 'items'])->find($orderId);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);
        Gate::forUser($user)->authorize('viewFinancials', $order);

        $items = $order->items->map(fn (OrderItem $item): array => [
            'product_name' => $item->product_name,
            'material_color' => $item->material_color,
            'size' => $item->size,
            'print_design_colors' => $item->print_design_colors,
            'sealing' => $item->sealing,
            'sealing_other' => $item->sealing_other,
            'puncher' => $item->puncher,
            'puncher_other' => $item->puncher_other,
            'customization_notes' => $item->customization_notes,
            'quantity' => $item->quantity,
            'measurement' => $item->measurement()->displayValue(),
            'unit_price_in_cents' => $this->priceInCents((string) $item->unit_price),
            'subtotal_in_cents' => $this->itemSubtotalInCents($item),
        ]);

        return [
            'id' => $order->id,
            'number' => $this->number($order->id, $order->order_date->toDateString()),
            'customer_name' => $order->customer->company_name,
            'order_date' => $order->order_date->toDateString(),
            'terms_of_payment' => $order->terms_of_payment,
            'due_date' => $order->due_date?->toDateString(),
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

    /**
     * @return array{status: 'deleted'|'missing'|'protected', number?: string}
     */
    public function delete(int $orderId, User $user): array
    {
        return DB::transaction(function () use ($orderId, $user): array {
            $order = Order::query()->visibleTo($user)->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null) {
                return ['status' => 'missing'];
            }

            Gate::forUser($user)->authorize('delete', $order);

            if ($order->payments()->exists()) {
                return ['status' => 'protected'];
            }

            $order->delete();
            $this->audit->orderDeleted($user, $order);

            return ['status' => 'deleted', 'number' => $this->number($order->id, $order->order_date->toDateString())];
        });
    }

    /**
     * @param  array{submission_key: string, customer_id?: int|string|null, customer_name?: string, contact_person?: string, address?: string, contact_number?: string, order_date: string, terms_of_payment: string, status: string, items: array<int, array{product_name: string, material_color?: ?string, size?: ?string, print_design_colors?: ?string, sealing?: ?string, sealing_other?: ?string, puncher?: ?string, puncher_other?: ?string, customization_notes?: ?string, quantity: int|float|string, unit?: string, unit_price: int|float|string}>}  $data
     */
    public function create(array $data, User $user): int
    {
        Gate::forUser($user)->authorize('create', Order::class);
        $existingOrderId = $this->submittedOrderId($data['submission_key'], $user);

        if ($existingOrderId !== null) {
            return (int) $existingOrderId;
        }

        try {
            return DB::transaction(function () use ($data, $user): int {
                $customer = isset($data['customer_id']) ? Customer::query()->lockForUpdate()->findOrFail($data['customer_id']) : new Customer([
                    'company_name' => $data['customer_name'],
                    'contact_person' => $data['contact_person'],
                    'address' => $data['address'],
                    'contact_number' => $data['contact_number'],
                ]);

                if (! $customer->exists) {
                    Gate::forUser($user)->authorize('create', Customer::class);
                    $customer->creator()->associate($user);
                    $customer->save();
                    $this->audit->clientCreated($user, $customer);
                }

                Gate::forUser($user)->authorize('view', $customer);

                $order = $customer->orders()->make([
                    'order_date' => $data['order_date'],
                    'terms_of_payment' => $data['terms_of_payment'],
                    'status' => OrderStatus::Pending->value,
                    'submission_key' => $data['submission_key'],
                ]);

                $order->target_delivery_date = $data['target_delivery_date'] ?? null;
                $order->creator()->associate($user);
                if (filled($data['payment_due_date'] ?? null)) {
                    Gate::forUser($user)->authorize('setPaymentDueDate', $order);
                }
                $order->payment_due_date = $data['payment_due_date'] ?? null;
                $order->save();

                $order->items()->createMany(array_map(fn (array $item): array => [
                    'product_name' => $item['product_name'],
                    'material_color' => $item['material_color'] ?? null,
                    'size' => $item['size'] ?? null,
                    'print_design_colors' => $item['print_design_colors'] ?? null,
                    'sealing' => $item['sealing'] ?? null,
                    'sealing_other' => ($item['sealing'] ?? null) === 'Others' ? ($item['sealing_other'] ?? null) : null,
                    'puncher' => $item['puncher'] ?? null,
                    'puncher_other' => ($item['puncher'] ?? null) === 'Others' ? ($item['puncher_other'] ?? null) : null,
                    'customization_notes' => $item['customization_notes'] ?? null,
                    'quantity' => ItemMeasurement::fromInput($item['quantity'], $item['unit'] ?? 'pcs')->storageValue(),
                    'unit' => $item['unit'] ?? 'pcs',
                    'unit_price' => $item['unit_price'],
                ], $data['items']));

                $order->statusHistory()->create([
                    'actor_id' => $user->id,
                    'actor_name' => $user->name,
                    'event_type' => 'order_created',
                    'from_status' => null,
                    'to_status' => OrderStatus::Pending->value,
                    'submission_key' => $data['submission_key'],
                ]);
                $this->audit->orderCreated($user, $order);

                return $order->id;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existingOrderId = $this->submittedOrderId($data['submission_key'], $user);

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

    private function submittedOrderId(string $submissionKey, User $user): ?int
    {
        $order = Order::query()->where('submission_key', $submissionKey)->first(['id', 'customer_id', 'created_by']);

        if ($order === null) {
            return null;
        }

        abort_unless($order->created_by === $user->id, 404);
        Gate::forUser($user)->authorize('view', $order);

        return $order->id;
    }

    private function priceInCents(string $price): int
    {
        return MoneyAmount::inCents($price);
    }

    private function itemSubtotalInCents(OrderItem $item): int
    {
        return $item->subtotalInCents();
    }
}
