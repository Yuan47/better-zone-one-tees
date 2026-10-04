<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#941B0C"><link rel="icon" type="image/png" href="{{ asset('brand/zone-one-tees-logo.png') }}">
<title>@yield('title','Zone One Tee’s') · 2.0</title><link rel="stylesheet" href="{{ asset('shop.css') }}"><script src="{{ asset('shop.js') }}" defer></script></head>
<body class="{{ request()->routeIs('admin.*') ? 'admin-body' : 'store-body' }}">
@php($isAdmin=request()->routeIs('admin.*'))
@if($isAdmin)
<aside class="sidebar"><a class="brand" href="{{ route('admin.dashboard') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo"><span>Zone One Tee’s<small>STORE WORKSPACE</small></span></a>
<div class="workspace"><span class="dot"></span> Zone One Tee’s 2.0 <small>{{ config('shop.demo')?'Local demo':'Store workspace' }}</small></div>
<nav class="side-nav" aria-label="Admin navigation">
<span class="nav-caption">YOUR STORE</span>
<a class="{{ request()->routeIs('admin.dashboard')?'active':'' }}" href="{{ route('admin.dashboard') }}"><span>◫</span> Overview</a>
<a class="{{ request()->routeIs('admin.orders')?'active':'' }}" href="{{ route('admin.orders') }}"><span>▤</span> Orders</a>
@if(auth()->user()->role==='admin')
<a class="{{ request()->routeIs('admin.products*')?'active':'' }}" href="{{ route('admin.products') }}"><span>◇</span> Products & inventory</a>
<a class="{{ request()->routeIs('admin.customers')?'active':'' }}" href="{{ route('admin.customers') }}"><span>♧</span> Customers</a>
@endif
<span class="nav-caption">INSIGHTS & TOOLS</span><a href="{{ route('admin.dashboard') }}#reports"><span>▥</span> Reports</a><a href="{{ route('admin.dashboard') }}#forecast"><span>✧</span> Demand forecast</a>
@if(auth()->user()->role==='admin')<a class="{{ request()->routeIs('admin.settings')?'active':'' }}" href="{{ route('admin.settings') }}"><span>⚙</span> Store settings</a>@endif
<a href="{{ route('store') }}"><span>↗</span> View storefront</a></nav>
<div class="sidebar-bottom"><div class="avatar">{{ substr(auth()->user()->name,0,1) }}</div><div>{{ auth()->user()->name }}<small>{{ ucfirst(auth()->user()->role) }}</small></div><form action="{{ route('logout') }}" method="post">@csrf<button class="icon-btn" aria-label="Sign out">↪</button></form></div></aside>
<div class="admin-main"><header class="admin-top"><span>Workspace <span class="muted">/ @yield('crumb','Overview')</span></span><span class="badge">{{ config('shop.paymongo.mode')==='test'?'PayMongo test mode':'PayMongo live mode' }}</span></header>
@else
<div class="announcement">Better basics. Better prices. <span>Wholesale pricing starts at just 6 pieces.</span></div>
<header class="store-header"><a class="brand" href="{{ route('store') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo"><span>Zone One Tee’s<small>EVERYDAY STARTS HERE</small></span></a>
<form class="header-search" action="{{ route('store') }}"><span>⌕</span><input type="search" name="q" aria-label="Search products" placeholder="Search tees, brands, and essentials" value="{{ request('q') }}"><button class="icon-btn" aria-label="Search">→</button></form>
<nav class="header-actions" aria-label="Customer navigation"><a href="{{ auth()->check()?route('store',['wishlist'=>1]):route('login') }}" aria-label="Wishlist">♡ <span>Wishlist</span></a><a href="{{ route('cart') }}">▢ <span>Cart</span><b>{{ array_sum(session('cart',[])) }}</b></a>
@auth<a href="{{ route('orders') }}">My orders</a><details class="account-menu"><summary>{{ explode(' ',auth()->user()->name)[0] }} ▾</summary><div>@if(auth()->user()->role!=='customer')<a href="{{ route('admin.dashboard') }}">Store workspace</a>@endif<form action="{{ route('logout') }}" method="post">@csrf<button class="text-button">Sign out</button></form></div></details>
@else<a class="login-link" href="{{ route('login') }}">Sign in ↗</a>@endauth</nav></header>
<nav class="store-nav" aria-label="Store navigation"><a href="{{ route('store') }}">Shop all</a><a href="{{ route('store',['category'=>'Essentials']) }}">Everyday essentials</a><a href="{{ route('store',['category'=>'Polos']) }}">Polos</a><a href="{{ route('store',['category'=>'Activewear']) }}">Activewear</a><a href="{{ route('store') }}#quantity">Buy more, save more</a><span>Made for your everyday.</span></nav>
@endif
@if(config('shop.demo'))<div class="demo-banner {{ $isAdmin?'admin-demo':'' }}">LOCAL DEMO · Sample clothes, prices, and inventory. No live payments or delivery bookings.</div>@endif
<main class="{{ $isAdmin?'admin-content':'container' }}">
@if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@yield('content')</main>
@if($isAdmin)</div>@else<footer class="footer"><a class="brand" href="{{ route('store') }}"><img class="brand-logo" src="{{ asset('brand/zone-one-tees-logo.png') }}" alt="Zone One Tee’s logo">Zone One Tee’s<span class="muted">2.0</span></a><span>Good clothes. Thoughtful service. Everyday value.</span><a href="{{ route('store') }}#quantity">Quantity pricing</a></footer>@endif
<button id="chat-toggle" class="chat-launcher" aria-controls="chat-panel" aria-expanded="false">✧ <span>{{ $isAdmin?'Staff assistant':'Ask Zone One' }}</span></button>
<section id="chat-panel" class="chat-panel" hidden aria-label="Store assistant"><header><div><b>{{ $isAdmin?'Store assistant':'Hey, welcome to Zone One.' }}</b><small>{{ config('shop.grok.key')?'Powered by Grok':'Basic help · Grok not connected' }}</small></div><button id="chat-close" class="icon-btn" aria-label="Close chat">×</button></header>
<div id="chat-messages" class="chat-messages" role="log" aria-live="polite"><p class="assistant-message">How can I help? Ask about products, quantity pricing, or your order number.</p></div><form id="chat-form" data-url="{{ route('chat') }}" data-side="{{ $isAdmin?'staff':'customer' }}"><label class="sr-only" for="chat-input">Message</label><input id="chat-input" required maxlength="1500" placeholder="Ask a question…"><button aria-label="Send message">↑</button></form><small class="chat-disclosure">AI answers may be inaccurate. Store staff can help resolve questions.</small></section>
</body></html>