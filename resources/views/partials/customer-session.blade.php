@if(auth()->check() && auth()->user()->role === 'customer')
<dialog id="customer-session-warning" class="customer-session-warning" aria-labelledby="customer-session-title" aria-describedby="customer-session-description"
    data-activity-url="{{ route('customer.session.activity') }}" data-status-url="{{ route('customer.session.status') }}" data-login-url="{{ route('login') }}"
    data-expires-at="{{ request()->attributes->get('customer_session_expires_at', session('customer_idle.expires_at')) }}" data-server-time="{{ now()->timestamp }}"
    data-sync-key="{{ hash('sha256', (string) session('customer_idle.id')) }}">
    <h2 id="customer-session-title">Still shopping?</h2>
    <p id="customer-session-description">For your security, you will be signed out after 15 minutes without activity. Your session expires in less than one minute.</p>
    <p id="customer-session-message" role="status" aria-live="polite"></p>
    <div class="customer-session-actions"><button class="button primary" id="customer-session-stay" type="button">Stay signed in</button><form id="customer-session-sign-out" method="post" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit">Sign out</button></form><a id="customer-session-sign-in" class="text-link" href="{{ route('login') }}?session_expired=1" hidden>Sign in again</a></div>
</dialog>
<script src="{{ asset('customer-session.js') }}?v={{ filemtime(public_path('customer-session.js')) }}" defer></script>
@endif
