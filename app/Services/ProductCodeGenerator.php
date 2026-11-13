<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

class ProductCodeGenerator
{
    private const MAX_ATTEMPTS = 10;

    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = 'PRD-'.Str::upper(Str::random(12));

            if (! Product::query()->where('internal_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('No se pudo generar un código interno único.');
    }

    /** @param array<string, mixed> $attributes */
    public function createProduct(array $attributes): Product
    {
        $lastCollision = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                return Product::query()->create([
                    ...$attributes,
                    'internal_code' => $this->generate(),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isInternalCodeConflict($exception)) {
                    throw $exception;
                }

                $lastCollision = $exception;
            }
        }

        throw new RuntimeException('No se pudo guardar un producto con un código interno único.', 0, $lastCollision);
    }

    /** @param array<string, mixed> $attributes */
    public function updateProduct(Product $product, array $attributes): void
    {
        $lastCollision = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $product->update([
                    ...$attributes,
                    'internal_code' => $this->generate(),
                ]);

                return;
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isInternalCodeConflict($exception)) {
                    throw $exception;
                }

                $lastCollision = $exception;
            }
        }

        throw new RuntimeException('No se pudo actualizar el producto con un código interno único.', 0, $lastCollision);
    }

    private function isInternalCodeConflict(UniqueConstraintViolationException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'products_internal_code_unique')
            || str_contains($message, 'products.internal_code');
    }
}
