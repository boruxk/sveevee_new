<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->string('image_path');
            $table->date('event_date');
            $table->string('event_time', 5);
            $table->string('address');
            $table->timestamps();

            $table->index(['page_id', 'event_date', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_events');
    }
};
