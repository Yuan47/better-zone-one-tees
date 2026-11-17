<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Exact hashes identify untouched shipped answers, not owner-written text.
    public const REFRESH = [
        [
            'question' => 'How can I track my order?',
            'previous_sha256' => '5db8550e3cb5beffcce3e80ef3ac896a0fd11d2f1a029adcd25ee5a7af109002',
            'answer' => 'Sign in and open My Purchases to see your order status, or share your full order number (ZOT-…). Customers can only access their own orders.',
        ],
        [
            'question' => 'How is the outstanding balance calculated?',
            'previous_sha256' => '9db73191b7d5b9f245d25432ab790d0398aee78dbbab177582a48273feb51e94',
            'answer' => 'Outstanding balance = original invoice total minus payments received. Pending, failed, or uncertain payment attempts do not count as received payments. Open the original invoice in My Purchases to see the recorded payments, remaining balance, and due date.',
        ],
        [
            'question' => 'Will I receive payment reminders?',
            'previous_sha256' => '39c58df4f13b04f232ec175ffbf79a72b818edecca81585abf53dbf59aa30909',
            'answer' => 'New orders require full payment; reminders do not create an installment option or extend a checkout session. Pending invoices may appear in account alerts. For preserved older invoices with an outstanding balance and an original due date, email reminders can be sent 3 days and 1 day before that due date when the store email service and automatic tasks are enabled. Check the same invoice in My Purchases even if an email is delayed or missing. A reminder is not a payment receipt, and never pay again just because an email did not arrive.',
        ],
        [
            'question' => 'How do I confirm receipt of an online order?',
            'previous_sha256' => '461b8a04176d228010516d31eed859e977aac3d037a48bca3cfbdb08c96a32ea',
            'answer' => 'After receiving and checking your clothing, open the fully paid order in My Purchases. Once courier completion is verified or your pickup order is ready, choose a rating from 1 to 5 stars, tick the confirmation that you received the order, and choose Confirm Delivered. The written review is optional. A courier completion update alone does not close a customer online order. Fully paid walk-in invoices use the separate payment-received rule and are marked Delivered automatically.',
        ],
        [
            'question' => 'Where can I find my payment receipt and order reference?',
            'previous_sha256' => '5763ead73b7d94aa5616b35cc7ff00715e84ab70ef87a2286cb5bad3cd94844f',
            'answer' => 'Sign in, open My Purchases or My dashboard, then open the order. Its ZOT order number identifies the original invoice. Invoice payments lists each payment with its own reference, amount, method, status, and received date. A confirmed PayMongo payment also shows its provider transaction reference. Only received payments are payment receipts; pending or uncertain attempts are not proof of payment.',
        ],
        [
            'question' => 'Where are my order history and favorites?',
            'previous_sha256' => '7b7b1e76317a09f2029bd2bb5e96c6c96d261f1b86c0e9f57aacf5a6cf35cc96',
            'answer' => 'Sign in and open My dashboard for your customer overview and links to My Purchases, Favorites / wishlist, and My Account. My Purchases shows your order history and opens each original invoice. Use the heart control on a product to save it, then open Favorites / wishlist to see your saved products.',
        ],
        [
            'question' => 'How do I open my order history and track an order?',
            'previous_sha256' => '18ebaf63aae1f0c61d7a679aba45c4c3345dda4566bf482d6e5638c656343628',
            'answer' => '1. Sign in to the account used for the order.
2. Open My dashboard, then My Purchases.
3. Open the order with the matching ZOT order number to see its invoice, payments, and current status.
4. Use Refresh order status or the delivery tracking link when shown. Your account only opens your own orders.',
        ],
        [
            'question' => 'How do I find my invoice and payment references?',
            'previous_sha256' => '158fef4510bd728c07f7dcf3386a3fabb163d388880c1fcb43b5ff78273b9a71',
            'answer' => '1. Sign in, open My Purchases, and choose the order.
2. Use its ZOT order number as the original invoice reference.
3. In Invoice payments, check each payment reference, amount, method, and received date. A confirmed online payment also shows its PayMongo transaction reference.
4. Treat only Payment received entries as receipts. Pending attempts, screenshots, and returning from checkout do not confirm payment.',
        ],
        [
            'question' => 'How do I confirm delivery and leave my review?',
            'previous_sha256' => '202c8ad54e3f9290d3d6403f392470d2cd8092e9d463d13bf85b30e11a6668de',
            'answer' => '1. Receive and check your clothing.
2. Sign in and open the fully paid order in My Purchases.
3. Once courier completion is verified or pickup is ready, choose a rating from 1 to 5 stars. The written review is optional.
4. Tick the confirmation that you received the order, then choose Confirm Delivered. This closes an online order as Delivered; a courier update alone does not. Fully paid walk-in sales follow their separate handover rule.',
        ],
        [
            'question' => 'How do I ask for a refund or return?',
            'previous_sha256' => '387ac0ffa1d5ef96b170793ad6207092a691e8fa433867dc3a8615fcb9cf35a7',
            'answer' => '1. Open your fully paid, Delivered order in My Purchases or your private guest invoice. For an online order, first choose a star rating and confirm receipt. The written review is optional.
2. Read the return policy and choose Request a refund or return.
3. Enter your reason and upload the required clear supporting photo of the clothing or issue (JPG, JPEG, PNG or WebP, up to 3 MB and 6000 pixels per side). Send the request for review.
4. Follow the store instructions for returning the clothing and check the decision on your order. Photos are private. A request or approval does not mean money has arrived; the owner reviews eligibility and confirms the refund separately.',
        ],
        [
            'question' => 'How do staff help a customer confirm an online order was received?',
            'previous_sha256' => '5227bd80b4cb814a8949e6467f084d6c968642b58b1259fdc866c86ca65568ec',
            'answer' => '1. Open the fully paid order and check that courier completion is verified or pickup is ready.
2. Ask the customer to sign in to their own account and open that order in My Purchases.
3. After receiving the clothing, the customer chooses a rating from 1 to 5 stars, ticks the receipt confirmation, and chooses Confirm Delivered. The written review is optional.
4. Check the updated order status. Staff cannot submit the customer receipt or rating for them; a courier completion update alone does not close an online order.',
        ],
        [
            'question' => 'What should I do if my payment receipt email is missing?',
            'previous_sha256' => '2ef3b3038cf22cd4495f7217cdc78f090ca30350f5480279ea1979d2efdab19a',
            'answer' => '1. Sign in, open My Purchases and choose the original invoice. In Invoice payments, only Payment received entries confirm money received; an email or checkout return alone does not confirm payment.
2. Each received payment has its own receipt reference and amount on the same invoice. Older invoices retain their payment history; new orders require full payment. Later payments or refunds appear on the invoice.
3. Check your account inbox and spam folder. The optional Zone One Tees store receipt is a separate acknowledgment from PayMongo email; either email may be delayed or fail.
4. If still missing, contact the store with the ZOT invoice number and receipt reference. Never pay again just to obtain an email. Never share your password, code or private receipt link in chat.',
        ],
        [
            'question' => 'Why did an old store link take me to the updated website?',
            'previous_sha256' => '33703a12079d000e37fe7519f1d9c505364ed11a9d9ffbe53eb11febd08f233e',
            'answer' => '1. The store can move its normal pages to an updated website. Use the store\'s confirmed current address.
2. You may need to sign in again there, using your usual customer sign-in method. A website move does not create a new customer account.
3. Open My Purchases to review your original invoices and payments. If you use a guest invoice, keep its private store-issued link safe and do not share it in chat.
4. If an old sign-in attempt does not finish, open Sign in on the current website and start again. Never share your password, verification code, or recovery link.',
        ],
        [
            'question' => 'Where can I see customer ratings and reviews for an item?',
            'previous_sha256' => 'cbf63c99d4910e71d6d5240da589a417a91b925f47e9f7e554591084d684f562',
            'answer' => 'Open the product and scroll below its details and size chart to Customer reviews. The summary and star counts use verified completed customer orders containing that product. Ratings belong to the whole order, not a separate rating of each item. Written reviews are optional, so some entries show stars only. A mixed order can appear on each product it contained, once per product. Buyer names are masked; emails, invoice numbers and private refund photos are not published. After receiving an eligible fully paid online order, open it in My Purchases to choose stars and confirm receipt. Product reviews do not let you review an order you did not purchase.',
        ],
        [
            'question' => 'How do I create a customer account?',
            'previous_sha256' => 'dab03091df281e391cef6c99b2d8217d59de8e8fabbc55074f2a87c098ed7122',
            'answer' => '1. Open Sign in, then Create an account. Enter your full name, unique username, email and matching passwords of at least 10 characters.
2. Read and agree to Terms and Conditions, then choose Create account. Enter the latest emailed verification code only on the verification page when requested.
3. Continue with Google, when shown, can create a customer account using a Google-verified email after you accept the terms. An existing email account must be signed in to and explicitly linked instead of registered again.
4. Use an inbox you control. Email spelling checks do not prove ownership. Keep passwords, recovery links and verification codes private; the assistant never needs them.',
        ],
        [
            'question' => 'How do I sign in to my customer account?',
            'previous_sha256' => '12014bac6031ef5adb5fe66a68b78a0128a0e330415b2abe157cf4ed620a1039',
            'answer' => '1. Open customer Sign in and enter the email and password used for your account.
2. If asked, verify your own email using the latest code on the verification page.
3. Continue with Google works for a new or already linked customer Google account. If your email was registered with a password, sign in with that password and link Google from My Account first.
4. If needed, use Forgot your password? Staff and owner accounts use their separate email/password sign-in. Never send passwords or codes in chat.',
        ],
        [
            'question' => 'How do I update my profile and delivery address?',
            'previous_sha256' => '53553137c43458265a46c69f850e0d9f2d4d7074c074f222051df7dbf2f55553',
            'answer' => '1. Sign in and open My Account. Edit your full name, username, photo or mobile number, then choose Save account. Your sign-in email is fixed.
2. Open My addresses through the account menu or Manage delivery addresses and map pins. Choose Add address or edit one you own.
3. Enter the recipient, an 11-digit mobile number beginning 09 and complete street, city, province, postal code and country. Choose and confirm the correct delivery entrance on the map, then save the address.
4. Review the saved address and delivery pin at checkout. GPS permission is optional. Changing a saved address does not change an order already placed; a changed checkout address or pin needs a fresh delivery quote.',
        ],
        [
            'question' => 'How do I add items to my cart and check out?',
            'previous_sha256' => '0429d8afce277df3ed4fa5951bf5d354406753dab01f3fa7c257a8d68efe1075',
            'answer' => '1. Open a product and choose its available color, size and quantity. Add to Cart, then review quantities and the automatic quantity price.
2. Sign in to your customer account and continue to checkout. Choose store pickup or Lalamove delivery and complete the required contact details.
3. For Lalamove, confirm the delivery entrance pin and request the delivery fee. Review clothing, delivery and the full invoice total before placing the order.
4. Pay the full invoice through an available PayMongo checkout method. Open the same saved invoice in My Purchases to check confirmation. A checkout return or screenshot is not proof of payment; check a pending attempt before paying again.',
        ],
        [
            'question' => 'How are returned clothes checked before a refund?',
            'previous_sha256' => 'd33c1f1056d9627b6f14f04d6ceb3034bb0ba0ef85eb83c19804c4e83bf10c5e',
            'answer' => 'The original invoice must be fully paid and Delivered. Submit the required private supporting photo and your reason through that order. The owner records the returned clothing, quantities, condition and inspection notes before deciding eligibility. Approval is limited by inspected eligible value and original received payments, without counting the clothing twice. A request or approval does not prove money was returned, and a refund does not automatically restock clothing.',
        ],
        [
            'question' => 'How do staff record cash received on an invoice?',
            'previous_sha256' => '6bdce1c63b96a8656dcacd3a2a8382f053da09160f6a794ea3316b5580bf0f41',
            'answer' => '1. Open the original invoice and check its entire outstanding balance. New orders require full payment; older invoices retain their received payments.
2. Resolve any pending or uncertain online attempt before accepting another payment. Do not create a replacement invoice or treat a screenshot as cash received.
3. Receive the whole remaining balance in store, enter that full amount and a clear receipt note, then tick the cash-received confirmation. A smaller installment is not accepted.
4. Save once and check the received payment reference and zero remaining balance. Fully paid walk-in sales are marked Delivered automatically. Online orders still follow customer receipt confirmation.',
        ],
        [
            'question' => 'How do staff read and export sales reports?',
            'previous_sha256' => '152f667c911b96d47404cac2042d96c6b304f06d679584c81560e1d0bad4e8c3',
            'answer' => '1. Open Reports. Choose Quick dates, or choose Group sales by and both From/To dates, then View sales. Dates use Asia/Manila.
2. Read Total Sales and the chart for that range: fully paid clothing after discounts, before refunds, excluding delivery. View exact sales amounts opens the matching table.
3. Use Export payments in the Reports section for the same chart dates. The CSV keeps delivery, confirmed refunds, original invoice and net invoice separate; net invoice includes delivery.
4. Visible active reports check for recorded changes every 15 seconds; Refresh data checks immediately. Forecasts use completed months. Operations uses order placement dates, so its counts can differ from paid-date sales.',
        ],
    ];

    public const ADDITIONS = [
        [
            'audience' => 'customer',
            'question' => 'Can I use Google if I already registered with the same email?',
            'answer' => '1. Keep your existing account. Sign in with its email and password; do not register another account.
2. In My Account, find Google sign-in, enter your current password on that page and choose the link action. Complete Google sign-in yourself using the matching email.
3. After successful linking, Google opens the same customer account, orders and favorites. Matching an email alone does not silently link accounts. If you forgot your password, recover it first.
4. Google sign-in is for customers. Staff and owners use email/password and their approved setup. Never share passwords or Google codes with the assistant.',
            'keywords' => 'same email Google, Google duplicate account, already registered email, link Google account, existing manual account',
            'sort_order' => 1000,
        ],
        [
            'audience' => 'customer',
            'question' => 'Why did my Google signup not ask for another email code?',
            'answer' => 'A new Google customer signup accepts only a Google-verified email and requires acceptance of the store terms. Google has already verified that email, so another store email code is not required for that signup. Manual email/password signup follows the store verification-code flow when enabled. Google does not convert a customer into staff or owner, and it does not silently merge a password account with the same email. Use My Account to explicitly link an existing customer account.',
            'keywords' => 'Google no OTP, Google verified email, Google no code, Google signup verification',
            'sort_order' => 1001,
        ],
        [
            'audience' => 'customer',
            'question' => 'Can I change my sign-in email in My Account?',
            'answer' => 'Your sign-in email is fixed in My Account. You can edit your full name, username, photo and mobile number, and manage addresses separately. If you no longer control that inbox, ask the store for assistance; do not create a second account just to move order history. Never send your password, verification code or recovery link in chat. The assistant cannot change your email or grant account access.',
            'keywords' => 'change email, fixed sign in email, email readonly, lost email access',
            'sort_order' => 1002,
        ],
        [
            'audience' => 'customer',
            'question' => 'Why is my cart empty while my payment is still pending?',
            'answer' => 'When checkout creates the invoice, its purchased quantities leave the cart even if payment still needs checking. Open that invoice in My Purchases and check its recorded payment progress before ordering or paying again. A pending or uncertain attempt is not a confirmed payment. Remaining cart items stay saved according to your account or guest browser session. Saving a cart does not reserve stock or lock prices.',
            'keywords' => 'cart empty payment pending, items disappeared checkout, cart after invoice, pending checkout cart',
            'sort_order' => 1003,
        ],
        [
            'audience' => 'customer',
            'question' => 'Why can I not confirm receipt yet?',
            'answer' => '1. Confirm receipt only after you have physically received and checked your clothing.
2. The online order must be fully paid. Courier completion must be verified, or a pickup order must be ready. Until then the receipt action is unavailable.
3. When available, select 1 to 5 stars and tick I have received my order, then choose Confirm Delivered. Written comments are optional.
4. If you received the clothing but the action remains unavailable, ask staff to check the same order. Staff cannot submit your rating or receipt for you. Fully paid walk-in handovers follow their separate completion rule.',
            'keywords' => 'cannot confirm receipt, order received button unavailable, cannot rate order, receipt disabled',
            'sort_order' => 1004,
        ],
        [
            'audience' => 'staff',
            'question' => 'How do I check daily, weekly, monthly or yearly sales?',
            'answer' => '1. Open Reports on the dashboard or above Sales & Growth Forecast. Quick dates such as Today, This week or This month apply immediately.
2. Group sales by Daily, Weekly, Monthly or Yearly. For your own range, enter both From and To, then choose View sales. Weeks start Monday; dates use Asia/Manila. This year chooses Monthly automatically.
3. Without custom dates, Daily shows the latest 7 days, Weekly 8 weeks, Monthly 12 months and Yearly 5 years. Read the date label before comparing totals.
4. Total Sales, paid count, average order, exact-amount table and the Reports Export payments share that chart range. Report dates do not retrain the monthly forecast.',
            'keywords' => 'daily weekly monthly yearly sales, sales date filters, quick dates, view sales, group sales by',
            'sort_order' => 1005,
        ],
        [
            'audience' => 'staff',
            'question' => 'What do Total Sales and Average order mean?',
            'answer' => '1. Total Sales adds the clothing subtotals of fully paid orders in the shown payment-date range, after quantity discounts. It includes verified online payments and recorded cash sales.
2. Delivery is excluded and refunds are reported separately. Total Sales is before refunds; it is not profit, provider payout or net invoice money retained.
3. Average order = those clothing sales divided by fully paid order count in the same range. For PHP 1,000 over 4 paid orders, the average is PHP 250.00. Zero orders shows zero.
4. Money and percentages show two decimal places. Product demand estimates round up to whole pieces. Use separate refund and invoice fields when reviewing money after refunds.',
            'keywords' => 'total sales definition, average order value, AOV formula, clothing sales meaning, total sales before refunds',
            'sort_order' => 1006,
        ],
        [
            'audience' => 'staff',
            'question' => 'How often do sales reports refresh?',
            'answer' => '1. Reports check every 15 seconds while the page is visible, online and active. Refresh data checks immediately. This is near-live checking after payment confirmation has been saved, not an instant stream from PayMongo.
2. Hidden or offline pages pause checks. Editing dates or grouping pauses updates until you apply the filters. After 15 minutes without page interaction, checking pauses until you interact again.
3. Last checked shows when a report check succeeded. New payments affect the dates where they were recorded, so a payment outside the chosen range will not change that total.
4. Current-month payments update actual sales. Forecasts use completed months, so their monthly estimate can stay unchanged. A refresh never confirms a payment or creates a new order.',
            'keywords' => 'automatic report refresh, live reports, every 15 seconds, last checked, refresh data, live sales update',
            'sort_order' => 1007,
        ],
        [
            'audience' => 'staff',
            'question' => 'What should I do if reports stop refreshing?',
            'answer' => '1. Read the message beside Last checked. A connection or server error keeps the last figures, so check their time before relying on them.
2. Restore the connection and choose Refresh data. Automatic retries slow down during repeated failures; return to the visible page and apply any edited filters.
3. If your session expires or workspace access is denied, private figures are cleared. Sign in again using your registered staff or owner account.
4. A failed report refresh does not mean a payment failed. Open the original order and check its payment record before retrying payment, changing cash records or promising confirmation.',
            'keywords' => 'reports not updating, sales refresh failed, reports stopped refreshing, refresh connection error, stale sales figures',
            'sort_order' => 1008,
        ],
        [
            'audience' => 'staff',
            'question' => 'Why do dashboard totals and charts show different numbers?',
            'answer' => '1. Read each date label. With no sales dates selected, the first summary cards show all-time figures, while Total Sales and Sales over time start with a recent window. Explicit sales dates filter the first sales cards and actual-sales chart.
2. Delivery and balance widgets show the current position across all dates. Total sales this month uses the current calendar month.
3. Walk-in/online and Top 5 items graphs use the current month plus the preceding 3 months. Operations uses orders placed within its dates; sales reports use confirmed payment dates.
4. Forecasts use completed calendar months. Matching date labels and definitions is necessary before comparing figures; a different scope is not automatically missing sales.',
            'keywords' => 'dashboard totals different, charts different numbers, report scopes, all time versus date range, four month charts',
            'sort_order' => 1009,
        ],
        [
            'audience' => 'staff',
            'question' => 'Why does a new payment change Total Sales but not the forecast?',
            'answer' => '1. Total Sales shows actual fully paid clothing sales in its chosen payment-date range. It updates after confirmation is recorded and a refresh succeeds.
2. The monthly forecast uses up to 6 completed calendar months. The current incomplete month is excluded, so a new current-month payment does not retrain that estimate.
3. Changing report dates or Daily/Weekly/Monthly/Yearly grouping changes actual-sales presentation, not forecast training. Product stock and Available or Suggested reorder can still change.
4. When a month is complete, its eligible paid history can enter the next monthly calculation. Estimates, especially from simulated data, do not guarantee future demand or real-world accuracy.',
            'keywords' => 'new payment forecast unchanged, total sales forecast difference, current month forecast, dates do not retrain forecast',
            'sort_order' => 1010,
        ],
        [
            'audience' => 'staff',
            'question' => 'What can staff do, and when should they ask the owner?',
            'answer' => '1. Staff can use walk-in checkout, record full cash actually received, check payments, pack paid orders, mark ready with a reason, and book or reconcile eligible deliveries.
2. Staff can review customer orders, reviews, assigned reports and forecasts. Only the customer submits receipt confirmation and the customer rating for an online order.
3. Ask the owner for product or stock changes, archive/delete actions, staff setup, settings, FAQ changes, return inspection/approval, cash refunds or approval to cover a higher courier quote.
4. Use only the actions your signed-in role permits. The assistant explains steps; it cannot charge money, approve refunds, edit stock or grant permissions.',
            'keywords' => 'staff permissions, staff versus owner, what can staff do, ask the owner, role responsibilities',
            'sort_order' => 1011,
        ],
        [
            'audience' => 'staff',
            'question' => 'What do Critical low and Alert mean in inventory?',
            'answer' => '1. Read Inventory needs attention on the dashboard; the owner also has Notifications in the workspace header. Open View affected variants to check each size/color. Available pieces = counted stock minus reserved pieces.
2. Red Critical low means available pieces are at or below half the reorder threshold, rounded down; zero available is critical.
3. Yellow Alert means available pieces are above that critical level but at or below the reorder threshold. Above the threshold is healthy.
4. For threshold 10, available 0 to 5 is Critical low and 6 to 10 is Alert. Ask the owner to confirm actual stock and restocking needs. A color badge does not create a purchase or change stock automatically.',
            'keywords' => 'critical low red, low inventory yellow, inventory alert meaning, reorder threshold, stock status colors, low stock warning, inventory warnings',
            'sort_order' => 1012,
        ],
        [
            'audience' => 'staff',
            'question' => 'Why did a canceled delivery return to Ready for delivery?',
            'answer' => '1. A provider-verified canceled, rejected or expired booking can return the order to Ready for delivery. The saved booking history remains available.
2. Open the same order and use the delivery check or reconciliation action to establish the original booking outcome. An uncertain result is not proof of cancellation.
3. Rebook only when the original booking is verified inactive and the order is eligible. Do not create two courier bookings while one outcome is unknown.
4. If a fresh quote is above the delivery fee already paid, ask the owner to review covering the difference. Only an explicit owner approval can allow that extra store cost; the customer invoice stays unchanged.',
            'keywords' => 'canceled courier ready again, delivery cancellation recovery, rebook delivery, uncertain delivery booking',
            'sort_order' => 1013,
        ],
        [
            'audience' => 'staff',
            'question' => 'Why can I not mark an order Ready or book a courier?',
            'answer' => '1. Open the order and verify full payment. Pending or uncertain online attempts and payment screenshots do not qualify.
2. Check the order stage, correct sizes/colors/quantities and completed packing. Enter a clear reason when marking Ready for delivery.
3. A refund fulfillment hold or unresolved existing courier booking blocks normal progression. Reconcile the existing record and ask the owner about a hold instead of creating another order.
4. Lalamove booking also needs a valid quote and delivery details. A higher courier quote needs owner cost approval. Store pickup does not use Lalamove; online customers confirm receipt after ready pickup or verified courier completion.',
            'keywords' => 'mark ready blocked, courier booking unavailable, cannot book courier, packing eligibility, refund fulfillment hold',
            'sort_order' => 1014,
        ],
        [
            'audience' => 'owner',
            'question' => 'Why will a staff setup code not activate the account?',
            'answer' => '1. Open the pending setup in Staff accounts. Use the latest six-digit code received by that staff member; a new code replaces the previous one and expires after 10 minutes.
2. If missing or expired, use the resend action after the shown wait. Resends are limited and too many incorrect attempts require a new code. Check the inbox and spam folder.
3. A pending setup does not grant staff sign-in or password-recovery access. An email already used by another account cannot be converted into staff through this setup.
4. The owner completes verification with the staff member through the approved setup process. Enter the code only on that page, never in chatbot messages, FAQ answers or public notes.',
            'keywords' => 'staff setup code expired, staff activation failed, staff invitation pending, staff OTP troubleshooting',
            'sort_order' => 1015,
        ],
        [
            'audience' => 'owner',
            'question' => 'Where can the owner see low-stock notifications?',
            'answer' => '1. Open Notifications in the workspace header. Its badge counts affected size/color variants of active products, not unread messages or total products.
2. Red Critical low means critically low or zero available stock. Yellow Alert means low available stock. Choose View affected variants to open the full inventory attention list.
3. The notification menu updates when you open or reload a workspace page. Opening the menu does not clear a stock problem. The dashboard warning also updates with its normal active report refresh.
4. Review the exact sizes/colors and counted quantities before changing stock or ordering more clothing. An alert does not restock automatically or send an email. When available stock recovers above its threshold, the warning disappears on the next applicable refresh.',
            'keywords' => 'owner stock notifications, low stock notifications, notification badge, inventory notification bell',
            'sort_order' => 1016,
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::REFRESH as $change) {
                $faq = DB::table('chatbot_faqs')->where('question', $change['question'])->lockForUpdate()->first();
                if ($faq && $faq->question === $change['question']
                    && hash_equals($change['previous_sha256'], hash('sha256', $faq->answer))) {
                    DB::table('chatbot_faqs')->where('id', $faq->id)->update([
                        'answer' => $change['answer'], 'updated_at' => now(),
                    ]);
                }
            }
            foreach (self::ADDITIONS as $row) {
                // Preserve a matching owner's answer, audience, inactive switch and ordering.
                DB::table('chatbot_faqs')->insertOrIgnore($row + [
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep corrected help and later owner edits; do not revive obsolete instructions.
    }
};
