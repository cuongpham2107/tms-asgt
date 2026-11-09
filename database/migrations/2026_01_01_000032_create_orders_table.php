<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 50)->unique();
            $table->enum('type', ['HHHK', 'external'])->default('HHHK');
            $table->foreignId('area_id')->constrained('areas');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('cargo_name')->nullable();
            $table->enum('cargo_type', ['GCR', 'DGR'])->default('GCR');
            $table->integer('total_packages')->nullable();
            $table->decimal('total_weight', 10, 2)->nullable();
            $table->decimal('chargeable_weight', 10, 2)->nullable();
            $table->decimal('loaded_km', 10, 1)->nullable();
            $table->foreignId('pickup_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('pickup_address')->nullable();
            $table->string('pickup_contact')->nullable();
            $table->string('pickup_phone', 20)->nullable();
            $table->timestamp('planned_loading_at')->nullable();
            $table->foreignId('trip_id')->nullable()->unique()->constrained('trips')->nullOnDelete();
            $table->smallInteger('trip_sequence')->nullable();
            $table->enum('status', [
                'draft', 'assigned', 'sent', 'in_transit', 'driver_swap', 'completed', 'cancelled',
            ])->default('draft');
            $table->enum('priority', ['urgent', 'high', 'medium', 'low'])->default('medium');
            $table->boolean('is_return_trip')->default(false);
            $table->foreignId('parent_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('rate_type', 100)->nullable();
            $table->string('bill_number')->nullable();
            $table->integer('pallet_count')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('order_code');
            $table->index('status');
            $table->index('priority');
            $table->index(['type', 'area_id']);
            $table->index('planned_loading_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
