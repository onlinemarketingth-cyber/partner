<?php

use App\Models\ChunkedUpload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-222 — `chunked_uploads.company_id` becomes NULLABLE.
 *
 * A Super Admin has `users.company_id = NULL` (the users migration says so
 * outright: "Super Admin is the one legitimate exception to NOT NULL").
 * Every large upload they attempted therefore failed at
 * `POST /uploads/init` — first with a TypeError from
 * VideoProcessingSettingService::forCompany(), and, once that was fixed,
 * with a NOT NULL violation on this column. Reported from production,
 * 2026-08-20, on a 198 MB video.
 *
 * NULL MEANS SOMETHING HERE: "staged by a platform operator, not yet bound
 * to a company". A chunked upload is a temporary pile of bytes with no
 * business meaning; the company binding happens later, at the create
 * endpoint the token is handed to (ResolveChunkedUpload -> the endpoint's
 * own Form Request and Policy), which validates it properly.
 *
 * BR-6 IS NOT WEAKENED, and the reason is worth stating because it looks
 * like it should be. TenantScope narrows a Company Admin's queries with
 * `where company_id = :own`, which EXCLUDES a NULL row — so a tenant
 * cannot see, append to, or consume a platform operator's staging file.
 * The only actor who can is a Super Admin, who is already exempt from the
 * scope, and even then only while holding the 64-character random token
 * this controller generated and never accepts from a client.
 *
 * The alternative — requiring a Super Admin to name a company at
 * /uploads/init — was rejected for now: the frontend's chunked transport
 * lives in api/client.ts and is shared by six call sites, several of which
 * legitimately have no company in hand (a lesson upload derives its
 * company from the module in the URL). Adding a parameter none of them can
 * supply would have meant six changes to work around one null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chunked_uploads', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });
    }

    /**
     * Safe to reverse: these rows are TEMPORARY by construction — they are
     * pruned by `uploads:prune` and deleted the moment their token is
     * consumed. Any that would block the NOT NULL are abandoned sessions,
     * so removing them is the same cleanup that command performs.
     */
    public function down(): void
    {
        ChunkedUpload::withoutGlobalScopes()->whereNull('company_id')->delete();

        Schema::table('chunked_uploads', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }
};
