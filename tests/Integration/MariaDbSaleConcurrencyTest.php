<?php

namespace Tests\Integration;

use App\Enums\RoleSlug;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbSaleConcurrencyTest extends TestCase
{
    public function test_two_independent_connections_with_the_same_token_create_one_sale(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requiere la conexión MySQL/MariaDB aislada.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertMatchesRegularExpression('/^quique_phase1_concurrency_test_[a-z0-9_]+$/', $database);

        $branch = Branch::factory()->create([
            'code' => 'CONC-'.Str::upper(Str::random(6)),
            'raffle_ticket_threshold' => '999999.00',
        ]);
        $role = Role::factory()->create(['slug' => RoleSlug::Cashier->value.'-'.Str::lower(Str::random(6))]);
        $user = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id]);
        $category = Category::factory()->create(['branch_id' => $branch->id]);
        $product = Product::factory()->create([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
            'sale_price' => '10.00',
            'stock' => '1.000',
        ]);
        $token = (string) Str::uuid();

        [$first, $second] = $this->runConcurrently($user, $user, $product, $token, $token);
        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $firstResult = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $secondResult = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($firstResult['sale_id'], $secondResult['sale_id']);
        $sale = Sale::query()->where('checkout_token', $token)->sole();
        $this->assertSame($sale->id, $firstResult['sale_id']);
        $this->assertSame(1, $sale->items()->count());
        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame(1, $sale->inventoryMovements()->count());
        $this->assertSame('0.000', $product->fresh()->stock);
    }

    public function test_two_cashiers_with_different_tokens_compete_for_the_last_unit(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requiere la conexión MySQL/MariaDB aislada.');
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertMatchesRegularExpression('/^quique_phase1_concurrency_test_[a-z0-9_]+$/', $database);

        $branch = Branch::factory()->create([
            'code' => 'STOCK-'.Str::upper(Str::random(6)),
            'raffle_ticket_threshold' => '999999.00',
        ]);
        $role = Role::query()->firstOrCreate(
            ['slug' => RoleSlug::Cashier->value],
            ['name' => 'Cajero'],
        );
        $firstUser = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id]);
        $secondUser = User::factory()->create(['branch_id' => $branch->id, 'role_id' => $role->id]);
        $category = Category::factory()->create(['branch_id' => $branch->id]);
        $product = Product::factory()->create([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
            'sale_price' => '10.00',
            'stock' => '1.000',
        ]);
        $firstToken = (string) Str::uuid();
        $secondToken = (string) Str::uuid();
        $this->assertNotSame($firstUser->id, $secondUser->id);
        $this->assertNotSame($firstToken, $secondToken);

        $before = [
            'sales' => DB::table('sales')->count(),
            'sale_items' => DB::table('sale_items')->count(),
            'payment_details' => DB::table('payment_details')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
        ];

        [$first, $second] = $this->runConcurrently(
            $firstUser,
            $secondUser,
            $product,
            $firstToken,
            $secondToken,
        );
        $successful = array_values(array_filter([$first, $second], fn (Process $process): bool => $process->isSuccessful()));
        $failed = array_values(array_filter([$first, $second], fn (Process $process): bool => ! $process->isSuccessful()));
        $this->assertCount(1, $successful);
        $this->assertCount(1, $failed);

        $successResult = json_decode($successful[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $failureResult = json_decode($failed[0]->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($successResult['connection_id'], $failureResult['connection_id']);
        $this->assertLessThan(1.0, abs($successResult['started_at'] - $failureResult['started_at']));
        $this->assertSame(ValidationException::class, $failureResult['class']);
        $this->assertArrayHasKey('items', $failureResult['validation_errors']);
        $this->assertStringContainsString('Stock insuficiente', $failureResult['validation_errors']['items'][0]);

        $sales = Sale::query()->whereIn('checkout_token', [$firstToken, $secondToken])->get();
        $this->assertCount(1, $sales);
        $sale = $sales->sole();
        $this->assertSame(SaleStatus::Confirmed, $sale->status);
        $this->assertContains($sale->checkout_token, [$firstToken, $secondToken]);
        $this->assertSame($successResult['checkout_token'], $sale->checkout_token);
        $this->assertNotSame($failureResult['checkout_token'], $sale->checkout_token);
        $this->assertSame(1, $sale->items()->count());
        $this->assertSame(1, $sale->payments()->count());
        $this->assertSame(1, $sale->inventoryMovements()->count());
        $this->assertSame($before['sales'] + 1, DB::table('sales')->count());
        $this->assertSame($before['sale_items'] + 1, DB::table('sale_items')->count());
        $this->assertSame($before['payment_details'] + 1, DB::table('payment_details')->count());
        $this->assertSame($before['inventory_movements'] + 1, DB::table('inventory_movements')->count());
        $this->assertSame('0.000', $product->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, bccomp($product->fresh()->stock, '0.000', 3));
    }

    /** @return array{Process, Process} */
    private function runConcurrently(
        User $firstUser,
        User $secondUser,
        Product $product,
        string $firstToken,
        string $secondToken,
    ): array {
        $barrier = tempnam(sys_get_temp_dir(), 'quique-sale-barrier-');
        if ($barrier === false) {
            $this->fail('No se pudo crear la barrera temporal.');
        }
        unlink($barrier);
        $firstReady = $barrier.'.first.ready';
        $secondReady = $barrier.'.second.ready';
        $go = $barrier.'.go';
        $firstCommand = [
            PHP_BINARY,
            base_path('tests/Support/concurrent_sale_worker.php'),
            (string) $firstUser->id,
            (string) $product->id,
            $firstToken,
            $barrier,
            'first',
        ];
        $secondCommand = [
            PHP_BINARY,
            base_path('tests/Support/concurrent_sale_worker.php'),
            (string) $secondUser->id,
            (string) $product->id,
            $secondToken,
            $barrier,
            'second',
        ];
        $first = new Process($firstCommand, base_path(), timeout: 30);
        $second = new Process($secondCommand, base_path(), timeout: 30);

        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 15;
            while (! file_exists($firstReady) || ! file_exists($secondReady)) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Los dos procesos no alcanzaron la barrera de preparación.');
                }
                usleep(10_000);
            }
            touch($go);
            $first->wait();
            $second->wait();
        } finally {
            foreach ([$firstReady, $secondReady, $go] as $temporaryFile) {
                if (file_exists($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }
        }

        return [$first, $second];
    }
}
