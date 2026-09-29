<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-01: explicit 1:1 ownership between a Sales-Kurir-Sub user and a Sub Location.
 *
 * Additive and nullable on purpose — EXISTING production Sub Locations keep owner_user_id = NULL
 * and are never mapped to a user automatically. The UNIQUE index is the hard guarantee that
 * a user owns at most one Sub Location and a Sub Location has at most one owner (MySQL allows
 * many NULLs, so unowned legacy rows are unaffected). Same-Agent / role rules cannot be
 * expressed as a plain FK and are enforced server-side in SubLocationOwnershipService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_sub_locations', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('agent_id')->constrained('users')->restrictOnDelete();
            $table->unique('owner_user_id', 'warehouse_sub_locations_owner_unique');
            // Audit only: who owned the location before it was deactivated (ownership is released on deactivate).
            $table->foreignId('previous_owner_user_id')->nullable()->after('owner_user_id')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_sub_locations', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropUnique('warehouse_sub_locations_owner_unique');
            $table->dropForeign(['previous_owner_user_id']);
            $table->dropColumn(['owner_user_id', 'previous_owner_user_id']);
        });
    }
};
