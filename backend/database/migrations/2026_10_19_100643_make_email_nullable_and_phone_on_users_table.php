
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Pastikan tidak ada email NULL sebelum rollback.
        DB::table('users')
            ->whereNull('email')
            ->update(['email' => '']);

        // Pastikan tidak ada phone NULL sebelum rollback.
        // Nomor telepon kosong harus diperbaiki sebelum rollback.
        if (DB::table('users')->whereNull('phone')->exists()) {
            throw new RuntimeException(
                'Tidak dapat rollback: masih ada pengguna dengan phone NULL.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('phone', 20)->nullable()->change();
        });
    }
};
