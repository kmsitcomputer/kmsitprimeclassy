<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `type` was a fixed MySQL ENUM (banner/hero/product_highlight/testimonial/
     * custom_html) — every new block kind would need a migration to widen it,
     * defeating "block dapat ditambah tanpa hard-code". Switched to a plain
     * string validated against App\Support\HomepageBlockTypes (a PHP array,
     * extended by editing one file, no migration).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE cms_homepage_blocks MODIFY type VARCHAR(50) NOT NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE cms_homepage_blocks MODIFY type ENUM('banner','hero','product_highlight','testimonial','custom_html') NOT NULL");
    }
};
