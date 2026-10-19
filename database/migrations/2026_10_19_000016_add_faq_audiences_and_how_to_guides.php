<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chatbot_faqs', 'audience')) {
            Schema::table('chatbot_faqs', function (Blueprint $table) {
                $table->string('audience', 20)->default('customer')->index();
            });
        }

        // This known legacy guide describes an owner-only action. Keep the
        // owner's wording, matching words, display order and active switch.
        DB::table('chatbot_faqs')->where('question', 'How is a staff account created?')->update(['audience' => 'owner']);

        $rows = [
            ['customer', 'How do I create a customer account?',
                "1. Open Sign in, then choose Create an account.\n2. Enter your name, email, and a password of at least 10 characters; enter the same password again.\n3. Choose Create account. If email verification is required, enter the latest code on the verification page.\n4. Keep your password and personal verification code private. The assistant never needs them.",
                'create account, register, sign up, new customer account'],
            ['customer', 'How do I sign in to my customer account?',
                "1. Open Sign in.\n2. Enter the email and password you used for your account, then choose Sign in.\n3. If asked, verify your own email on the verification page. Continue with Google is an option only when that button is shown.\n4. If your password does not work, choose Forgot your password? Never send your password or verification code in chat.",
                'sign in, login, log in, access customer account'],
            ['customer', 'How do I enter my email verification code?',
                "1. Sign in and open Verify email when asked.\n2. Check your account email, including spam, for the latest six-digit code.\n3. Enter it only on the email verification page within 10 minutes. A newly sent code replaces the old one.\n4. If needed, use Resend after the wait shown on that page. Never paste your personal code into chat or share it with anyone.",
                'verify email steps, email otp help, enter verification code, resend code'],
            ['customer', 'How do I reset a forgotten password?',
                "1. Open Sign in and choose Forgot your password?\n2. Enter your account email and request the reset link. Recovery must be enabled by the store.\n3. Check inbox and spam; open the one-use link within 60 minutes.\n4. Enter and confirm a new password of at least 10 characters, then sign in. If recovery is unavailable, contact the store. Never share the link or your password in chat.",
                'forgotten password steps, forgot password help, reset password steps, recovery link'],
            ['customer', 'How do I update my profile and delivery address?',
                "1. Sign in and open My Account.\n2. Update your name, username, or profile photo as needed.\n3. For delivery, enter your mobile number and complete street address, city, province, postal code, and country.\n4. Choose Save account, then review the details again at checkout. Changing your saved address does not change an order already placed.",
                'edit profile, update address, change address, my account, mobile number'],
            ['customer', 'How do I save or remove a favorite product?',
                "1. Sign in and browse the products.\n2. Use the heart control on a product to save it.\n3. Open Favorites / wishlist from My dashboard to see saved products.\n4. Use the heart again to remove a saved product. Saving a favorite does not reserve stock.",
                'save favorite, remove favorite, heart, wishlist help, save product'],
            ['customer', 'How do I add items to my cart and check out?',
                "1. Open a product and choose its available size, color, and quantity.\n2. Add it to your cart; open Cart to review or update quantities.\n3. Sign in, continue to checkout, and check your contact and pickup or delivery details.\n4. Review the total and available payment choice before placing the order. Stock and prices are checked again when the order is saved.",
                'cart help, add to cart, checkout steps, change cart quantity'],
            ['customer', 'How do I open my order history and track an order?',
                "1. Sign in to the account used for the order.\n2. Open My dashboard, then My orders.\n3. Open the order with the matching ZOT order number to see its invoice, payments, and current status.\n4. Use Refresh order status or the delivery tracking link when shown. Your account only opens your own orders.",
                'view order history, track order steps, my orders, previous orders'],
            ['customer', 'How do I find my invoice and payment references?',
                "1. Sign in, open My orders, and choose the order.\n2. Use its ZOT order number as the original invoice reference.\n3. In Invoice payments, check each payment reference, amount, method, and received date. A confirmed online payment also shows its PayMongo transaction reference.\n4. Treat only Payment received entries as receipts. Pending attempts, screenshots, and returning from checkout do not confirm payment.",
                'find payment reference, invoice reference steps, transaction reference, payment receipt steps'],
            ['customer', 'How do I choose partial payment and pay the balance later?',
                "1. At checkout, choose partial payment if offered. It requires a discounted clothing subtotal above ₱1,000, excluding delivery; only one open partial-payment invoice is allowed.\n2. Review the original invoice and its 7–30 day balance deadline.\n3. Later, open that same order in My orders and use its available payment action to pay the balance or another eligible installment.\n4. Check a pending payment before trying again. Received payments reduce the same balance; they do not extend the deadline. Packing waits for full payment.",
                'partial payment steps, installment how to, pay balance later, down payment steps'],
            ['customer', 'How do I confirm delivery and leave my review?',
                "1. Receive and check your clothing.\n2. Sign in and open the order in My orders.\n3. Once courier completion is verified or pickup is ready, choose Confirm Delivered & submit review.\n4. Submit the required rating and review. This closes an online order as Delivered; a courier update alone does not. Fully paid walk-in sales follow their separate handover rule.",
                'confirm delivery steps, confirm received, leave review, received my clothes'],
            ['customer', 'How do I ask for a refund or return?',
                "1. Open your fully paid, Delivered order in My orders. For an online order, first confirm receipt and submit the required review.\n2. Read the return policy and choose Request a refund or return.\n3. Enter your reason, optionally add a supporting photo, and send the request for review.\n4. Follow the store instructions for returning the clothing and check the decision on your order. A request or approval does not mean money has arrived; the owner reviews eligibility and confirms the refund separately.",
                'refund steps, return steps, request refund help, return my clothes'],
            ['staff', 'How do staff create a walk-in sale?',
                "1. Open Walk-in checkout in the workspace. Choose an existing customer or enter the walk-in customer details.\n2. Add the correct products, sizes, colors, and quantities; review prices and stock.\n3. Choose full cash, available online payment, or an eligible partial payment. Partial clothing totals must be above ₱1,000; check the original due date.\n4. For cash, receive the money before confirming it and entering the receipt note. Save the checkout, then open the saved invoice. Full payment completes a walk-in handover; a partial invoice stays open.",
                'staff walk in steps, walk-in checkout, counter sale, create walkin'],
            ['staff', 'How do staff record cash received on an invoice?',
                "1. Open the correct invoice and check its remaining balance.\n2. Resolve any pending or uncertain online attempt before taking another payment.\n3. After receiving the cash, enter the actual amount under Record cash received, add the receipt reason, and tick the confirmation.\n4. Save once and check the received payment reference and remaining balance. Do not create a new invoice for a later payment; the original deadline stays in place.",
                'record cash received, staff cash payment, cash receipt, counter payment steps'],
            ['staff', 'How do staff pack an order and mark it ready?',
                "1. Open Orders and select the invoice. Confirm it is fully paid.\n2. Check every size, color, and quantity, then pack the clothing.\n3. If fulfillment is on hold for a refund, ask the owner to review it before proceeding.\n4. When the action is available, enter a clear reason and choose Mark Ready for delivery. Do not mark ready before the packing check is complete.",
                'packing steps, mark ready, staff pack order, ready for delivery'],
            ['staff', 'How do staff book and check a courier delivery?',
                "1. Open the fully paid, packed order and mark it Ready for delivery.\n2. For Lalamove orders, use Book Lalamove delivery when the button is available. The delivery connection and quotation must be valid.\n3. Use Check delivery with Lalamove to read the existing booking status.\n4. If the booking result is uncertain, check the original booking and use its verified delivery ID to recover it. Never book a second courier until the original outcome is known.",
                'book courier steps, staff lalamove, courier delivery, check delivery'],
            ['staff', 'How do staff help a customer confirm an online order was received?',
                "1. Open the order and check that courier completion is verified or pickup is ready.\n2. Ask the customer to sign in to their own account and open that order in My orders.\n3. The customer chooses Confirm Delivered & submit review and submits a rating and review after receiving the clothing.\n4. Check the updated order status. Staff cannot submit the customer receipt or review for them; a courier completion update alone does not close an online order.",
                'staff customer receipt, online delivered help, customer confirmation, receipt and review'],
            ['staff', 'How do staff handle a pending or uncertain payment?',
                "1. Open the original invoice and read Invoice payments.\n2. If Check payment with PayMongo is available, use it to verify the existing attempt. A screenshot or checkout return is not confirmation.\n3. Avoid another cash or online payment while the original attempt is unresolved.\n4. If an uncertain checkout has no saved checkout ID, ask the owner to recover its matching provider reference. Keep the same invoice and payment history.",
                'staff payment recovery, pending payment, uncertain checkout help, check payment'],
            ['staff', 'How do staff read and export sales reports?',
                "1. Open Reports in the workspace and choose the time period and any date range. Dates use Asia/Manila.\n2. Read confirmed clothing sales after discounts, before refunds, excluding delivery. Paid-sale timing follows the confirmed payment date.\n3. Compare the separate original invoice, confirmed refund, and net invoice figures; net invoice includes delivery.\n4. Choose Export sales for the relevant CSV. Operations uses orders placed within its selected dates, so its counts can differ from paid-date sales reports.",
                'reports how to, staff sales report, export sales, report date basis'],
            ['staff', 'How do staff understand the forecast formulas?',
                "1. Open Sales & Growth Forecast and expand the historical periods to see its inputs from up to 6 completed months of paid sales.\n2. Historical growth compares the last two completed months: growth rate = (last month - month before) / month before; estimate = last month × (1 + growth rate). It needs a positive earlier month.\n3. Regression fits a trend line, y = a + bx, using at least 3 completed months; a is the starting level and b is the estimated monthly change.\n4. Compare both estimates with available stock. Suggested reorder = max(0, rounded-up regression pieces - available stock). These are planning estimates, before refunds and returns, not guaranteed demand.",
                'forecast formula, how forecast works, regression explained, growth explained, reorder formula'],
            ['staff', 'What do forecast history and accuracy warnings mean?',
                "1. Open Sales & Growth Forecast and check the completed months shown. The current month is excluded from the inputs.\n2. Needs history means the required paid-sales history is missing; it does not mean demand is zero. Growth needs 2 months and a positive earlier baseline; regression needs 3.\n3. Expand past estimates. Each estimate uses only earlier months; at least 2 usable test months are needed to measure error.\n4. Average miss (MAE) is the average absolute forecast error. WAPE compares total absolute error with actual sales; it is unavailable when actual sales total zero. Lower error helps comparison, but cannot promise future accuracy.",
                'forecast history warning, needs history, accuracy warning, mae, wape, historical error'],
            ['owner', 'How does the owner add a product and its size and color variants?',
                "1. Open Products and choose Add product, or open an existing product to edit it.\n2. Save the product details, photo, and measurements that you have confirmed.\n3. Use Add a size & color variant for each sellable combination. Enter its size, color group, color, prices, stock, and reorder threshold; the SKU is assigned automatically.\n4. Save and review the variants. Add another color copies existing colored sizes and prices, so review each new variant stock and price before selling.",
                'owner add product, edit variants, size color variant, product setup'],
            ['owner', 'How does the owner update stock when another change conflicts?',
                "1. Open Products, select the product, and edit the relevant size and color variant.\n2. Check the physical on-hand count and any open orders before entering the new count.\n3. If the page says stock changed while it was open, review the refreshed count and the latest orders before saving again. Do not repeatedly submit an old count.\n4. Save the reviewed stock and check Inventory attention. Available stock subtracts reservations; refunds do not automatically add returned clothing to stock.",
                'owner stock conflict, update stock, stock changed, inventory adjustment'],
            ['owner', 'How does the owner activate a staff account with its setup code?',
                "1. Open Staff accounts and choose Add staff account. Enter the staff name, their own unused email, and an initial password of at least 10 characters.\n2. Save the setup; when staff setup email is enabled and configured, the staff member receives a setup code. Pending setup does not grant access.\n3. On the dedicated staff setup screen, enter the latest staff setup code to activate that account. Use this code only for this setup; never enter it in chat.\n4. Give the initial password privately so the staff member can sign in and change it. Never request their personal email-verification code, password-recovery link, or account password in chat.",
                'owner staff setup, activate staff account, staff setup otp, add staff account'],
            ['owner', 'How does the owner manage FAQ audiences and import answers?',
                "1. Open Settings and the Approved FAQ library. Search for an existing question before adding one.\n2. Write a simple answer and choose Customer for public help, Staff for staff and owner workspace help, or Owner for owner workspace help. Review older custom entries, which default to Customer.\n3. Choose Active to make the answer available to that audience, or Inactive to save it without sharing. Save the FAQ.\n4. For a CSV, download the template and use customer, staff, or owner in the optional audience column. A missing or blank audience is public Customer. All rows are checked before import. Never put passwords, private codes, or service keys in an answer.",
                'owner faq audience, edit faq, import faq, approved answers, faq settings'],
            ['owner', 'How does the owner review the activity log?',
                "1. Open Activity log in the workspace.\n2. Use its filters to find the action, account, or date you need.\n3. Read the recorded actor, time, action, and change details to understand what was saved.\n4. Compare the related invoice or product when investigating a change. The log records actions; it does not undo them or itself confirm a provider payment.",
                'owner activity log, audit trail, review activity, who changed'],
            ['owner', 'How does the owner review store settings and service readiness?',
                "1. Open Settings and enter confirmed opening hours, pickup details, and support contacts. Leave unconfirmed facts blank, then save.\n2. Review the payment, delivery, assistant, and email status cards. Ask the website maintainer to configure unavailable services on the server.\n3. Review email verification, staff setup, password recovery, and payment reminder readiness. A configured email connection still needs a real inbox test; do not assume delivery.\n4. If changing default partial-payment terms, choose 7–30 days and record a reason. The new default applies to future invoices; an existing invoice needs its own recorded agreement.",
                'owner system settings, service readiness, store information, payment terms settings'],
            ['owner', 'How does the owner inspect a return and process its refund?',
                "1. Open Refunds and the request. Confirm the invoice is fully paid and Delivered, then read the applicable return policy and deadline.\n2. Record or reuse a physical return inspection for that invoice, including returned items, quantities, condition, and private evidence or its required explanation. Record a valid exception for a late approval.\n3. Choose the original received payment receipt, the eligible amount, and the decision reason. Approval is limited by the inspected return and remaining payment.\n4. For online refunds, issue the refund in PayMongo and verify its reference here. For cash, record it only after handing back the approved amount. Approval alone does not move money, restock clothing, or cancel a courier.",
                'owner refund inspection, review refund steps, process return, approve refund'],
        ];

        foreach ($rows as $index => [$audience, $question, $answer, $keywords]) {
            // Existing questions may contain owner edits or be deliberately inactive.
            DB::table('chatbot_faqs')->insertOrIgnore([
                'question' => $question, 'answer' => $answer, 'keywords' => $keywords,
                'audience' => $audience, 'active' => true, 'sort_order' => 300 + $index * 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retain the audience boundary and editable answers when reverting code.
    }
};
