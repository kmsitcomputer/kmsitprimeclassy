<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->after('id')->constrained('roles')->restrictOnDelete();
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('referral_code', 20)->nullable()->unique()->after('phone');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('referral_code');
            $table->softDeletes();

            $table->foreignId('parent_id')->nullable()->after('role_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->after('parent_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('korsal_id')->nullable()->after('agent_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('sales_id')->nullable()->after('korsal_id')
                ->constrained('users')->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_id');
            $table->dropConstrainedForeignId('korsal_id');
            $table->dropConstrainedForeignId('agent_id');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropSoftDeletes();
            $table->dropColumn(['phone', 'referral_code', 'status']);
        });
    }
};
