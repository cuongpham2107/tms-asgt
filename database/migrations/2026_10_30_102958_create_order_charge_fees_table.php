<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_charge_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_charge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['order_charge_id', 'fee_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_charge_fees');
    }
};
