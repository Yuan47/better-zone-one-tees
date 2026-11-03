<?php

use App\Services\DannonNecklineSplit;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(DannonNecklineSplit::class)->apply();
    }

    public function down(): void
    {
        // Retain product identities, order links, owner updates and any later V-Neck sales.
    }
};
