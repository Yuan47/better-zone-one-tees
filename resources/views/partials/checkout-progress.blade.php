@php($checkoutStage = max(1, min(4, (int) ($checkoutStage ?? 1))))
<ol class="checkout-progress" id="checkout-progress" aria-label="Checkout progress" data-checkout-stage="{{ $checkoutStage }}">
    @foreach(['Delivery details', 'Review total', 'Pay securely', 'Order confirmation'] as $stepLabel)
        <li data-checkout-step="{{ $loop->iteration }}" @if($checkoutStage === $loop->iteration) aria-current="step" @endif @if($checkoutStage > $loop->iteration) data-complete @endif>{{ $stepLabel }}</li>
    @endforeach
</ol>
