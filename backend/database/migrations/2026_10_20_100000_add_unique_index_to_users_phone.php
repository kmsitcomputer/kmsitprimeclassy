<?php

use App\Support\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')
            ->select('id', 'phone')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get();

        $seen = [];
        $updates = [];

        foreach ($rows as $row) {
            $normalized = PhoneNormalizer::normalize($row->phone);

            if ($normalized !== null) {
                if (isset($seen[$normalized])) {
                    throw new RuntimeException(
                        "Duplicate normalized phone for users {$seen[$normalized]} and {$row->id}"
                    );
                }

                $seen[$normalized] = $row->id;
            }

            if ($normalized !== $row->phone) {
                $updates[$row->id] = $normalized;
            }
        }

        foreach ($updates as $id => $phone) {
            DB::table('users')->where('id', $id)->update(['phone' => $phone]);
        }

        Schema::table('users', function ($table) {
            $table->unique('phone', 'users_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique('users_phone_unique');
        });
    }
};
