<?php

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = $argv[1] ?? '';
if (! preg_match('/^quique_phase1_concurrency_test_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Nombre de base de pruebas no permitido.\n");
    exit(2);
}

$configuration = config('database.connections.mysql');
$dsn = sprintf(
    'mysql:host=%s;port=%s;charset=%s',
    $configuration['host'],
    $configuration['port'],
    $configuration['charset'] ?? 'utf8mb4',
);
$pdo = new PDO($dsn, $configuration['username'], $configuration['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$exists = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$exists->execute([$database]);

if ($exists->fetchColumn() !== false) {
    echo "Base aislada ya existente: {$database}\n";
    exit(0);
}

$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Base aislada creada: {$database}\n";
