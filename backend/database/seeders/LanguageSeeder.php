<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    /**
     * The mandatory base languages (minimum 4 per the multi-language
     * requirement) — Indonesian is always is_default and must never be
     * removed. Adding a 5th+ language later is just another admin-configurable
     * row (see `languages` table + lang/{code}/*.php), not a migration.
     */
    public function run(): void
    {
        $languages = [
            ['code' => 'id', 'name' => 'Bahasa Indonesia', 'is_default' => true, 'sort_order' => 1],
            ['code' => 'en', 'name' => 'English', 'is_default' => false, 'sort_order' => 2],
            ['code' => 'zh', 'name' => '中文', 'is_default' => false, 'sort_order' => 3],
            ['code' => 'ar', 'name' => 'العربية', 'is_default' => false, 'sort_order' => 4],
        ];

        foreach ($languages as $language) {
            DB::table('languages')->updateOrInsert(
                ['code' => $language['code']],
                [
                    'name' => $language['name'],
                    'is_default' => $language['is_default'],
                    'is_active' => true,
                    'sort_order' => $language['sort_order'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
