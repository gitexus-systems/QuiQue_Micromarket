<?php

use App\Models\Product;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = $argv[1];
$productId = $argv[2];
$checkoutToken = $argv[3];
$barrier = $argv[4];
$workerId = $argv[5] ?? (string) getmypid();
$readyFile = $barrier.'.'.$workerId.'.ready';
$goFile = $barrier.'.go';
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
DB::beginTransaction();
$transactionStartedAt = microtime(true);
$readyAt = microtime(true);
touch($readyFile);
$deadline = microtime(true) + 15;
while (! file_exists($goFile)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, json_encode([
            'worker_id' => $workerId,
            'connection_id' => $connectionId,
            'transaction_started_at' => $transactionStartedAt,
            'ready_at' => $readyAt,
            'error' => 'Tiempo de espera agotado en la barrera.',
        ]));
        DB::rollBack();
        exit(2);
    }
    usleep(10_000);
}

$startedAt = microtime(true);
try {
    $user = User::query()->findOrFail((int) $userId);
    $product = Product::query()->findOrFail((int) $productId);
    $checkout = [
        'checkout_token' => $checkoutToken,
        'items' => [['product_id' => $product->id, 'quantity' => '1']],
        'payment_type' => 'cash',
        'cash_received' => $product->sale_price,
        'cash_amount' => null,
        'qr_amount' => null,
        'raffle_decision' => 'decline',
    ];
    $service = app(SaleService::class);
    $sale = $service->confirm(
        $user,
        [['product_id' => $product->id, 'quantity' => '1', 'expected_price' => $product->sale_price]],
        'cash',
        $product->sale_price,
        null,
        null,
        $checkoutToken,
        $service->fingerprint($user, $checkout),
        'decline',
    );

    DB::commit();
    echo json_encode([
        'worker_id' => $workerId,
        'connection_id' => $connectionId,
        'user_id' => $user->id,
        'checkout_token' => $checkoutToken,
        'transaction_started_at' => $transactionStartedAt,
        'ready_at' => $readyAt,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
        'sale_id' => $sale->id,
        'sale_number' => $sale->sale_number,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, json_encode([
        'worker_id' => $workerId,
        'connection_id' => $connectionId,
        'user_id' => (int) $userId,
        'checkout_token' => $checkoutToken,
        'transaction_started_at' => $transactionStartedAt,
        'ready_at' => $readyAt,
        'started_at' => $startedAt,
        'finished_at' => microtime(true),
        'class' => $exception::class,
        'error' => $exception->getMessage(),
        'validation_errors' => $exception instanceof ValidationException ? $exception->errors() : null,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
