<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('image_path')->nullable();
            $t->json('size_chart')->nullable();
            $t->text('size_chart_note')->nullable();
        });
        Schema::table('variants', function (Blueprint $t) {
            $t->string('color_group')->default('COLORED');
            $t->string('image_path')->nullable();
            $t->unique(['product_id', 'size', 'color']);
        });
        DB::table('variants')->whereRaw('LOWER(color) = ?', ['white'])->update(['color_group' => 'WHITE']);
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $t) {
            $t->dropUnique(['product_id', 'size', 'color']);
            $t->dropColumn(['color_group', 'image_path']);
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['image_path', 'size_chart', 'size_chart_note']));
    }
};
