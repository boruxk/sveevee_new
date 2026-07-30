<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('image_path');
            $table->decimal('price', 10, 2);
            $table->string('link', 2048);
            $table->timestamps();

            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_products');
    }
};
