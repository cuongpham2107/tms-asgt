<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->enum('job_type', ['periodic_maintenance', 'repair', 'inspection']);
            $table->enum('priority', ['urgent', 'high', 'medium', 'low'])->default('medium');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('trigger_type', ['by_km', 'by_date', 'both']);
            $table->unsignedInteger('km_interval')->nullable();
            $table->decimal('km_current', 10, 1)->nullable();
            $table->decimal('km_next_trigger', 10, 1)->nullable();
            $table->unsignedInteger('km_remind_before')->nullable()->default(500);
            $table->unsignedSmallInteger('date_interval_days')->nullable();
            $table->date('last_service_date')->nullable();
            $table->date('date_next_trigger')->nullable();
            $table->unsignedSmallInteger('date_remind_before_days')->nullable()->default(14);
            $table->unsignedBigInteger('estimated_cost')->nullable();
            $table->string('garage')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('auto_create_job')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('alert_status', ['ok', 'warning', 'due', 'overdue'])->default('ok');
            $table->timestamp('last_triggered_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['vehicle_id', 'is_active']);
            $table->index('alert_status');
        });

        Schema::create('vehicle_maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->enum('job_type', [
                'periodic_maintenance', 'repair', 'inspection', 'registration', 'insurance',
            ]);
            $table->enum('priority', ['urgent', 'high', 'medium', 'low'])->default('medium');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('planned_date');
            $table->unsignedSmallInteger('remind_before_days')->default(3);
            $table->unsignedBigInteger('estimated_cost')->nullable();
            $table->unsignedBigInteger('actual_cost')->nullable();
            $table->string('garage')->nullable();
            $table->string('technician')->nullable();
            $table->decimal('km_at_service', 10, 1)->nullable();
            $table->date('next_service_date')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled', 'overdue'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('schedule_id')->nullable()->constrained('vehicle_maintenance_schedules')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'status']);
            $table->index('planned_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenance_jobs');
        Schema::dropIfExists('vehicle_maintenance_schedules');
    }
};
