<?php

namespace Tests\Feature;

use App\Enums\MeasurementUnit;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductCodeGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ProductCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->category = Category::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_insert_time_internal_code_collision_is_retried(): void
    {
        Str::createRandomStringsUsingSequence(['AAAAAAAAAAAA', 'BBBBBBBBBBBB']);
        $collisionsRemaining = 1;
        Product::creating(function (Product $product) use (&$collisionsRemaining): void {
            if ($collisionsRemaining-- > 0) {
                $this->insertConflictingProduct($product->internal_code);
            }
        });

        try {
            $product = app(ProductCodeGenerator::class)->createProduct($this->attributes('Producto definitivo'));
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('PRD-BBBBBBBBBBBB', $product->internal_code);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['internal_code' => 'PRD-AAAAAAAAAAAA']);
    }

    public function test_retry_exhaustion_fails_clearly_without_an_unidentified_product(): void
    {
        $suffixes = collect(range(0, 9))->map(fn (int $number): string => str_pad((string) $number, 12, '0', STR_PAD_LEFT))->all();
        Str::createRandomStringsUsingSequence($suffixes);
        $collisionsRemaining = 10;
        Product::creating(function (Product $product) use (&$collisionsRemaining): void {
            if ($collisionsRemaining-- > 0) {
                $this->insertConflictingProduct($product->internal_code);
            }
        });

        try {
            app(ProductCodeGenerator::class)->createProduct($this->attributes('Producto que no debe guardarse'));
            $this->fail('La excepción por agotamiento de reintentos no fue lanzada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('No se pudo guardar un producto con un código interno único.', $exception->getMessage());
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertDatabaseCount('products', 10);
        $this->assertDatabaseMissing('products', ['name' => 'Producto que no debe guardarse']);
    }

    public function test_unrelated_unique_violation_is_not_swallowed_or_retried(): void
    {
        $attempts = 0;
        Product::creating(function () use (&$attempts): void {
            $attempts++;
            throw new UniqueConstraintViolationException(
                'sqlite',
                'insert into products',
                [],
                new PDOException('UNIQUE constraint failed: products.barcode'),
            );
        });

        try {
            app(ProductCodeGenerator::class)->createProduct($this->attributes('Otra colisión'));
            $this->fail('La colisión ajena al código interno no fue propagada.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, $attempts);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(string $name): array
    {
        return [
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'barcode' => null,
            'name' => $name,
            'unit' => MeasurementUnit::Unit->value,
            'purchase_price' => '5.00',
            'sale_price' => '10.00',
            'minimum_stock' => '1.000',
            'expires_at' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    private function insertConflictingProduct(string $internalCode): void
    {
        DB::table('products')->insert([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'internal_code' => $internalCode,
            'barcode' => null,
            'name' => 'Producto que ganó la carrera '.$internalCode,
            'unit' => MeasurementUnit::Unit->value,
            'purchase_price' => '1.00',
            'sale_price' => '2.00',
            'stock' => '0.000',
            'minimum_stock' => '0.000',
            'expires_at' => null,
            'is_active' => true,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
