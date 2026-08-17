<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TASK-056 Sprint P2 bugfix — ClientCategoryService::ensureDefaults() was
// checking "does this company currently have any category row" to decide
// whether to self-heal the starter set. That means an admin who
// deliberately deleted every category (wanting zero) got them silently
// re-seeded on their next visit — count()==0 can mean either "brand new"
// or "emptied on purpose", and the two must not be treated the same. This
// column is a one-way "have we ever seeded this company" marker, set once
// and never cleared, so a genuine delete-all is respected going forward.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('client_categories_seeded_at')->nullable()->after('commission_plan_type');
        });

        // Backfill: any company that already has at least one category row
        // (seeded under the old "count == 0" check, or created manually)
        // must be marked seeded now, otherwise ensureDefaults() would treat
        // it as brand-new on its next index() call and insert a duplicate
        // starter set alongside the admin's real data.
        DB::table('companies')
            ->whereIn('id', DB::table('client_categories')->select('company_id')->distinct())
            ->update(['client_categories_seeded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('client_categories_seeded_at');
        });
    }
};
