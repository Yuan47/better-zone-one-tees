<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#941B0C"><link rel="icon" type="image/png" href="{{ asset('brand/zone-one-tees-logo.png') }}">
<title>@yield('title','Zone One Tee’s') · 2.0</title>
@if(request()->routeIs('checkout'))<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}"><script src="{{ asset('vendor/leaflet/leaflet.js') }}" defer></script>@endif
<link rel="stylesheet" href="{{ asset('shop.css') }}?v={{ filemtime(public_path('shop.css')) }}"><script src="{{ asset('shop.js') }}?v={{ filemtime(public_path('shop.js')) }}" defer></script></head>
<body class="{{ (request()->routeIs('admin.*') || ((request()->routeIs('account*') || request()->routeIs('orders.show')) && auth()->check() && auth()->user()->role!=='customer')) ? 'admin-body' : 'store-body' }}">
<a class="skip-link" href="#main-content">Skip to content</a>
@php($isAdmin=(request()->routeIs('admin.*') || ((request()->routeIs('account*') || request()->routeIs('orders.show')) && auth()->check() && auth()->user()->role!=='customer')))
@if($isAdmin)
<aside class="sidebar" id="workspace-menu"><div class="sidebar-heading"><a class="brand" href="{{ route('admin.dashboard') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo"><span>Zone One Tee’s<small>STORE WORKSPACE</small></span></a><button type="button" class="icon-btn admin-menu-close" id="workspace-menu-close" aria-label="Close workspace menu">×</button></div>
<div class="workspace"><span class="dot"></span> Zone One Tee’s 2.0 <small>{{ config('shop.demo')?'Local demo':'Store workspace' }}</small></div>
<nav class="side-nav" aria-label="Admin navigation">
<span class="nav-caption">YOUR STORE</span>
<a class="{{ request()->routeIs('admin.dashboard')?'active':'' }}" href="{{ route('admin.dashboard') }}"><span>◫</span> Overview</a>
<a class="{{ request()->routeIs('admin.orders')?'active':'' }}" href="{{ route('admin.orders') }}"><span>▤</span> Orders</a>
<a class="{{ request()->routeIs('admin.operations')?'active':'' }}" href="{{ route('admin.operations') }}"><span>◴</span> Orders & delivery</a>
<a class="{{ request()->routeIs('admin.refunds.*')?'active':'' }}" href="{{ route('admin.refunds.index') }}"><span>↶</span> Refund requests</a>
@if(auth()->user()->role==='admin')
<a class="{{ request()->routeIs('admin.products*')?'active':'' }}" href="{{ route('admin.products') }}"><span>◇</span> Products & inventory</a>
<a class="{{ request()->routeIs('admin.customers')?'active':'' }}" href="{{ route('admin.customers') }}"><span>♧</span> Customers</a>
@endif
<span class="nav-caption">INSIGHTS & TOOLS</span><a href="{{ route('admin.dashboard') }}#reports"><span>▥</span> Reports</a><a class="{{ request()->routeIs('admin.forecast')?'active':'' }}" href="{{ route('admin.forecast') }}"><span>✧</span> Sales & Growth Forecast</a>
@if(auth()->user()->role==='admin')<a class="{{ request()->routeIs('admin.activity.*')?'active':'' }}" href="{{ route('admin.activity.index') }}"><span>◷</span> Activity log</a><a class="{{ request()->routeIs('admin.settings*','admin.faqs.*')?'active':'' }}" href="{{ route('admin.settings') }}"><span>⚙</span> Store settings</a>@endif
<a class="{{ request()->routeIs('account*')?'active':'' }}" href="{{ route('account') }}"><span>◉</span> My Account</a><a href="{{ route('store') }}"><span>↗</span> View storefront</a></nav>
<div class="sidebar-bottom"><div class="avatar">@if(auth()->user()->avatar_path)<img src="{{ asset(auth()->user()->avatar_path) }}" alt="Your profile photo">@else{{ substr(auth()->user()->name,0,1) }}@endif</div><div>{{ auth()->user()->name }}<small>{{ ucfirst(auth()->user()->role) }}</small></div><form action="{{ route('logout') }}" method="post">@csrf<button class="text-button" aria-label="Sign out">Sign out ↪</button></form></div></aside>
<div class="menu-backdrop" id="workspace-backdrop" hidden aria-hidden="true"></div>
<div class="admin-main"><header class="admin-top"><div class="workspace-top-title"><button type="button" class="button small admin-menu-toggle" id="workspace-menu-toggle" aria-controls="workspace-menu" aria-expanded="false">☰ Menu</button><span class="workspace-crumb">Workspace <span class="muted">/ @yield('crumb','Overview')</span></span></div><span class="badge">{{ config('shop.paymongo.mode')==='test'?'PayMongo test mode':'PayMongo live mode' }}</span></header>
@else
<div class="announcement">Better basics. Better prices. <span>Wholesale pricing starts at just 6 pieces.</span></div>
<header class="store-header"><a class="brand" href="{{ route('store') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo"><span>Zone One Tee’s<small>EVERYDAY STARTS HERE</small></span></a>
<form class="header-search" action="{{ route('store') }}" role="search"><span aria-hidden="true">⌕</span><input type="search" name="q" aria-label="Search products" placeholder="Search tees, brands, and essentials" value="{{ request('q') }}"><button class="icon-btn" aria-label="Search">→</button></form>
<nav class="header-actions" aria-label="Customer navigation"><a href="{{ auth()->check()?route('store',['wishlist'=>1]):route('login') }}" aria-label="Wishlist">♡ <span>Wishlist</span></a><a href="{{ route('cart') }}">▢ <span>Cart</span><b>{{ array_sum(session('cart',[])) }}</b></a>
@auth<a href="{{ route('orders') }}">My orders</a><details class="account-menu"><summary aria-label="Account menu for {{ auth()->user()->name }}">{{ explode(' ',auth()->user()->name)[0] }} ▾</summary><div><a href="{{ route('account') }}">My Account</a><a class="mobile-account-link" href="{{ route('orders') }}">My orders</a><a class="mobile-account-link" href="{{ route('store',['wishlist'=>1]) }}">Wishlist</a>@if(auth()->user()->role!=='customer')<a href="{{ route('admin.dashboard') }}">Store workspace</a>@endif<form action="{{ route('logout') }}" method="post">@csrf<button class="text-button">Sign out</button></form></div></details>
@else<a class="login-link" href="{{ route('login') }}">Sign in ↗</a>@endauth</nav></header>
<nav class="store-nav" aria-label="Store navigation"><a href="{{ route('store') }}">Shop all</a><a href="{{ route('store') }}#catalog">Our brands</a><a href="{{ route('store') }}#quantity">Buy more, save more</a><span>Made for your everyday.</span></nav>
@endif
@if(config('shop.demo'))<div class="demo-banner {{ $isAdmin?'admin-demo':'' }}">LOCAL PREVIEW · First catalog batch. No live payments or delivery bookings.</div>@endif
<main id="main-content" tabindex="-1" class="{{ $isAdmin?'admin-content':'container' }}">
@if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@yield('content')</main>
@if($isAdmin)</div>@else<footer class="footer"><a class="brand" href="{{ route('store') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo">Zone One Tee’s<span class="muted">2.0</span></a><span>Good clothes. Thoughtful service. Everyday value.</span><a href="{{ route('store') }}#quantity">Quantity pricing</a></footer>@endif
<button id="chat-toggle" class="chat-launcher" aria-controls="chat-panel" aria-expanded="false">✧ <span>{{ $isAdmin?'Staff assistant':'Ask Zone One' }}</span></button>
<section id="chat-panel" class="chat-panel" hidden aria-label="Store assistant"><header><div><b>{{ $isAdmin?'Store assistant':'Hey, welcome to Zone One.' }}</b><small>{{ config('shop.grok.key')?'Powered by Grok':'Basic help · Grok not connected' }}</small></div><button id="chat-close" class="icon-btn" aria-label="Close chat">×</button></header>
<div id="chat-messages" class="chat-messages" role="log" aria-live="polite"><p class="assistant-message">How can I help? Ask about products, quantity pricing, or your order number.</p></div><form id="chat-form" data-url="{{ route('chat') }}" data-side="{{ $isAdmin?'staff':'customer' }}"><label class="sr-only" for="chat-input">Message</label><input id="chat-input" required maxlength="1500" placeholder="Ask a question…"><button aria-label="Send message">↑</button></form><small class="chat-disclosure">AI answers may be inaccurate. Store staff can help resolve questions.</small></section>
</body></html>
