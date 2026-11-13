<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\RaffleTicket;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SaleCheckoutIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Category $category;

    private Role $cashierRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create([
            'code' => 'PRINCIPAL',
            'raffle_ticket_threshold' => '50.00',
        ]);
        $this->category = Category::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierRole = Role::factory()->create(['slug' => RoleSlug::Cashier->value]);
    }

    public function test_exact_replay_returns_the_original_sale_without_duplicate_effects(): void
    {
        $user = $this->cashier();
        $product = $this->product(['sale_price' => '20.00']);
        $checkout = $this->checkout($product, (string) Str::uuid());
        $this->putCart($user, $product);

        $first = $this->actingAs($user)->post(route('pos.sales.store'), $checkout);
        $sale = Sale::query()->sole();
        $first->assertRedirect(route('sales.show', $sale));

        $this->post(route('pos.sales.store'), $checkout)
            ->assertRedirect(route('sales.show', $sale))
            ->assertSessionHas('status', "Venta {$sale->sale_number} recuperada correctamente.");

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseCount('payment_details', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame('9.000', $product->fresh()->stock);
    }

    public function test_reusing_a_token_for_different_content_is_rejected(): void
    {
        $user = $this->cashier();
        $product = $this->product(['sale_price' => '20.00']);
        $token = (string) Str::uuid();
        $checkout = $this->checkout($product, $token);
        $this->putCart($user, $product);
        $this->actingAs($user)->post(route('pos.sales.store'), $checkout)->assertSessionHasNoErrors();

        $altered = $checkout;
        $altered['items'][0]['quantity'] = '2';
        $altered['cash_received'] = '40.00';
        $this->post(route('pos.sales.store'), $altered)->assertSessionHasErrors('checkout_token');

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame('9.000', $product->fresh()->stock);
    }

    public function test_a_new_token_allows_a_new_legitimate_sale(): void
    {
        $user = $this->cashier();
        $product = $this->product(['sale_price' => '20.00']);

        foreach ([(string) Str::uuid(), (string) Str::uuid()] as $token) {
            $this->putCart($user, $product);
            $this->actingAs($user)->post(route('pos.sales.store'), $this->checkout($product, $token))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('sales', 2);
        $this->assertDatabaseCount('sale_items', 2);
        $this->assertDatabaseCount('payment_details', 2);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame('8.000', $product->fresh()->stock);
    }

    public function test_raffle_customer_and_tickets_are_idempotent_with_the_sale(): void
    {
        $user = $this->cashier();
        $product = $this->product(['sale_price' => '100.00']);
        $checkout = $this->checkout($product, (string) Str::uuid(), [
            'cash_received' => '100.00',
            'raffle_decision' => 'participate',
            'customer_full_name' => 'Cliente Nuevo',
            'customer_phone' => '70000123',
        ]);
        $this->putCart($user, $product);

        $this->actingAs($user)->post(route('pos.sales.store'), $checkout)->assertSessionHasNoErrors();
        $this->post(route('pos.sales.store'), $checkout)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('raffle_participations', 1);
        $this->assertDatabaseCount('raffle_tickets', 2);
        $this->assertSame('9.000', $product->fresh()->stock);
    }

    public function test_ticket_failure_rolls_back_the_entire_sale_and_same_token_can_retry(): void
    {
        $user = $this->cashier();
        $product = $this->product(['sale_price' => '100.00']);
        $checkout = $this->checkout($product, (string) Str::uuid(), [
            'cash_received' => '100.00',
            'raffle_decision' => 'participate',
            'customer_full_name' => 'Cliente Atómico',
            'customer_phone' => '70000456',
        ]);
        $this->putCart($user, $product);
        $mustFail = true;
        RaffleTicket::creating(function () use (&$mustFail): void {
            if ($mustFail) {
                $mustFail = false;
                throw new RuntimeException('fallo simulado al emitir ticket');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post(route('pos.sales.store'), $checkout);
            $this->fail('La excepción esperada no fue lanzada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fallo simulado al emitir ticket', $exception->getMessage());
        }

        foreach (['sales', 'sale_items', 'payment_details', 'inventory_movements', 'customers', 'raffle_participations', 'raffle_periods', 'raffle_tickets'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('10.000', $product->fresh()->stock);

        $this->withExceptionHandling();
        $this->post(route('pos.sales.store'), $checkout)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('raffle_tickets', 2);
        $this->assertSame('9.000', $product->fresh()->stock);
    }

    private function checkout(Product $product, string $token, array $overrides = []): array
    {
        return [
            'checkout_token' => $token,
            'items' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_type' => 'cash',
            'cash_received' => '20.00',
            'cash_amount' => null,
            'qr_amount' => null,
            'raffle_decision' => 'decline',
            ...$overrides,
        ];
    }

    private function putCart(User $user, Product $product): void
    {
        session([
            'pos.cart.'.$user->id => [
                $product->id => ['quantity' => '1', 'observed_price' => $product->sale_price],
            ],
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'stock' => '10.000',
            ...$attributes,
        ]);
    }

    private function cashier(): User
    {
        return User::factory()->create([
            'branch_id' => $this->branch->id,
            'role_id' => $this->cashierRole->id,
        ]);
    }
}
