<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: MAT_Materials (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * Binary image fields (Image1..4, Image1_Thumbnail70x70) are mapped to nullable string
     * columns holding a storage path/disk key, not raw blobs - actual files belong on a
     * Laravel filesystem disk, not in this table.
     *
     * zkf_SUP___TBDEL? was dropped: marked "to be deleted" in the source schema and never
     * pointed at a real table.
     */
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable for the same reason as on metre_lines: FileMaker has no NOT NULL and
            // a catalogue entry need not be classified against every axis.
            $table->foreignUuid('category_id')->nullable()->constrained('categories');
            $table->foreignUuid('sub_category_id')->nullable()->constrained('sub_categories');
            $table->foreignUuid('reference_id')->nullable()->constrained('metre_references');
            $table->foreignUuid('sub_reference_id')->nullable()->constrained('sub_references');
            $table->foreignUuid('sub_reference_line_id')->nullable()->constrained('sub_reference_lines');
            $table->foreignUuid('parent_id')->nullable()->constrained('materials');

            // Cross-system: points to CTC_Contacts in ShakeDesign, no local constraint.
            $table->uuid('supplier_contact_id')->nullable();

            $table->string('title_en')->nullable();
            $table->string('title_fr')->nullable();
            $table->string('title_nl')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_nl')->nullable();
            $table->string('model')->nullable();
            $table->string('brand')->nullable();
            $table->string('color')->nullable();
            $table->string('finish')->nullable();
            $table->string('size_l')->nullable();
            $table->string('size_w')->nullable();
            $table->string('size_h')->nullable();
            $table->string('size_diam')->nullable();
            $table->text('product_url')->nullable();
            $table->string('delivery_time')->nullable();
            $table->text('note_internal')->nullable();
            $table->decimal('price_buy_from', 15, 4)->nullable();
            $table->decimal('price_buy_to', 15, 4)->nullable();
            $table->decimal('price_public_from', 15, 4)->nullable();
            $table->decimal('price_public_to', 15, 4)->nullable();
            $table->decimal('discount', 15, 4)->nullable();
            $table->boolean('is_parent_b')->default(false);
            $table->boolean('is_child_b')->default(false);
            $table->boolean('is_master_b')->default(false);
            $table->string('image1')->nullable();
            $table->string('image2')->nullable();
            $table->string('image3')->nullable();
            $table->string('image4')->nullable();
            $table->string('image1_thumbnail70x70')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
