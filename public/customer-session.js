(function () {
  'use strict';
  var panel = document.getElementById('customer-session-warning');
  if (!panel) return;
  var stay = document.getElementById('customer-session-stay');
  var message = document.getElementById('customer-session-message');
  var title = document.getElementById('customer-session-title');
  var description = document.getElementById('customer-session-description');
  var signIn = document.getElementById('customer-session-sign-in');
  var signOut = document.getElementById('customer-session-sign-out');
  var token = document.querySelector('meta[name="csrf-token"]');
  var storageKey = 'zone-one-idle:' + panel.dataset.syncKey;
  var deadline = Date.now() + (Number(panel.dataset.expiresAt) - Number(panel.dataset.serverTime)) * 1000;
  var lastSent = Date.now();
  var lastAction = 0;
  var warningTimer, expiryTimer, activityTimer;
  var pending = false, busy = false, stopped = false, previousFocus;
  var channel;
  try { if (window.BroadcastChannel) channel = new BroadcastChannel(storageKey); } catch (_) {}

  function broadcast(value) {
    try { localStorage.setItem(storageKey, JSON.stringify(value)); } catch (_) {}
    if (channel) channel.postMessage(value);
  }
  function openWarning() {
    if (stopped || panel.open) return;
    previousFocus = document.activeElement;
    if (typeof panel.showModal === 'function') panel.showModal();
    else panel.setAttribute('open', '');
    stay.focus();
  }
  function closeWarning() {
    if (!panel.open) return;
    if (typeof panel.close === 'function') panel.close();
    else panel.removeAttribute('open');
    if (previousFocus && previousFocus.isConnected) previousFocus.focus();
  }
  function signedOut() {
    if (stopped) return;
    stopped = true;
    clearTimeout(warningTimer); clearTimeout(expiryTimer); clearTimeout(activityTimer);
    broadcast({expired: true});
    window.location.replace(panel.dataset.loginUrl + '?session_expired=1');
  }
  function unavailable(text) {
    openWarning();
    title.textContent = 'Check your session';
    description.textContent = 'We could not confirm your session. Try again or choose Sign out.';
    message.textContent = text || 'Your session could not be checked. Please try again.';
    if (Date.now() >= deadline) {
      title.textContent = 'Please sign in again';
      description.textContent = 'Your 15-minute activity window has ended. Sign in again before continuing.';
      stay.hidden = true;
      if (signOut) signOut.hidden = true;
      if (signIn) signIn.hidden = false;
    }
  }
  function schedule() {
    clearTimeout(warningTimer); clearTimeout(expiryTimer);
    if (stopped) return;
    var remaining = deadline - Date.now();
    if (remaining <= 0) { check(false); return; }
    if (remaining <= 60000) openWarning();
    else { closeWarning(); warningTimer = setTimeout(openWarning, remaining - 60000); }
    expiryTimer = setTimeout(function () { check(false); }, remaining + 50);
  }
  function accept(data, share) {
    if (stopped || !Number.isFinite(data.expires_at) || !Number.isFinite(data.server_time)) return;
    deadline = Date.now() + Math.max(0, data.expires_at - data.server_time) * 1000;
    title.textContent = 'Still shopping?';
    description.textContent = 'For your security, you will be signed out after 15 minutes without activity. Your session expires in less than one minute.';
    stay.hidden = false;
    if (signOut) signOut.hidden = false;
    if (signIn) signIn.hidden = true;
    message.textContent = '';
    if (share) broadcast({deadline: deadline});
    schedule();
  }
  function receive(value) {
    if (!value || stopped) return;
    if (value.expired) { signedOut(); return; }
    if (Number.isFinite(value.deadline) && value.deadline > deadline) {
      deadline = value.deadline;
      schedule();
    }
  }
  async function check(activity) {
    if (busy || stopped) return;
    var idleSeconds = Math.max(0, Math.floor((Date.now() - lastAction) / 1000));
    if (activity && (document.hidden || idleSeconds > 60)) return;
    busy = true;
    if (activity) { pending = false; lastSent = Date.now(); }
    stay.disabled = activity;
    var timeout, controller = window.AbortController ? new AbortController() : null;
    try {
      var request = fetch(activity ? panel.dataset.activityUrl : panel.dataset.statusUrl, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token ? token.content : ''},
        body: JSON.stringify(activity ? {idle_seconds: idleSeconds} : {}), signal: controller ? controller.signal : undefined
      });
      var response = await Promise.race([request, new Promise(function (_, reject) {
        timeout = setTimeout(function () {
          if (controller) controller.abort();
          reject(new Error('Your session check timed out. Please try again.'));
        }, 10000);
      })]);
      if (response.status === 401 || response.status === 419) { signedOut(); return; }
      var data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Your session could not be checked. Try again or sign in again.');
      accept(data, true);
    } catch (error) { unavailable(error.message); }
    finally {
      clearTimeout(timeout); busy = false; stay.disabled = false;
      // A trusted gesture may have arrived while a read-only status check was pending.
      flushActivity();
    }
  }
  function flushActivity() {
    clearTimeout(activityTimer);
    if (!pending || stopped) return;
    var wait = Math.max(0, 60000 - (Date.now() - lastSent));
    if (wait > 0) { activityTimer = setTimeout(flushActivity, wait); return; }
    check(true);
  }
  function activity(event) {
    if (!event.isTrusted || document.hidden || stopped || event.target === stay) return;
    lastAction = Date.now(); pending = true;
    flushActivity();
  }
  ['pointerdown', 'keydown', 'input', 'change', 'wheel', 'touchmove'].forEach(function (name) {
    document.addEventListener(name, activity, {passive: true, capture: true});
  });
  stay.addEventListener('click', function (event) {
    if (!event.isTrusted || stopped) return;
    lastAction = Date.now(); pending = true;
    if (Date.now() - lastSent >= 60000) check(true);
    else { flushActivity(); if (deadline - Date.now() > 60000) closeWarning(); }
  });
  panel.addEventListener('cancel', function (event) { event.preventDefault(); });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && !stopped && deadline - Date.now() <= 60000) check(false);
  });
  window.addEventListener('storage', function (event) {
    if (event.key !== storageKey || !event.newValue) return;
    try { receive(JSON.parse(event.newValue)); } catch (_) {}
  });
  if (channel) channel.onmessage = function (event) { receive(event.data); };
  // A second tab may have acknowledged activity after this page started loading.
  try { receive(JSON.parse(localStorage.getItem(storageKey))); } catch (_) {}
  broadcast({deadline: deadline});
  schedule();
}());
