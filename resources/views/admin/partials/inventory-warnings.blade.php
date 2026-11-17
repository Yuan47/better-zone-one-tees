@if(auth()->check() && in_array(auth()->user()->role, ['admin', 'staff'], true))
<div data-report-region="inventory-warnings" aria-live="polite" aria-atomic="true">
    @if($inventoryWarnings['critical'] || $inventoryWarnings['alert'])
    <section class="inventory-warnings" aria-labelledby="inventory-warning-heading">
        <div class="inventory-warning-heading">
            <h2 id="inventory-warning-heading">Inventory needs attention</h2>
            <a class="text-link" href="{{ route('admin.inventory-attention') }}">View affected variants →</a>
        </div>
        <div class="inventory-warning-grid">
            @if($inventoryWarnings['critical'])
            <div class="inventory-warning inventory-warning-critical">
                <span class="inventory-warning-icon" aria-hidden="true">!</span>
                <div><strong>Critical low · {{ number_format($inventoryWarnings['critical']) }} {{ $inventoryWarnings['critical'] === 1 ? 'variant' : 'variants' }}</strong><p>Available pieces are critically low or out of stock. Review these sizes and colors now.</p></div>
            </div>
            @endif
            @if($inventoryWarnings['alert'])
            <div class="inventory-warning inventory-warning-alert">
                <span class="inventory-warning-icon" aria-hidden="true">!</span>
                <div><strong>Alert · {{ number_format($inventoryWarnings['alert']) }} {{ $inventoryWarnings['alert'] === 1 ? 'variant' : 'variants' }}</strong><p>Available pieces are low. Check these sizes and colors and plan restocking.</p></div>
            </div>
            @endif
        </div>
        <p class="inventory-warning-note">Available stock excludes reserved pieces. These counts cover active products across all dates.</p>
    </section>
    @endif
</div>
@endif
