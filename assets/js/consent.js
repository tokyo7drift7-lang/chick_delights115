/* =========================================================
   Chick Delights - cookie consent.

   Self-hosted and dependency-free: no external script, no
   third-party CMP, no network request.

   The choice is stored in localStorage (not in a cookie) so
   the privacy policy stays true: this site sets no cookies of
   its own. Nothing non-essential runs before consent.

   Public API for future ad code:

     window.ChickDelightsConsent.ads      -> true only after consent
     window.ChickDelightsConsent.has('ads')
     document.addEventListener('consent:change', fn)

   Example gating:
     if (window.ChickDelightsConsent.ads) { loadAds(); }
   ========================================================= */

(function () {
  'use strict';

  var KEY = 'ch_consent_v1';
  var VERSION = 1;

  var root = document.documentElement;
  var banner = document.getElementById('consent');
  var panel = document.getElementById('consentPanel');
  var adsBox = document.getElementById('consentAds');
  var lastFocus = null;
  var current = { ads: false };

  function read() {
    try {
      var raw = window.localStorage.getItem(KEY);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || data.v !== VERSION) return null;
      return data;
    } catch (err) {
      return null;
    }
  }

  function makeState(ads) {
    return { v: VERSION, necessary: true, ads: !!ads, ts: Date.now() };
  }

  function publish(data) {
    current = { ads: !!(data && data.ads) };

    var api = {
      version: VERSION,
      ads: current.ads,
      analytics: false,
      has: function (name) {
        if (name === 'necessary') return true;
        if (name === 'ads' || name === 'advertising') return current.ads;
        return false;
      }
    };

    window.ChickDelightsConsent = api;
    root.setAttribute('data-consent', api.ads ? 'all' : 'essential');

    try {
      document.dispatchEvent(new CustomEvent('consent:change', { detail: api }));
    } catch (err) {
      /* very old browser: no CustomEvent, the global is enough */
    }
  }

  function measure() {
    if (!banner || banner.hidden) {
      root.style.removeProperty('--consent-h');
      return;
    }
    root.style.setProperty('--consent-h', banner.offsetHeight + 'px');
  }

  function open(focus) {
    if (!banner) return;
    lastFocus = document.activeElement;

    /* Always mirror the current choice, so reopening the banner never
       shows a stale checkbox. */
    if (adsBox) adsBox.checked = current.ads;
    if (panel) panel.hidden = true;
    var settingsBtn = banner.querySelector('[data-consent-choice="settings"]');
    if (settingsBtn) settingsBtn.setAttribute('aria-expanded', 'false');

    banner.hidden = false;
    root.classList.add('consent-open');
    measure();
    if (focus) {
      var first = banner.querySelector('[data-consent-choice="all"]');
      if (first && first.focus) {
        try { first.focus(); } catch (err) { /* ignore */ }
      }
    }
  }

  function close() {
    if (!banner) return;
    banner.hidden = true;
    root.classList.remove('consent-open');
    root.style.removeProperty('--consent-h');
    if (panel) panel.hidden = true;
    var settingsBtn = banner.querySelector('[data-consent-choice="settings"]');
    if (settingsBtn) settingsBtn.setAttribute('aria-expanded', 'false');
    if (lastFocus && lastFocus.focus) {
      try { lastFocus.focus(); } catch (err) { /* ignore */ }
    }
  }

  function save(ads) {
    var data = makeState(ads);
    try { window.localStorage.setItem(KEY, JSON.stringify(data)); } catch (err) { /* private mode */ }
    publish(data);
    close();
  }

  /* Always publish a safe default first: ads stay off until the
     visitor explicitly allows them. */
  var stored = read();
  publish(stored || makeState(false));

  if (!banner) return;

  /* Show the banner only when no choice has been stored yet. */
  if (!stored) open(false);

  banner.addEventListener('click', function (e) {
    var node = e.target;
    var btn = node && node.closest ? node.closest('[data-consent-choice]') : null;
    if (!btn) return;

    var choice = btn.getAttribute('data-consent-choice');

    if (choice === 'all') {
      save(true);
      return;
    }

    if (choice === 'essential') {
      save(false);
      return;
    }

    if (choice === 'settings') {
      if (!panel) return;
      panel.hidden = !panel.hidden;
      btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
      measure();
      if (!panel.hidden && adsBox && adsBox.focus) {
        try { adsBox.focus(); } catch (err) { /* ignore */ }
      }
      return;
    }

    if (choice === 'save') {
      save(!!(adsBox && adsBox.checked));
    }
  });

  document.addEventListener('click', function (e) {
    var node = e.target;
    var trigger = node && node.closest ? node.closest('[data-consent-open]') : null;
    if (!trigger) return;
    e.preventDefault();
    open(true);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !banner.hidden) close();
  });

  window.addEventListener('resize', measure);
})();
