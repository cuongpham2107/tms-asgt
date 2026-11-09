<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiting_fee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('surcharge_type', 100)->default('');
            $table->string('waiting_mode', 20)->default('normal');
            $table->json('tiers');
            $table->smallInteger('fee_block_minutes')->default(60);
            $table->smallInteger('grace_minutes')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'surcharge_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiting_fee_rates');
    }
};
