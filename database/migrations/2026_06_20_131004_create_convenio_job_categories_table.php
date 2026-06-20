<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenio_job_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_id')->constrained('convenios');
            $table->string('name');
            $table->string('group_code')->nullable();
            $table->decimal('annual_hours', 7, 2)->nullable();
            $table->decimal('weekly_hours', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convenio_job_categories');
    }
};
