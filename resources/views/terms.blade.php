@extends('layout')
@section('title', 'Terms and Conditions')
@section('content')
@php($policy = app(\App\Services\ReturnInspections::class)->policy())
<article class="panel policy-page">
    <h1>Terms and Conditions</h1>
    <p class="muted">Version {{ \App\Services\StoreTerms::VERSION }}</p>
    <h2>Your account and order</h2>
    <p>Use an email account you can verify and provide accurate contact and delivery details. Keep your password and verification codes private. Review the product, size, color, quantity, prices and delivery destination before placing an order.</p>
    <h2>Full payment</h2>
    <p>New orders require full payment. Online payments are completed through PayMongo. An order is paid after the payment provider confirms the transaction. Walk-in cash payments are recorded by authorized staff after the full amount is received.</p>
    <p>Keep your invoice reference and payment receipt. If payment is uncertain, open the existing invoice and ask the store to check its status before making another payment.</p>
    <h2>Delivery</h2>
    <p>For Lalamove delivery, provide an 11-digit Philippine mobile number, a complete address and a confirmed map pin at the delivery entrance. Review the delivery quotation included in the invoice. A quotation has an expiry and must be refreshed when the destination changes.</p>
    <p>The store arranges a courier after payment and packing. Courier availability, cancellation charges and claims are subject to Lalamove’s applicable terms. Confirm receipt through My Purchases after receiving your order. Select a star rating. A written review is optional.</p>
    <h2>Returns and refunds</h2>
    <p>The standard store request window is {{ $policy['days'] }} days after confirmed receipt. Submit a request through the fully paid, delivered order with a reason and a clear supporting photo. The owner checks eligibility and the returned clothing before approving a refund. The owner may review an exception to the standard request window.</p>
    <p>Eligible clothing @if($policy['delivery_eligible']) and delivery amounts @endif are reviewed against the original paid invoice and recorded inspection. Provider and courier rules affect the available refund method and processing. A request or approval must progress to a confirmed refund before it is recorded as returned money. Check the refund status on the original order.</p>
    <p>Approved online refunds follow PayMongo’s supported payment-method process and provider verification. Lalamove handles its own delivery charges and cancellation or claim decisions under its terms. Contact the store using the invoice reference for help.</p>
    <h2>Information used to complete your order</h2>
    <p>The store records your account, delivery addresses, orders, payment references, reviews and support requests. Order contact and destination details are used for fulfillment and the configured payment and courier services. Supporting refund photos are private evidence available to authorized store personnel.</p>
    <p>The site requests your device location when you choose “Use my current location.” You can select the delivery pin on the map. Payment-card details are entered on PayMongo’s hosted checkout.</p>
    <h2>Service policies</h2>
    <ul>
        <li><a class="text-link" href="{{ \App\Services\StoreTerms::PAYMONGO_TERMS }}" target="_blank" rel="noopener">PayMongo Terms of Use</a></li>
        <li><a class="text-link" href="{{ \App\Services\StoreTerms::PAYMONGO_REFUNDS }}" target="_blank" rel="noopener">PayMongo refund process</a></li>
        <li><a class="text-link" href="{{ \App\Services\StoreTerms::LALAMOVE_TERMS }}" target="_blank" rel="noopener">Lalamove Philippines Terms and Conditions</a></li>
    </ul>
</article>
@endsection
