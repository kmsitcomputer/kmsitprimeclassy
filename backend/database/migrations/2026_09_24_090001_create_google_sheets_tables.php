<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A spreadsheet is registered to exactly one scope, even across tabs.
        Schema::create('sheets_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('spreadsheet_id', 150)->unique();
            $table->foreignId('agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('sheets_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained('sheets_destinations')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('tab', 100);
            $table->string('dataset', 50);
            $table->json('columns');
            $table->json('filters')->nullable();
            $table->timestamps();
            $table->unique(['destination_id', 'tab']);
        });
        Schema::create('sheets_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->nullable()->constrained('sheets_configs')->nullOnDelete();
            $table->string('dataset', 50);
            $table->string('spreadsheet_id', 150);
            $table->string('tab', 100);
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_role', 30);
            $table->unsignedBigInteger('agent_scope')->nullable()->index();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('rows_success')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->string('error_summary')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheets_sync_logs');
        Schema::dropIfExists('sheets_configs');
        Schema::dropIfExists('sheets_destinations');
    }
};
