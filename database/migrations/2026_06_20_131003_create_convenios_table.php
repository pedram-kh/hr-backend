<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenios', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->string('name');
            $table->foreignId('province_id')->constrained('provinces');
            $table->foreignId('sector_id')->constrained('sectors');
            $table->decimal('annual_hours', 7, 2)->nullable();
            $table->decimal('weekly_hours', 5, 2)->nullable();
            $table->string('numero_a3')->nullable();
            $table->string('it_complement')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convenios');
    }
};
