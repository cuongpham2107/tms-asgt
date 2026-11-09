<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->string('name');
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('calc_method', 30)->default('manual');
            $table->decimal('amount', 14, 2)->nullable();
            $table->boolean('auto_apply')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('code');
            $table->index('is_active');
            $table->index('sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_types');
    }
};
