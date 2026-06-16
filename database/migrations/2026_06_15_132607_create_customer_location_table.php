<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('loc_type', 30)->nullable()->comment('Ghi đè loại địa điểm cho cặp customer-location');
            $table->timestamps();

            $table->unique(['customer_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_location');
    }
};
