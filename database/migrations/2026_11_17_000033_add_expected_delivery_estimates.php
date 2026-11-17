<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const ADDITIONS = [
        [
            'audience' => 'customer', 'question' => 'Where can I see the expected delivery days after payment?',
            'answer' => 'Choose Lalamove at checkout to see the current store delivery estimate before paying. New delivery orders save that estimate on the original invoice in My Purchases; it starts after confirmed full payment, not from checkout submission or a payment screenshot. Older orders without a saved estimate are labeled with current store guidance instead. The estimate is not a guaranteed courier arrival time. Check the order for the latest delivery details and ask store staff about delays.',
            'keywords' => 'expected delivery days, delivery estimate, delivery time, when will my order arrive, how long delivery', 'sort_order' => 1020,
        ],
        [
            'audience' => 'owner', 'question' => 'How does the owner change the expected delivery days?',
            'answer' => "1. Open Store settings and find Expected delivery days under Store information. The default is 1-2.\n2. Enter a number such as 3 or an ascending range such as 1-2, then Save store information. Use only digits and one optional hyphen; each number can be 0 to 999.\n3. The value is a delivery estimate after confirmed full payment. It does not set a guaranteed courier arrival, extend a payment session or change the delivery fee.\n4. New online Lalamove orders keep the estimate saved when their invoice was created. Changing the setting does not replace those saved estimates. Older orders without a saved value show clearly labeled current store guidance. Pickup and completed walk-in sales keep their own handover workflow.",
            'keywords' => 'change expected delivery days, set delivery range, owner delivery estimate setting', 'sort_order' => 1021,
        ],
    ];

    public function up(): void
    {
        // Nullable keeps earlier invoices truthful: no historical estimate is invented.
        if (! Schema::hasColumn('orders', 'expected_delivery_days')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('expected_delivery_days', 7)->nullable();
            });
        }

        foreach (self::ADDITIONS as $row) {
            DB::table('chatbot_faqs')->insertOrIgnore($row + [
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve saved customer estimates and later owner FAQ edits when code is reverted.
    }
};
