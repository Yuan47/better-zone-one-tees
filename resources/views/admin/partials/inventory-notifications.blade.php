@if(auth()->user()?->role === 'admin' && isset($inventoryNotifications))
@php($inventoryNotificationTotal = array_sum($inventoryNotifications))
<details class="inventory-notifications" id="inventory-notifications">
    <summary aria-label="Inventory notifications: {{ $inventoryNotificationTotal }} affected {{ $inventoryNotificationTotal === 1 ? 'variant' : 'variants' }}">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        <span>Notifications</span>@if($inventoryNotificationTotal)<strong class="inventory-notification-count">{{ number_format($inventoryNotificationTotal) }}</strong>@endif
    </summary>
    <div class="inventory-notification-panel" role="region" aria-labelledby="inventory-notification-heading">
        <h2 id="inventory-notification-heading">Stock notifications</h2>
        <p class="inventory-notification-scope">{{ number_format($inventoryNotificationTotal) }} affected size/color {{ $inventoryNotificationTotal === 1 ? 'variant' : 'variants' }} · active products</p>
        @if($inventoryNotifications['critical'])
        <div class="inventory-notification-row inventory-warning-critical"><strong>Critical low</strong><span>{{ number_format($inventoryNotifications['critical']) }} {{ $inventoryNotifications['critical'] === 1 ? 'variant' : 'variants' }}</span><p>Critically low available pieces or out of stock.</p></div>
        @endif
        @if($inventoryNotifications['alert'])
        <div class="inventory-notification-row inventory-warning-alert"><strong>Alert</strong><span>{{ number_format($inventoryNotifications['alert']) }} {{ $inventoryNotifications['alert'] === 1 ? 'variant' : 'variants' }}</span><p>Low available pieces. Review restocking needs.</p></div>
        @endif
        @if($inventoryNotificationTotal)
        <a class="text-link inventory-notification-link" href="{{ route('admin.inventory-attention') }}">View affected variants →</a>
        @else
        <p class="inventory-notification-empty">No active stock warnings.</p>
        @endif
        <p class="inventory-notification-note">These are current stock counts, not unread messages. They update when you open or reload a workspace page. Opening this menu does not clear warnings.</p>
    </div>
</details>
@endif
