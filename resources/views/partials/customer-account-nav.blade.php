<aside class="customer-account-nav" aria-label="My account navigation">
    <div class="customer-account-identity">
        @if($user->avatar_path)
            <img src="{{ asset($user->avatar_path) }}" alt="Your profile photo">
        @else
            <span class="customer-account-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
        @endif
        <div><strong>{{ $user->username ?: $user->name }}</strong><small>{{ $user->name }}</small></div>
    </div>
    <nav>
        <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Overview</a>
        <a href="{{ route('account') }}" @if(request()->routeIs('account')) aria-current="page" @endif>My profile</a>
        <a href="{{ route('account.addresses.index') }}" @if(request()->routeIs('account.addresses.*')) aria-current="page" @endif>My addresses</a>
        <a href="{{ route('account') }}#account-password">Change password</a>
        <a href="{{ route('orders') }}" @if(request()->routeIs('orders')) aria-current="page" @endif>My purchases</a>
        <a href="{{ route('store', ['wishlist' => 1]) }}">Favorites</a>
    </nav>
</aside>
