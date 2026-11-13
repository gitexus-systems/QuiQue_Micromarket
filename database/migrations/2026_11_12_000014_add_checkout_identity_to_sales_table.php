<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('checkout_token')->nullable()->after('sale_number');
            $table->char('checkout_fingerprint', 64)->nullable()->after('checkout_token');
            $table->unique('checkout_token');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['checkout_token']);
            $table->dropColumn(['checkout_token', 'checkout_fingerprint']);
        });
    }
};
