<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Retain the wording and audit history, but stop publishing obsolete offers.
            DB::table('chatbot_faqs')->whereIn('question', [
                'Can I pay an order in installments?',
                'What is the deadline for a partial-payment invoice?',
                'Can I place another partial-payment order?',
                'How do I choose partial payment and pay the balance later?',
                'What payment options are available for walk-in customers?',
                'How do staff create a walk-in sale?',
            ])->where('active', true)->update(['active' => false, 'updated_at' => now()]);

            $this->refreshDefault(
                'How do I pay the remaining invoice balance?',
                'Sign in, open My orders, and open the original invoice. Use its available payment action to pay an amount against the remaining balance through PayMongo when online payments are enabled. Do not create a new order to settle an old invoice. If a payment is still pending or uncertain, check its status before starting another payment. Walk-in cash payments must be recorded as received by store staff.',
                'Sign in, open My Purchases and choose the original invoice. New orders use full payment only. If an older invoice has money already received, its history is kept and its entire remaining balance must be settled on that same invoice. Check any pending or uncertain payment before starting another. Do not create another order to pay an old balance. Staff record walk-in cash only after receiving it in full.'
            );
            $this->refreshDefault(
                'How does the owner review store settings and service readiness?',
                "1. Open Settings and enter confirmed opening hours, pickup details, and support contacts. Leave unconfirmed facts blank, then save.\n2. Review the payment, delivery, assistant, and email status cards. Ask the website maintainer to configure unavailable services on the server.\n3. Review email verification, staff setup, password recovery, and payment reminder readiness. A configured email connection still needs a real inbox test; do not assume delivery.\n4. If changing default partial-payment terms, choose 7–30 days and record a reason. The new default applies to future invoices; an existing invoice needs its own recorded agreement.",
                "1. Open Store settings and enter confirmed opening hours, pickup details and support contacts, then save.\n2. Review the payment, delivery, assistant and email status cards. Ask the maintainer to configure any unavailable connection privately.\n3. Test verification, recovery and important order emails with an inbox you control. A configured connection does not guarantee email delivery.\n4. New orders require full payment. Keep test mode for demonstrations and review the approved FAQ library when a store process changes. Never put secret keys in a FAQ."
            );

            $rows = [
                ['customer', 'Which payment options can I use now?',
                    "1. New online orders require the full clothing total plus any confirmed delivery fee through PayMongo. Choose an available payment method on its secure checkout.\n2. Partial payments, installments and deposits are no longer offered. Returning from checkout or uploading a screenshot does not confirm payment.\n3. If a payment is pending, open the same invoice and check it before trying again. Existing invoices keep all received payments; an older remaining balance must be paid in full.\n4. At the store, staff can record full cash received or create a full online payment. Only provider-verified online payments or recorded cash receipts count as received.",
                    'full payment only, available payment options, payment methods, pay entire invoice'],
                ['customer', 'Why must I agree to the Terms and Conditions when creating an account?',
                    "1. Read Terms and Conditions before creating a customer account, including when Google creates a new account.\n2. The terms explain account information, full payment, delivery details and the store return/refund review process. They link to the applicable PayMongo and Lalamove terms.\n3. Agree only after reading them. For manual signup, enter your full name, unique username and email, then verify your own inbox when requested.\n4. An email format alone does not prove ownership. Enter codes only on the verification page and keep passwords, recovery links and codes out of chat.",
                    'agree terms, terms and conditions, signup policy, registration consent, legitimate email'],
                ['customer', 'How do I save an address and choose my delivery pin?',
                    "1. Open My Account → My addresses and choose Add address. Enter the recipient, an 11-digit mobile number beginning with 09, and the complete street, city, province, postal code and country.\n2. Tap the map or drag the pin to the delivery entrance. Confirm that the pin matches the written address, then save. You can choose a default address.\n3. At checkout, choose a saved address or enter another. For Lalamove, confirm the pin and request the delivery fee before paying. Required inputs have an asterisk (*).\n4. GPS permission is optional: Use my current location is a shortcut. You can select the pin on the map instead. Changing the address or pin requires a fresh fee quote; saved-address edits do not change placed orders.",
                    'saved address, address book, default address, delivery pin, location pin, GPS required, eleven digit phone'],
                ['customer', 'Will my cart stay saved after I sign out or close the browser?',
                    "1. Add the correct product, color, size and quantity to Cart. Signed-in customers have a saved cart linked to their account.\n2. Sign in to the same account to restore it after signing out or closing the browser. A guest cart depends on that browser retaining its store cookie.\n3. When a guest cart and saved cart contain the same variant, the larger quantity is kept so signing in does not add it twice. Review Cart before checkout.\n4. Saving a cart does not reserve stock or lock prices. Purchased checkout quantities are removed when the invoice is created, even if payment still needs checking. The invoice remains in My Purchases.",
                    'cart saved, persistent cart, exit browser, cart logout, recover cart, cart after sign out'],
                ['customer', 'How do I read My Purchases and the To Ship status?',
                    "1. Open My Purchases to search your order number or product and use the status tabs. Each row shows its original invoice and total.\n2. To Pay needs confirmed payment. Packing means staff are preparing the fully paid order. Ready for delivery means it is packed.\n3. To Ship means a courier booking is active. Open the order for the latest Lalamove details; this label alone does not mean a rider is already at your door.\n4. After verified courier completion or ready pickup, receive and check the clothing, select a star rating and confirm receipt. Written comments are optional. The customer confirmation changes the online order to Delivered.",
                    'my purchases help, purchase tabs, to ship meaning, compact order history'],
                ['customer', 'Are the product pictures actual supplier photographs?',
                    'Images labeled Demo preview are generated clothing examples for the selected style and color. They are not supplier photographs or a guarantee of exact fabric, cut or color. Read the actual listed size/color and measurements, and ask staff about the physical clothing. Store-uploaded product photographs may be shown separately. Product availability and prices come from the selected variant, not the picture.',
                    'demo preview, generated shirt photo, product picture accuracy, shade varies'],
                ['staff', 'How do staff create a full-payment walk-in sale?',
                    "1. Open Walk-in checkout. Choose a registered customer to link order history, or enter the guest details without creating an account.\n2. Select the product, actual color, size and quantity. Review the quantity price and full invoice total.\n3. Choose Cash or PayMongo. For cash, receive the full amount first, add the receipt note and tick the cash-received confirmation before saving. For online payment, wait for PayMongo verification.\n4. A fully received walk-in payment marks the sale Delivered. Do not record cash from a screenshot or create a second invoice to recover a pending payment. New partial payments are not available.",
                    'staff walk in steps, walk-in checkout, counter sale, create walkin, full cash sale'],
                ['staff', 'How do we demonstrate Lalamove booking without a real delivery?',
                    "1. Confirm the store is using Lalamove Sandbox, the approved pickup entrance and the TEST contact number. In the Philippines Partner Portal, select Sandbox; use only its virtual test credit.\n2. Use a fully paid TEST order with a complete address and confirmed map pin. Pack it, record the reason and mark Ready for delivery. Review the saved delivery fee, then choose Book Lalamove sandbox delivery once.\n3. Open the same Sandbox booking in the Partner Portal. Use its test controls to simulate rider progress, then Check delivery with Lalamove on the store order. If the first booking is uncertain, reconcile or recover it before rebooking. A higher quote needs owner approval to cover the difference.\n4. To Ship remains open until the customer confirms receipt with a star rating after verified courier completion. This is a simulation only: do not request a real courier, use a real wallet or claim a physical delivery occurred.",
                    'Lalamove demonstration, sandbox booking guide, test courier, simulate delivery, Lalamove sandbox'],
                ['staff', 'What should I check first on the dashboard?',
                    "1. Check Needs attention for orders to pack, orders ready for delivery, returns to review and low-stock variants. Open a count to see the related records.\n2. Read Confirmed Clothing sale, Paid orders, Customers and Average order value above Sales over time. Use the date filters when comparing periods.\n3. The sales chart uses confirmed paid dates and excludes delivery from clothing sales. Operations delivery charts use order placement dates and current delivery status, so those counts can differ.\n4. Walk-in and online order counts compare fully paid purchases in the current month and previous three months. The Top 5 most bought items line graph shows monthly pieces for the leading products in that same four-month window and updates with paid purchases. Values are shown to two decimal places; the current month is incomplete.\n5. Refunds and paid invoices after refunds are shown separately. A forecast is a planning estimate, not a confirmed sale. Staff and owners use only the actions allowed for their signed-in role.",
                    'simple dashboard help, needs attention, dashboard widgets, understand sales chart'],
                ['owner', 'How does the owner archive or permanently delete a product or variant?',
                    "1. Open Products & inventory and choose the product or size/color variant. Archive it first to remove it from new customer selection.\n2. Review archived records before restoring or deleting them. Archived items keep their saved order history.\n3. Permanent deletion is available only for an archived record with no linked order items or other protected stock/order references. Linked transaction records remain archived to preserve invoices and reports.\n4. New adult variants use XS, S, M, L, XL, 2XL, 3XL, 4XL or 5XL. Existing kids and other legacy sizes remain supported. Enter actual colors, including White, without a separate Colored group.",
                    'archive product, delete product, delete variant, restore variant, standard adult sizes'],
            ];
            foreach ($rows as $index => [$audience, $question, $answer, $keywords]) {
                DB::table('chatbot_faqs')->insertOrIgnore(compact('audience', 'question', 'answer', 'keywords') + [
                    'active' => true, 'sort_order' => 900 + $index, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    private function refreshDefault(string $question, string $previous, string $answer): void
    {
        $faq = DB::table('chatbot_faqs')->where('question', $question)->lockForUpdate()->first();
        if ($faq && $faq->question === $question && $faq->answer === $previous) {
            DB::table('chatbot_faqs')->where('id', $faq->id)->update(['answer' => $answer]);
        }
    }

    public function down(): void
    {
        // Do not restore obsolete offers or remove later owner edits.
    }
};
