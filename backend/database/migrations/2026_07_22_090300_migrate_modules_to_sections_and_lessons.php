<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-009 — the cutover. Uses raw DB::table() queries throughout (not
// Eloquent models), per this project's own migration convention: a
// migration must keep working against the schema as it existed at the
// moment it ran, independent of how the app's models change later.
//
// module_completions is retargeted from module_id to module_lesson_id
// via a full table rebuild (create shadow table with the final shape,
// copy every row across with the id preserved, drop the original,
// rename the shadow into place) rather than Schema::table()->change()
// or dropColumn()/dropForeign() — doctrine/dbal is not installed in
// this project (see 2026_07_20_090000's comment), and this exact class
// of ALTER (retarget a FK + swap a unique constraint) has already burned
// this project once on SQLite (see
// 2026_07_14_130000_relax_referral_constraints_on_commission_ledger_table's
// comment trail). A uniform create+copy+rename rebuild works identically
// on MySQL and SQLite, so no driver branching is needed here.
//
// Lossy in one direction: down() cannot losslessly un-merge a Section
// that grew extra lessons after this ran — acceptable for an internal
// one-way schema evolution (same trade-off already made elsewhere in
// this project's migration history).
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1) Wrap every existing Module row into a single ModuleLesson
        // carrying its current content (content columns are still on
        // `modules` at this point — the next migration drops them).
        $modules = DB::table('modules')->select([
            'id', 'company_id', 'title', 'content_type', 'source_type',
            'content_ref', 'processing_status', 'xp_reward', 'is_published',
        ])->get();

        $lessonIdByModuleId = [];

        foreach ($modules as $module) {
            $lessonIdByModuleId[$module->id] = DB::table('module_lessons')->insertGetId([
                'company_id' => $module->company_id,
                'module_id' => $module->id,
                'title' => $module->title,
                'content_type' => $module->content_type,
                'source_type' => $module->source_type,
                'content_ref' => $module->content_ref,
                'processing_status' => $module->processing_status,
                'sort_order' => 0,
                'xp_reward' => $module->xp_reward,
                'is_published' => $module->is_published,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2) Rebuild module_completions targeting module_lesson_id
        // instead of module_id (see class docblock for why a rebuild,
        // not an in-place ALTER).
        Schema::disableForeignKeyConstraints();

        Schema::create('module_completions_rebuild_tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('module_lesson_id')->constrained('module_lessons')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->unsignedInteger('score')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        foreach (DB::table('module_completions')->get() as $completion) {
            if (! isset($lessonIdByModuleId[$completion->module_id])) {
                continue; // orphaned row (module already gone) — nothing to carry forward.
            }
            DB::table('module_completions_rebuild_tmp')->insert([
                'id' => $completion->id,
                'company_id' => $completion->company_id,
                'user_id' => $completion->user_id,
                'module_lesson_id' => $lessonIdByModuleId[$completion->module_id],
                'completed_at' => $completion->completed_at,
                'score' => $completion->score,
                'created_at' => $completion->created_at,
            ]);
        }

        Schema::drop('module_completions');
        Schema::rename('module_completions_rebuild_tmp', 'module_completions');

        Schema::table('module_completions', function (Blueprint $table) {
            $table->unique(['user_id', 'module_lesson_id']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('module_completions_rebuild_tmp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('module_id')->constrained('modules')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->unsignedInteger('score')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        foreach (DB::table('module_completions')->get() as $completion) {
            $lesson = DB::table('module_lessons')->where('id', $completion->module_lesson_id)->first();
            if (! $lesson) {
                continue;
            }
            DB::table('module_completions_rebuild_tmp')->insert([
                'id' => $completion->id,
                'company_id' => $completion->company_id,
                'user_id' => $completion->user_id,
                'module_id' => $lesson->module_id,
                'completed_at' => $completion->completed_at,
                'score' => $completion->score,
                'created_at' => $completion->created_at,
            ]);
        }

        Schema::drop('module_completions');
        Schema::rename('module_completions_rebuild_tmp', 'module_completions');

        Schema::table('module_completions', function (Blueprint $table) {
            $table->unique(['user_id', 'module_id']);
        });

        Schema::enableForeignKeyConstraints();

        DB::table('module_lessons')->delete();
    }
};
