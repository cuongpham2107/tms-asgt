<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('freight_rate_id')->nullable()->constrained('freight_rates')->nullOnDelete();
            $table->string('price_code')->nullable();
            $table->string('cargo_type', 10)->nullable();
            $table->string('rate_type', 100)->default('');
            $table->string('surcharge_type', 100)->default('');
            $table->decimal('chargeable_tonnage', 5, 2)->nullable();
            $table->boolean('is_return_trip')->default(false);
            $table->decimal('return_trip_percent', 5, 2)->default(50);
            $table->json('delivery_notes')->nullable();
            $table->text('note')->nullable();
            $table->decimal('base_price', 14, 2)->nullable();
            $table->decimal('fuel_adjustment_percent', 6, 2)->default(0);
            $table->decimal('adjusted_price', 14, 2)->nullable();
            $table->integer('waiting_minutes')->default(0);
            $table->decimal('waiting_fee', 14, 2)->default(0);
            $table->decimal('other_fees_total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_percent', 5, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manually_updated_at')->nullable();
            $table->boolean('is_cancelled_trip')->default(false);
            $table->decimal('cancel_trip_percent', 5, 2)->default(0);
            $table->timestamps();

            $table->index('order_id');
            $table->index('trip_id');
            $table->index('customer_id');
        });

        Schema::create('order_charge_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_charge_id')->constrained('order_charges')->cascadeOnDelete();
            $table->foreignId('fee_type_id')->constrained('fee_types');
            $table->decimal('amount', 14, 2);
            $table->decimal('quantity', 10, 2)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_auto')->default(false);
            $table->timestamps();

            $table->index('order_charge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_charge_fees');
        Schema::dropIfExists('order_charges');
    }
};
