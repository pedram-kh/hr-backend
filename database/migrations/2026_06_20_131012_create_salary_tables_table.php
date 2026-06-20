<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_id')->constrained('convenios');
            $table->integer('year')->nullable();
            $table->date('validity_start')->nullable();
            $table->date('validity_end')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_tables');
    }
};
