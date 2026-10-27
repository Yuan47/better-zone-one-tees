<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#941B0C"><link rel="icon" type="image/png" href="{{ asset('brand/zone-one-tees-logo.png') }}">
<title>@yield('title','Zone One Tee’s') · 2.0</title>
@if(request()->routeIs('checkout'))<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}"><script src="{{ asset('vendor/leaflet/leaflet.js') }}" defer></script>@endif
<link rel="stylesheet" href="{{ asset('shop.css') }}?v={{ filemtime(public_path('shop.css')) }}"><script src="{{ asset('shop.js') }}?v={{ filemtime(public_path('shop.js')) }}" defer></script>
<link rel="stylesheet" href="{{ asset('password-visibility.css') }}?v={{ filemtime(public_path('password-visibility.css')) }}"><script src="{{ asset('password-visibility.js') }}?v={{ filemtime(public_path('password-visibility.js')) }}" defer></script>
@if(auth()->check() && auth()->user()->role === 'customer')<link rel="stylesheet" href="{{ asset('customer-session.css') }}?v={{ filemtime(public_path('customer-session.css')) }}">@endif
</head>
@php($isAdmin=\App\Services\AccountNavigation::workspace(auth()->user()) && ((request()->routeIs('admin.*') && !request()->routeIs('admin.login','admin.login.submit')) || request()->routeIs('account*','orders.show')))
<body class="{{ $isAdmin ? 'admin-body' : 'store-body' }}{{ request()->routeIs('verification.*', 'password.*', 'admin.staff.create', 'admin.staff.show') ? ' verification-page' : '' }}{{ request()->routeIs('admin.staff.*') ? ' staff-setup-page' : '' }}">
<a class="skip-link" href="#main-content">Skip to content</a>
@if($isAdmin)
<aside class="sidebar" id="workspace-menu"><div class="sidebar-heading"><a class="brand" href="{{ route('admin.dashboard') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo"><span>Zone One Tee’s<small>STORE WORKSPACE</small></span></a><button type="button" class="icon-btn admin-menu-close" id="workspace-menu-close" aria-label="Close workspace menu">×</button></div>
<div class="workspace"><span class="dot"></span> Zone One Tee’s 2.0 <small>{{ config('shop.demo')?'Local demo':'Store workspace' }}</small></div>
<nav class="side-nav" aria-label="Admin navigation">
<span class="nav-caption">YOUR STORE</span>
<a class="{{ request()->routeIs('admin.dashboard')?'active':'' }}" href="{{ route('admin.dashboard') }}"><span>◫</span> Overview</a>
<a class="{{ request()->routeIs('admin.orders')?'active':'' }}" href="{{ route('admin.orders') }}"><span>▤</span> Orders</a>
<a class="{{ request()->routeIs('admin.walk-in.*')?'active':'' }}" href="{{ route('admin.walk-in.create') }}"><span>＋</span> Walk-in checkout</a>
<a class="{{ request()->routeIs('admin.operations')?'active':'' }}" href="{{ route('admin.operations') }}"><span aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" style="display:block"><circle cx="10" cy="3.5" r="1.8"/><circle cx="5" cy="18" r="3"/><circle cx="19" cy="18" r="3"/><path d="m9 7-3 5h6l2 6M9 7l4 3h3M5 18l3-6h7l4 6H5M17 7h3l1 5"/></svg></span> Orders & delivery</a>
<a class="{{ request()->routeIs('admin.refunds.*')?'active':'' }}" href="{{ route('admin.refunds.index') }}"><span>↶</span> Refund requests</a>
@if(auth()->user()->role==='admin')
<a class="{{ request()->routeIs('admin.products*')?'active':'' }}" href="{{ route('admin.products') }}"><span>◇</span> Products & inventory</a>
<a class="{{ request()->routeIs('admin.customers')?'active':'' }}" href="{{ route('admin.customers') }}"><span aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" style="display:block"><circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-3.5-5.5"/></svg></span> Customers</a>
<a class="{{ request()->routeIs('admin.staff.*')?'active':'' }}" href="{{ route('admin.staff.index') }}"><span aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" style="display:block"><circle cx="12" cy="5" r="3"/><path d="M4 22v-5a8 8 0 0 1 16 0v5M10 11l2 3 2-3M12 14l-2 6 2 2 2-2-2-6M7 11l3 4M17 11l-3 4"/></svg></span> Staff accounts</a>
@endif
<span class="nav-caption">INSIGHTS & TOOLS</span><a href="{{ route('admin.dashboard') }}#reports"><span>▥</span> Reports</a><a class="{{ request()->routeIs('admin.forecast')?'active':'' }}" href="{{ route('admin.forecast') }}"><span aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" style="display:block"><path d="M3 3v18h18M7 16l5-6 4 3 5-8M17 5h4v4"/></svg></span> Sales & Growth Forecast</a>
@if(auth()->user()->role==='admin')<a class="{{ request()->routeIs('admin.activity.*')?'active':'' }}" href="{{ route('admin.activity.index') }}"><span aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" style="display:block"><rect x="3" y="2" width="18" height="20" rx="2"/><path d="M7 7h.01M11 7h6M7 12h.01M11 12h6M7 17h.01M11 17h6"/></svg></span> Activity log</a><a class="{{ request()->routeIs('admin.settings*','admin.faqs.*')?'active':'' }}" href="{{ route('admin.settings') }}"><span>⚙</span> Store settings</a>@endif
<a class="{{ request()->routeIs('account*')?'active':'' }}" href="{{ route('account') }}"><span>◉</span> My Account</a><a href="{{ route('store') }}"><span>↗</span> View storefront</a></nav>
<div class="sidebar-bottom"><div class="avatar">@if(auth()->user()->avatar_path)<img src="{{ asset(auth()->user()->avatar_path) }}" alt="Your profile photo">@else{{ substr(auth()->user()->name,0,1) }}@endif</div><div>{{ auth()->user()->name }}<small>{{ ucfirst(auth()->user()->role) }}</small></div><form action="{{ route('logout') }}" method="post">@csrf<button class="text-button" aria-label="Sign out">Sign out ↪</button></form></div></aside>
<div class="menu-backdrop" id="workspace-backdrop" hidden aria-hidden="true"></div>
<div class="admin-main"><header class="admin-top"><div class="workspace-top-title"><button type="button" class="button small admin-menu-toggle" id="workspace-menu-toggle" aria-controls="workspace-menu" aria-expanded="false">☰ Menu</button><span class="workspace-crumb">Workspace <span class="muted">/ @yield('crumb','Overview')</span></span></div><span class="badge">{{ config('shop.paymongo.mode')==='test'?'PayMongo test mode':'PayMongo live mode' }}</span></header>
@else
<div class="announcement">Better basics. Better prices. <span>Wholesale pricing starts at just 6 pieces.</span></div>
<header class="store-header"><a class="brand" href="{{ route('store') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo"><span>Zone One Tee’s<small>EVERYDAY STARTS HERE</small></span></a>
<form class="header-search" action="{{ route('store') }}" role="search"><span aria-hidden="true">⌕</span><input type="search" name="q" aria-label="Search products" placeholder="Search tees, brands, and essentials" value="{{ request('q') }}"><button class="icon-btn" aria-label="Search">→</button></form>
<nav class="header-actions" aria-label="Customer navigation"><a href="{{ auth()->check()?route('store',['wishlist'=>1]):route('login') }}" aria-label="Wishlist">♡ <span>Wishlist</span></a><a href="{{ route('cart') }}">▢ <span>Cart</span><b>{{ array_sum(session('cart',[])) }}</b></a>
@auth
@if(auth()->user()->role==='customer')
<a href="{{ route('dashboard') }}">My dashboard</a>
@endif
<a href="{{ route('orders') }}">My orders</a><details class="account-menu"><summary aria-label="Account menu for {{ auth()->user()->name }}">{{ explode(' ',auth()->user()->name)[0] }} ▾</summary><div>
@if(auth()->user()->role==='customer')
<a href="{{ route('dashboard') }}">My dashboard</a>
@endif
<a href="{{ route('account') }}">My Account</a><a href="{{ route('orders') }}">My orders · History</a><a href="{{ route('store',['wishlist'=>1]) }}">Favorites / wishlist</a>
@if(auth()->user()->role!=='customer')
<a href="{{ route('admin.dashboard') }}">Store workspace</a>
@endif
<form action="{{ route('logout') }}" method="post">@csrf<button class="text-button">Sign out</button></form></div></details>
@else<a class="login-link" href="{{ route('login') }}">Sign in ↗</a>@endauth</nav></header>
<nav class="store-nav" aria-label="Store navigation"><a href="{{ route('store') }}">Shop all</a><a href="{{ route('store') }}#catalog">Our brands</a><a href="{{ route('store') }}#quantity">Buy more, save more</a><span>Made for your everyday.</span></nav>
@endif
@if(config('shop.demo'))<div class="demo-banner {{ $isAdmin?'admin-demo':'' }}">LOCAL PREVIEW · Permanent product catalog. No live payments or delivery bookings.</div>@endif
<main id="main-content" tabindex="-1" class="{{ $isAdmin?'admin-content':'container' }}">
@if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if(!request()->routeIs('verification.*'))
@if(auth()->check() && config('auth.email_verification.required') && !auth()->user()->email_verified_at)<div class="notice" role="status">Verify your email to place orders and use your account. <a class="text-link" href="{{ route('verification.notice') }}">Verify email →</a></div>@else
@include('partials.payment-alert')
@endif
@endif
@yield('content')</main>
@if($isAdmin)</div>@else<footer class="footer"><a class="brand" href="{{ route('store') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo">Zone One Tee’s<span class="muted">2.0</span></a><span>Good clothes. Thoughtful service. Everyday value.</span><a href="{{ route('store') }}#quantity">Quantity pricing</a></footer>@endif
<button id="chat-toggle" class="chat-launcher" aria-controls="chat-panel" aria-expanded="false">✧ <span>{{ $isAdmin?'Staff assistant':'Ask Zone One' }}</span></button>
<section id="chat-panel" class="chat-panel" hidden aria-label="Store assistant"><header><div><b>{{ $isAdmin?'Store assistant':'Hey, welcome to Zone One.' }}</b><small>{{ (filled(config('shop.groq.key')) && filled(config('shop.groq.model')))?'Powered by GroqCloud':'Basic help · GroqCloud not connected' }}</small></div><button id="chat-close" class="icon-btn" aria-label="Close chat">×</button></header>
<div id="chat-messages" class="chat-messages" role="log" aria-live="polite"><p class="assistant-message">{{ $isAdmin?'Ask how to use your workspace. I can explain the steps allowed for your role.':'Need a hand? Ask how to create an account, shop, or find your own orders.' }}</p></div>
@php($chatSuggestions=$isAdmin ? (auth()->user()->role==='admin' ? ['Staff setup'=>'How does the owner activate a staff account with its setup code?','Refund inspection'=>'How does the owner inspect a return and process its refund?','Forecast help'=>'How do staff understand the forecast formulas?'] : ['Walk-in sale'=>'How do staff create a walk-in sale?','Packing help'=>'How do staff pack an order and mark it ready?','Forecast help'=>'How do staff understand the forecast formulas?']) : ['Create an account'=>'How do I create a customer account?','Cart & checkout'=>'How do I add items to my cart and check out?','My orders'=>'How do I open my order history and track an order?'])
<div class="chat-suggestions" aria-label="Quick help questions">@foreach($chatSuggestions as $label=>$question)<button type="button" class="chat-suggestion" data-question="{{ $question }}">{{ $label }}</button>@endforeach</div>
<form id="chat-form" data-url="{{ route('chat') }}" data-side="{{ $isAdmin?'staff':'customer' }}"><label class="sr-only" for="chat-input">Message</label><input id="chat-input" required maxlength="1500" placeholder="Ask a question…"><button aria-label="Send message">↑</button></form><small class="chat-disclosure">Never share passwords, verification codes, or recovery links here. Store staff can help resolve questions.</small></section>
@include('partials.customer-session')
</body></html>
