<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
            $table->boolean('archive_was_active')->nullable();
        });
        Schema::table('variants', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('variants', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['archived_at', 'archive_was_active']));
    }
};
