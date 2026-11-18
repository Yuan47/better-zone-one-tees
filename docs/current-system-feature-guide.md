# Zone One Tee’s: current system feature guide

**Last source inspection: 18 November 2026 · Asia/Manila**

This guide describes source-inspected features and intended workflows. Validation including the simplified workspace and automatic readiness marking passed **2,008 automated tests with 17,691 assertions** using isolated data, plus phone and tablet browser checks. Earlier checks also covered the inventory warnings and notification menu. This does not certify a new real-provider payment or inbox test on this date. Hosted publication results are recorded separately for each update.

## 1. What the system does

Zone One Tee’s combines ordering, payments, inventory, delivery coordination, controlled returns and sales information. Customers use the storefront; staff and the owner use a separate workspace. It works through a computer, tablet or phone browser.

The system supports **full payment for new orders**. Deposits and new installment orders are no longer offered. Older invoices retain their original payment history; a remaining balance must be settled in full on the same invoice. Keeping those records does not make partial payment available for new purchases.

| Person | Main activities |
|---|---|
| Customer | Create or sign in to an account, choose clothing, save favorites and a cart, maintain delivery addresses, pay, track purchases, confirm receipt and request a return. |
| Staff | Record walk-in sales, review orders and payment records, pack paid orders, arrange and check delivery, view reviews, and use the reports and assistance assigned to the workspace. |
| Owner / Admin | Use staff functions plus manage products, variants, customers, staff setup, store information, FAQ audiences, activity logs, return inspections, refund decisions and owner recovery actions. |

The current implementation allows **both staff and the owner to open Reports and Sales & Growth Forecast**. Do not describe forecasts as owner-only when documenting this version. Product management, customer-register administration, staff setup, FAQ editing and refund approval/inspection remain owner functions.

## 2. Customer account and shopping

### Creating and protecting an account

The customer sign-in page is `/login`; account creation is `/register`. Manual registration requires a full name, unique username, email, matching passwords of at least 10 characters, and agreement to the current Terms and Conditions. The email format is checked, including common provider misspellings. A valid-looking address alone does not establish ownership: when verification is required, the customer must enter the latest six-digit code sent to that address.

Verification codes expire after 10 minutes. Resending has a 60-second wait; a new code replaces the old one. Forgotten-password recovery uses an emailed, one-use link expiring after 60 minutes when enabled. Enter passwords, codes and recovery links only on their account pages, never in chat.

**Google sign-in is for customers.** A new account created through a verified Google identity does not need another store email code, but still requires the store terms. If the same email already belongs to a manually registered customer, the website does not silently create or merge a second account. The customer signs in with the existing password, opens My Account, and explicitly links the matching Google identity after confirming the current password. Staff and owners keep using email/password access.

My Account allows changes to the name, username, profile picture and customer mobile number. The sign-in email is fixed. Delivery addresses are managed through **My addresses**, rather than by changing an already placed order. Customer sessions automatically sign out after 15 minutes without activity; this does not cancel orders or erase saved account records.

### Selecting products and quantity prices

Customers search the catalog and select the actual product, size, color and quantity. White is a normal color choice, alongside the other listed colors; there is no separate White/Colored selection step. Dannon Round Neck and V-Neck, including their kids’ products, are separate products. Prices and stock belong to the selected size/color variant, not to the picture or size-chart reference.

The total clothing quantity across the cart determines the pricing tier:

| Total pieces | Clothing price used |
|---|---|
| 1–5 | Retail / SRP |
| 6–50 | Wholesale |
| 51–100 | Re-seller |
| 101 or more | Re-seller clothing subtotal less 5% |

Different products and sizes can have different unit prices within the same tier. Delivery is charged separately. Size charts show width and length in inches; a reference measurement does not mean that size is available for purchase. Pictures marked **Demo preview** are generated examples, not actual supplier photographs or guarantees of fabric, fit or exact shade.

The heart control saves a product to Favorites / wishlist. A saved favorite does not reserve stock. Signed-in customers also have a saved cart linked to their account, so added selections can return after logout or browser closure. Guest cart recovery depends on retaining the browser’s store cookie. On sign-in, the same variant in guest and account carts keeps the larger quantity rather than adding it twice. Customers should review quantities before checkout.

A cart does not lock prices or hold stock. Availability is checked when the purchase is submitted. When an invoice is created, its purchased quantities leave the saved cart, even if the payment outcome still needs checking. The saved invoice remains in My Purchases; an empty cart is not evidence that the invoice was paid or lost.

## 3. Address, checkout and verified payment

### Address and delivery fee

In **My addresses**, customers add, edit, remove and choose a default address. An address includes Home/Work or another label, recipient, mobile, street, city, province, postal code, country and entrance pin. Mobile numbers must be **11 digits beginning with 09**.

For Lalamove delivery, a complete Philippine address and confirmed map pin are required. The customer can tap the map, move the pin, or use the coordinate fallback if the map cannot load. **GPS permission is optional**: “Use my current location” is only a shortcut. The customer must still ensure that the pin marks the correct delivery entrance and agrees with the written address. Required delivery fields are marked with an asterisk.

Checkout can use an owned saved address or another complete address. The customer requests the Lalamove fee before paying. Changing the address or pin requires a fresh quotation. Saving a new profile/address detail does not rewrite the destination of an order already placed. Store pickup does not require a customer delivery pin or a courier fee.

The owner can set **Expected delivery days** in Store settings → Store information. The default is **1-2 days after confirmed full payment**. Enter digits or one ascending range, such as `3` or `1-2`; letters and extra hyphens are rejected. Checkout shows this estimate for Lalamove delivery. New delivery invoices keep the value saved at creation, so a later policy change does not replace it. Older orders without a saved value are labeled with the current store estimate instead. This is an estimate rather than a guaranteed courier arrival time; it does not change the fee, payment confirmation or actual delivery status.

### Payment and documents

The normal online sequence is:

1. Review the cart and quantity price.
2. Confirm recipient/contact information and choose pickup or Lalamove delivery.
3. For delivery, confirm the entrance pin and obtain the fee.
4. Read the complete clothing, delivery and invoice totals, accept the checkout terms, and continue to PayMongo for full payment.
5. Return to the original invoice and wait for the saved payment status to be verified.

The original order has a **ZOT invoice/order reference**. Its payment records show amount, method, status and reference; confirmed online receipts also show a provider transaction reference. Store receipt emails depend on email configuration and recipient delivery.

Returning from PayMongo or presenting a screenshot does not mark an invoice paid. Verified provider notification or reconciliation establishes payment received. Check uncertain attempts on the **same invoice** before paying again. Eligible abandoned checkout recovery uses provider-verified expiry/closure and reconciliation; a browser countdown alone is not proof of failure.

My Purchases presents searchable purchase history and status tabs, with each invoice’s original details, payment records and fulfillment progress. Only the customer’s own account orders are available there. An unregistered walk-in customer uses a private guest invoice instead of acquiring a customer account automatically.

## 4. Staff and owner order operations

Staff and the owner sign in through `/admin/login`. Staff accounts are created by the owner and activated with the code emailed to the staff member; public customer registration does not create staff access.

### Walk-in and fulfillment

For a walk-in sale, select a registered customer to link the order history, or enter guest details without creating an account. Add the actual variants and quantities, review the full price, then choose recorded cash received or full online payment. **Cash must actually be received in store** before the confirmation is checked and the receipt reason is saved. A screenshot must not be entered as a cash payment. Online payment waits for provider verification.

A fully received walk-in payment completes its handover and marks that sale Delivered automatically. Online customer orders use a separate process:

| Status | What it means / next action |
|---|---|
| Packing | Payment is confirmed. Staff prepare the clothing. |
| Ready for delivery | Packing is complete. Arrange the courier or prepare the pickup handover. |
| To Ship | A courier booking is active. Open the order for its latest Lalamove details; the label alone does not mean the rider has arrived. |
| Delivered | The online customer confirmed receipt with a star rating, or the separate fully paid walk-in handover rule completed. |

Routine packing/ready changes automatically record the signed-in staff member or owner in the activity log. Marked by shows that name; no routine reason entry is needed. The booking flow checks the order’s payment and readiness. If the outcome of a booking is uncertain, staff reconcile or recover that booking before creating another one. If a refreshed courier price exceeds the customer’s already-paid delivery fee, the owner may approve covering the difference with a reason and cost limit; the customer invoice stays unchanged. Do not tell a customer to make an unrecorded extra payment.

Ready-for-delivery notification/email is prepared through the store notification workflow. Its recorded sending status should be checked rather than assuming that “sent” means the customer has read it. Courier updates show progress, but courier completion alone does not close an online customer order.

For demonstrations, verify **PayMongo Test Mode and Lalamove Sandbox** before proceeding. Use only test outcomes, virtual test credit and the approved TEST contact. Pack a fully paid test order, choose Mark Ready for delivery, check the automatically recorded name, book once, simulate progress in the Sandbox portal and reconcile the store status. Never claim that a simulated booking physically delivered clothing or that a test payment moved real money.

### Receipt, reviews and returns

After actually receiving and checking an eligible fully paid online order, the customer selects one to five clickable stars, checks the receipt confirmation and submits it. Courier completion must be verified, or pickup must be ready. **The written review is optional.** This changes the online order to Delivered.

Completed-purchase reviews appear below the products and in the staff/owner Customer reviews workspace. A rating belongs to the whole order and is displayed once on each product in that purchase; it is not a separate rating for every individual item. Public buyer details are masked. Refund evidence is not a public product review photo.

A refund request is available only for a fully paid, Delivered order or completed walk-in handover. The customer provides a reason and a **required clear supporting photo**: JPG/JPEG, PNG or WebP, up to 3 MB and 6000 pixels on either side. The photo is private evidence for store review and does not guarantee approval.

The default request window is **7 days after confirmed receipt/handover**. The owner can configure the policy; the request retains its applicable deadline and delivery-fee eligibility. Clothing and delivery are eligible by default, subject to inspected eligibility and the remaining paid amount. A late request needs a recorded owner exception before approval. The owner records returned clothing, quantities, condition and inspection evidence, then makes the refund decision. Refund approval is not a money transfer: provider confirmation or a recorded cash return is a separate step. A refund does not automatically restock returned clothing.

## 5. Inventory, reports and assistance

The owner maintains products, photographs, variant prices and stock. New adult size choices are XS, S, M, L, XL, 2XL, 3XL, 4XL and 5XL; existing kids’/legacy sizes remain supported. Archive products/variants before deletion. Linked transaction/stock references can prevent permanent deletion, preserving historical records.

Checkout reservations protect availability while payment is unresolved. Confirmed payment consumes reserved stock; repeated notifications must not deduct it again. Favorites/carts do not deduct stock. Record legitimate stock corrections rather than changing payment records to fix inventory.

**Inventory needs attention** on the staff/owner dashboard shows red **Critical low** and yellow **Alert** warnings. Counts cover all affected size/color variants of active, non-archived products, rather than only the six-row preview. Available pieces exclude reservations. Critical means available stock is at or below half that variant’s reorder threshold, rounded down; zero is critical. Alert means above that level but at or below the threshold. For threshold 10, 0–5 is critical and 6–10 is alert.

The owner also has **Notifications** in the workspace header. Its badge counts currently affected variants, not unread messages or total products. It updates when opening or reloading a workspace page; opening the menu does not clear a warning. Dashboard warnings use the normal active report refresh, checking every 15 seconds. Both link to [View affected variants](https://tan-dolphin-569446.hostingersite.com/admin/inventory-attention). Warnings do not restock automatically or send email, and disappear after stock recovers on the next applicable refresh. This behavior was source-inspected; fresh runtime results are recorded separately.

### Understanding the dashboard

Start with Needs attention, then the labeled totals and graphs. **Total Sales / Confirmed Clothing sale** uses fully paid clothing subtotals after discounts, **before refunds and excluding delivery**. It is neither profit nor the full amount collected including delivery. Average order value is the corresponding clothing sales divided by the paid-order count. Refunds and paid invoices after refunds are shown separately.

Reports provide quick dates, custom From/To dates, Daily/Weekly/Monthly/Yearly grouping, exact-value tables and a payment CSV export. Dates use Asia/Manila; weeks begin Monday. Read the scope shown beside each widget: current delivery queues, current-month sales, selected-period sales and the four-month top-item/channel graphs have different purposes. Their counts need not be identical.

Orders & delivery keeps its date range and delivery/fulfillment information. Its removed invoice-payment and delivery-method comparison panels do not remove payment receipts or delivery details from the original order. Its order/refund graph chooses daily grouping for up to 62 days and monthly grouping for longer ranges automatically.

While a report page is visible, online and active, it checks for saved updates every **15 seconds**; Refresh data is also available. Payment confirmation must reach the system first. Editing filters pauses refresh until applied; connection failures retain the last loaded figures with retry guidance, and expired/denied access clears private report data. This is near-live updating, not a guarantee of instant delivery from an external service.

### Understanding forecasts

Actual-sales totals appear above historical growth and linear-regression graphs. Forecasts use up to **six completed calendar months**; selected actual-sales dates and current-month payments do not retrain that completed-month estimate.

- **Historical growth:** next estimate = previous completed month’s sales × (1 + growth rate), where growth rate = (last completed month − preceding month) ÷ preceding month. It needs sufficient history and a nonzero preceding value.
- **Linear regression:** `y = a + bx`, where `x` is the month number, `a` the starting intercept and `b` the average trend per month. It needs sufficient completed history.
- **Historical error:** earlier periods are predicted using only preceding history, then compared with actuals. An error figure is evidence from those test periods, not promised future accuracy.

Money is displayed to two decimals. Demand history currently combines each product’s sizes/colors; it is **not a separate forecast for every variant**. New products may show Needs history. Forecasts use original fully paid sales/pieces before returns; refunds are reported separately. A suggested reorder is planning assistance, not an automatic supplier purchase.

### Approved help and its boundaries

The owner adds, edits, activates/deactivates or deletes FAQs and can import validated CSV entries. Audience matters: Customer answers are public, Staff answers are available to staff and owner in the workspace, and Owner answers are restricted to the owner assistant. Never place credentials, private codes or personal account information into an FAQ. Customer chat cannot access internal workspace guidance or another customer’s order records. Unknown store policies and product/fabric facts should be referred to the store, rather than guessed.

## 6. What the research evaluation should and should not claim

This inspection explains workflows and access boundaries; it does not establish user-satisfaction scores, measured improvement over the old website, forecasting accuracy or freedom from failures. Identify generated images, simulated orders and test payments as demonstration material. Live payment, email, courier, scheduled-task and device results need separate checks.

Evaluate ease of use through customer/staff/owner tasks and surveys. Distinguish source review, automated tests, hosted checks, inbox confirmations and user feedback. Deferred items such as variant-level forecasts or owner-only forecast access are not implemented in this version.

Source areas checked for this guide: account/authentication and Google linking; customer address/cart/checkout; receipt/review/refund controllers; inventory and order recovery routes; report/forecast views and services; FAQ knowledge and audience rules. See also [Live sales reports](live-sales-reports.md) for the report-specific behavior and the dated release verification record.
