<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email')->unique();
            $table->string('full_name');
            $table->string('employee_external_id')->nullable();
            $table->foreignId('convenio_id')->constrained('convenios');
            $table->foreignId('job_category_id')->nullable()->constrained('convenio_job_categories')->nullOnDelete();
            $table->foreignId('province_id')->constrained('provinces');
            $table->string('work_location')->nullable(); // free text (data-model §12.4)
            $table->enum('employment_type', ['full_time', 'part_time']);
            $table->date('start_date')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('profile_last_reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
