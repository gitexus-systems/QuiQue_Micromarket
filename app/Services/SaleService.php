<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Enums\MeasurementUnit;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private InventoryService $inventory, private SaleNumberGenerator $numbers, private RaffleParticipationService $raffles) {}

    /** @param array<string, mixed> $checkout */
    public function fingerprint(User $user, array $checkout): string
    {
        $items = collect($checkout['items'])
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => $this->decimal((string) $item['quantity'], 3),
            ])
            ->sortBy('product_id')
            ->values()
            ->all();

        $payment = match ($checkout['payment_type']) {
            'cash' => ['type' => 'cash', 'received' => $this->nullableDecimal($checkout['cash_received'] ?? null, 2)],
            'qr' => ['type' => 'qr'],
            'mixed' => [
                'type' => 'mixed',
                'cash' => $this->nullableDecimal($checkout['cash_amount'] ?? null, 2),
                'qr' => $this->nullableDecimal($checkout['qr_amount'] ?? null, 2),
            ],
        };

        $raffleDecision = $checkout['raffle_decision'] ?? null;
        $raffle = ['decision' => $raffleDecision];
        if ($raffleDecision === 'participate') {
            $raffle += [
                'customer_id' => isset($checkout['customer_id']) ? (int) $checkout['customer_id'] : null,
                'full_name' => $checkout['customer_full_name'] ?? null,
                'phone' => $checkout['customer_phone'] ?? null,
                'ci' => $checkout['customer_ci'] ?? null,
            ];
        }

        return hash('sha256', json_encode([
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'items' => $items,
            'payment' => $payment,
            'raffle' => $raffle,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function existingCheckout(User $user, string $checkoutToken, string $checkoutFingerprint, bool $lock = false): ?Sale
    {
        $query = Sale::query()->where('checkout_token', $checkoutToken);
        $sale = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($sale === null) {
            return null;
        }

        if ($sale->user_id !== $user->id
            || $sale->branch_id !== $user->branch_id
            || ! is_string($sale->checkout_fingerprint)
            || ! hash_equals($sale->checkout_fingerprint, $checkoutFingerprint)) {
            throw ValidationException::withMessages([
                'checkout_token' => 'Este identificador de venta ya fue utilizado para una operación diferente.',
            ]);
        }

        return $this->loadCheckout($sale);
    }

    /** @param array<int, array{product_id:int, quantity:string, expected_price?:string}> $items
     * @param  array{customer_id?:int|null,full_name?:string|null,phone?:string|null,ci?:string|null}  $raffleCustomer
     */
    public function confirm(
        User $user,
        array $items,
        string $paymentType,
        ?string $cashReceived,
        ?string $cashAmount,
        ?string $qrAmount,
        string $checkoutToken,
        string $checkoutFingerprint,
        ?string $raffleDecision = null,
        array $raffleCustomer = [],
    ): Sale {
        try {
            return DB::transaction(function () use ($user, $items, $paymentType, $cashReceived, $cashAmount, $qrAmount, $checkoutToken, $checkoutFingerprint, $raffleDecision, $raffleCustomer) {
                if ($existing = $this->existingCheckout($user, $checkoutToken, $checkoutFingerprint)) {
                    return $existing;
                }

                if ($user->branch_id === null) {
                    throw new AuthorizationException('El usuario no tiene una sucursal asignada.');
                }
                $branch = Branch::query()->whereKey($user->branch_id)->where('is_active', true)->lockForUpdate()->first();
                if ($branch === null) {
                    throw new AuthorizationException('La sucursal no está disponible.');
                }

                if ($existing = $this->existingCheckout($user, $checkoutToken, $checkoutFingerprint, lock: true)) {
                    return $existing;
                }

                $quantities = collect($items)->mapWithKeys(fn ($item) => [(int) $item['product_id'] => (string) $item['quantity']]);
                $expectedPrices = collect($items)->mapWithKeys(fn ($item) => [(int) $item['product_id'] => $item['expected_price'] ?? null]);
                $products = Product::query()->whereIn('id', $quantities->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($products->count() !== $quantities->count()) {
                    throw ValidationException::withMessages(['items' => 'Uno o más productos ya no existen.']);
                }

                $lines = [];
                $total = '0.00';
                foreach ($quantities as $productId => $quantity) {
                    $product = $products->get($productId);
                    if ($product->branch_id !== $user->branch_id) {
                        throw new AuthorizationException('No puede vender productos de otra sucursal.');
                    }
                    if (! $product->is_active) {
                        throw ValidationException::withMessages(['items' => "{$product->name} está inactivo y no puede venderse."]);
                    }
                    $expectedPrice = $expectedPrices->get($productId);
                    if ($expectedPrice !== null && bccomp((string) $expectedPrice, $product->sale_price, 2) !== 0) {
                        throw ValidationException::withMessages(['items' => "El precio de \"{$product->name}\" cambió nuevamente. Actualice el carrito antes de confirmar."]);
                    }
                    if ($product->unit === MeasurementUnit::Unit && bccomp($quantity, (string) (int) $quantity, 3) !== 0) {
                        throw ValidationException::withMessages(['items' => "{$product->name} se vende por unidad y requiere una cantidad entera."]);
                    }
                    if (bccomp($product->stock, $quantity, 3) === -1) {
                        throw ValidationException::withMessages(['items' => "Stock insuficiente para {$product->name}. Disponible: {$product->stock}."]);
                    }
                    $subtotal = bcadd(bcmul($quantity, $product->sale_price, 3), '0.005', 2);
                    $total = bcadd($total, $subtotal, 2);
                    $lines[] = compact('product', 'quantity', 'subtotal');
                }

                $payments = $this->payments($paymentType, $total, $cashReceived, $cashAmount, $qrAmount);
                $sale = Sale::query()->create(['branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'PENDING-'.bin2hex(random_bytes(6)), 'checkout_token' => $checkoutToken, 'checkout_fingerprint' => $checkoutFingerprint, 'subtotal' => $total, 'total' => $total, 'status' => SaleStatus::Confirmed, 'confirmed_at' => now()]);
                $sale->update(['sale_number' => $this->numbers->forNextId($branch, $sale->id)]);

                foreach ($lines as $line) {
                    $sale->items()->create(['product_id' => $line['product']->id, 'product_name' => $line['product']->name, 'unit' => $line['product']->unit, 'quantity' => $line['quantity'], 'unit_price' => $line['product']->sale_price, 'subtotal' => $line['subtotal']]);
                    $this->inventory->record($user, $line['product'], InventoryMovementType::Exit, $line['quantity'], 'Venta '.$sale->sale_number, null, $sale);
                }
                foreach ($payments as $payment) {
                    $sale->payments()->create($payment);
                }
                $participation = $this->raffles->offer($sale, $branch->raffle_ticket_threshold);
                if ($participation !== null) {
                    if (! in_array($raffleDecision, ['participate', 'decline'], true)) {
                        throw ValidationException::withMessages([
                            'raffle_decision' => 'Indique si el cliente participará en el sorteo antes de confirmar la venta.',
                        ]);
                    }

                    if ($raffleDecision === 'participate') {
                        $this->raffles->accept($user, $sale, $raffleCustomer);
                    } else {
                        $this->raffles->decline($user, $sale);
                    }
                }

                return $this->loadCheckout($sale->refresh());
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isCheckoutTokenConflict($exception)) {
                throw $exception;
            }

            $existing = $this->existingCheckout($user, $checkoutToken, $checkoutFingerprint);
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    private function loadCheckout(Sale $sale): Sale
    {
        return $sale->load(['items.product', 'payments', 'user', 'customer', 'raffleParticipation.tickets']);
    }

    private function isCheckoutTokenConflict(UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'sales_checkout_token_unique')
            || str_contains($message, 'sales.checkout_token');
    }

    private function decimal(string $value, int $scale): string
    {
        return bcadd($value, '0', $scale);
    }

    private function nullableDecimal(mixed $value, int $scale): ?string
    {
        return $value === null || $value === '' ? null : $this->decimal((string) $value, $scale);
    }

    /** @return array<int, array<string, mixed>> */
    private function payments(string $type, string $total, ?string $received, ?string $cash, ?string $qr): array
    {
        if ($type === 'cash') {
            if ($received === null || bccomp($received, $total, 2) === -1) {
                throw ValidationException::withMessages(['cash_received' => 'El efectivo recibido no alcanza para cubrir el total.']);
            }

            return [['method' => PaymentMethod::Cash, 'amount' => $total, 'received_amount' => $received, 'change_amount' => bcsub($received, $total, 2)]];
        }
        if ($type === 'qr') {
            return [['method' => PaymentMethod::Qr, 'amount' => $total, 'received_amount' => null, 'change_amount' => null]];
        }

        $cash ??= '0';
        $qr ??= '0';
        if (bccomp($cash, '0', 2) !== 1 || bccomp($qr, '0', 2) !== 1 || bccomp(bcadd($cash, $qr, 2), $total, 2) !== 0) {
            throw ValidationException::withMessages(['payment' => 'En un pago mixto, efectivo y QR deben ser mayores que cero y sumar exactamente el total.']);
        }

        return [
            ['method' => PaymentMethod::Cash, 'amount' => $cash, 'received_amount' => $cash, 'change_amount' => '0.00'],
            ['method' => PaymentMethod::Qr, 'amount' => $qr, 'received_amount' => null, 'change_amount' => null],
        ];
    }
}
