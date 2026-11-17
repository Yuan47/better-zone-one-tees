@if($deliveryEstimate)
<div class="notice" data-delivery-estimate>
    <strong>{{ $deliveryEstimate['legacy'] ? 'Current store delivery estimate' : 'Expected delivery' }}: {{ \App\Services\DeliveryEstimates::periodLabel($deliveryEstimate['days']) }} after confirmed full payment.</strong>
    <p>This is an estimate. Timing may vary with packing, courier availability and location.</p>
    @if($deliveryEstimate['pending'])<small>The estimate starts only after full payment is confirmed.</small>@endif
</div>
@endif
