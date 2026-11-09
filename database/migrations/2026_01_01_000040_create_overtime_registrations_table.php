<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('shift_type');
            $table->date('overtime_date');
            $table->string('status')->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'overtime_date', 'shift_type']);
            $table->index('overtime_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_registrations');
    }
};
