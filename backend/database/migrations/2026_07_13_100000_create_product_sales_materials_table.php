<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Human-requested: Client Management should show each client's
// interested product(s) (via the existing Referral relationship — see
// ClientResource change in the same task) plus let agents download that
// product's "sales material" (brochure/PDF). This table is the new
// piece: Product had zero attachment infrastructure before this.
//
// Mirrors ClientDocument's shape exactly (company_id, uploaded_by,
// file_path, original_filename, mime_type, size_bytes — see that
// migration/model) but files live on the PRIVATE disk, not public
// (human explicitly chose this — see task discussion): sales materials
// can be company-confidential, so access is checked the same way as
// PDPA client documents (Section 5 rule 6 pattern), just with a wider
// "who can view" circle (any same-company user, not only the referring
// agent — see ProductSalesMaterialController's reuse of ProductPolicy).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales_materials');
    }
};
