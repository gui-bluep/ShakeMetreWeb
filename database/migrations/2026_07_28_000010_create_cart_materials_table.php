<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FileMaker source: JCARTMAT_JoinCartsMaterials (docs/filemaker-reference/ShakeMetre_data_dictionary.json)
     *
     * This is not a plain pivot: most columns below are a denormalized snapshot of the
     * linked Material's attributes at the time it was added to the cart (per the migration
     * plan doc, section 6.1). They are kept as real columns, not derived from `materials`.
     */
    public function up(): void
    {
        Schema::create('cart_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('cart_id')->constrained('carts');
            $table->foreignUuid('material_id')->constrained('materials');
            $table->foreignUuid('category_id')->constrained('categories');

            $table->integer('number')->nullable();
            $table->string('category_label')->nullable();
            $table->string('title')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('color')->nullable();
            $table->string('finish')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->text('comment')->nullable();
            $table->text('note_internal')->nullable();
            $table->text('product_url')->nullable();
            $table->string('size_l')->nullable();
            $table->string('size_w')->nullable();
            $table->string('size_h')->nullable();
            $table->string('size_diam')->nullable();
            $table->decimal('price_buy', 15, 4)->nullable();
            $table->decimal('price_buy_from', 15, 4)->nullable();
            $table->decimal('price_buy_to', 15, 4)->nullable();
            $table->decimal('price_public', 15, 4)->nullable();
            $table->decimal('price_public_from', 15, 4)->nullable();
            $table->decimal('price_public_to', 15, 4)->nullable();
            $table->string('image1')->nullable();
            $table->string('image2')->nullable();
            $table->string('image3')->nullable();
            $table->string('image1_thumbnail70x70')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_materials');
    }
};
