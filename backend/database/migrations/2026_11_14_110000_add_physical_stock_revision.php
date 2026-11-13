<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB DDL auto-commits: existence guards support retry after a
        // privilege/binlog failure. Missing triggers must never be ignored.
        if (! Schema::hasTable('warehouse_stock_revisions')) {
            Schema::create('warehouse_stock_revisions', function (Blueprint $t) {
                $t->string('target_key', 160)->primary();
                $t->unsignedBigInteger('revision')->default(0);
            });
        }
        if (! Schema::hasColumn('stock_opname_items', 'system_stock_revision')) {
            Schema::table('stock_opname_items', function (Blueprint $t) {
                $t->unsignedBigInteger('system_stock_id')->nullable();
                $t->unsignedBigInteger('system_stock_revision')->nullable();
            });
        }
        // A durable target counter (including tombstones) also covers
        // absent -> inserted -> deleted ABA without materialising a zero
        // warehouse row, which would change the legacy sellable fallback.
        $key = fn (string $r) => "CONCAT_WS(':', $r.agent_id, $r.stock_type, COALESCE($r.sub_location_id, 0), IF($r.product_variation_id IS NULL, 'p', 'v'), COALESCE($r.product_variation_id, $r.product_id, 0))";
        $bump = fn (string $r) => 'INSERT INTO warehouse_stock_revisions (target_key, revision) VALUES ('.$key($r).', 1) ON DUPLICATE KEY UPDATE revision = revision + 1;';
        $definitions = [
            'insert' => 'AFTER INSERT ON warehouse_stocks FOR EACH ROW BEGIN '.$bump('NEW').' END',
            'delete' => 'AFTER DELETE ON warehouse_stocks FOR EACH ROW BEGIN '.$bump('OLD').' END',
            'update' => 'AFTER UPDATE ON warehouse_stocks FOR EACH ROW BEGIN IF NOT ('.$key('OLD').' <=> '.$key('NEW').') THEN '.$bump('OLD').$bump('NEW').' ELSEIF NEW.quantity <> OLD.quantity THEN '.$bump('NEW').' END IF; END',
        ];
        foreach ($definitions as $suffix => $definition) {
            $name = 'warehouse_stocks_revision_'.$suffix;
            if (! DB::selectOne('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$name])) {
                DB::unprepared('CREATE TRIGGER '.$name.' '.$definition);
            }
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update', 'delete'] as $suffix) {
            DB::unprepared('DROP TRIGGER IF EXISTS warehouse_stocks_revision_'.$suffix);
        }
        Schema::table('stock_opname_items', fn (Blueprint $t) => $t->dropColumn(['system_stock_id', 'system_stock_revision']));
        Schema::dropIfExists('warehouse_stock_revisions');
    }
};
