<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_table_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_table_id')->constrained('salary_tables')->cascadeOnDelete();
            $table->foreignId('job_category_id')->constrained('convenio_job_categories');
            $table->decimal('gross_annual', 10, 2)->nullable();
            $table->decimal('base_salary_monthly', 10, 2)->nullable();
            $table->decimal('extra_pay', 10, 2)->nullable();
            $table->integer('num_payments')->nullable();
            $table->decimal('hourly_rate', 8, 4)->nullable();
            $table->decimal('night_plus', 10, 2)->nullable();
            $table->jsonb('raw_values')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_table_rows');
    }
};
