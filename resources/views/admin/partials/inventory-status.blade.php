@php($inventoryStatus = $variant->inventoryStatus())
@if($inventoryStatus !== 'healthy')
<span class="badge inventory-status inventory-status-{{ $inventoryStatus }}">
    <span>{{ $inventoryStatus === 'critical' ? 'Critical low' : 'Alert' }}</span>
    @if($showQuantity ?? true)<span class="inventory-status-quantity">{{ $variant->available() }} left</span>@endif
</span>
@endif
