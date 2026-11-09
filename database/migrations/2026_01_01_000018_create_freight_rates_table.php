<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freight_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('origin_zone_id')->constrained('billing_zones')->cascadeOnDelete();
            $table->foreignId('destination_zone_id')->constrained('billing_zones')->cascadeOnDelete();
            $table->string('cargo_type', 10)->default('GCR');
            $table->string('rate_type', 100)->default('');
            $table->string('vehicle_type', 30)->nullable();
            $table->string('pricing_method', 32)->default('by_vehicle');
            $table->json('prices');
            $table->json('range_prices')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'origin_zone_id', 'destination_zone_id'], 'freight_rates_cust_origin_dest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_rates');
    }
};
