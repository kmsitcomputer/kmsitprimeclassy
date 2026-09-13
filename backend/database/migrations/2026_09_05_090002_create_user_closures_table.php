<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ancestor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('depth');

            $table->unique(['ancestor_id', 'descendant_id']);
            $table->index('descendant_id');
            $table->index(['ancestor_id', 'depth']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_closures');
    }
};
