@if(in_array(auth()->user()?->role, ['staff','admin']) && $order->delivery_method === 'lalamove' && config('shop.lalamove.mode') === 'sandbox')
<details class="panel delivery-demo-note">
    <summary>Lalamove demonstration steps · Sandbox only</summary>
    <ol>
        <li>Check the pickup entrance and TEST contact number. In the Philippines Lalamove Partner Portal, select <strong>Sandbox</strong> and use virtual test credit.</li>
        <li>Use a fully paid TEST order with a complete address and confirmed delivery pin. Pack it and mark <strong>Ready for delivery</strong> with a reason.</li>
        <li>Book the sandbox delivery once. Open that booking in the Sandbox portal to simulate rider progress, then use <strong>Check delivery with Lalamove</strong> here.</li>
        <li>If the booking is uncertain, check or recover the original before rebooking. If its fee rises, ask the owner to approve covering the difference.</li>
        <li>After verified courier completion, the customer selects a star rating and confirms receipt. A written review is optional. The order stays <strong>To Ship</strong> until that confirmation.</li>
    </ol>
    <p class="mini-note">This is a simulation. Do not request a real courier, use real payment funds or claim a physical delivery occurred. The customer invoice keeps its original delivery fee.</p>
</details>
@endif
