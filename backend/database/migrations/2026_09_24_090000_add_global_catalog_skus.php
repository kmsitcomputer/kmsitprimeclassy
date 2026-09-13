<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 60)->nullable()->unique();
        });
        Schema::create('catalog_skus', function (Blueprint $table) {
            $table->string('sku', 60)->primary();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->unique(['owner_type', 'owner_id']);
        });
        DB::table('product_variations')->select(['id', 'sku'])->orderBy('id')->chunkById(500, function ($rows) {
            DB::table('catalog_skus')->insert($rows->map(fn ($row) => [
                'sku' => $row->sku, 'owner_type' => 'variant', 'owner_id' => $row->id,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_skus');
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropColumn('sku');
        });
    }
};
