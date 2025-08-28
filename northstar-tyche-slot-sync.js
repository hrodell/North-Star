/**
 * File: northstar-tyche-slot-sync.js
 * Version: 1.8.0
 * Package: NorthStar Delivery Slots (Storefront overlay)
 * Last Modified: 2025-08-20
 *
 * Developer Summary
 * -----------------
 * GOAL
 *   Keep Tyche/ORDDD’s UI, but make NorthStar the single source of truth.
 *
 * WHAT THIS FILE DOES
 *   1) **Network intercept**:
 *      - Patches `window.fetch` **and** `XMLHttpRequest` to catch calls to
 *        `/wp-json/orddd/v1/delivery_schedule`.
 *      - Instead of letting that request go to ORDDD, we call:
 *          /wp-json/nsds/v1/slots?date=YYYY-MM-DD&type=Delivery
 *        and transform NorthStar’s JSON into the exact shape ORDDD expects:
 *          [{ time_slot, time_slot_i18n, charges: "" }, ...]
 *        We include ONLY windows where `blocked==0 && remaining>0`.
 *
 *   2) **Fallback DOM filter** (belt & suspenders):
 *      - After slots render, we disable/grey anything not allowed by NorthStar.
 *
 *   3) **Audit fields**:
 *      - Writes hidden inputs for the ISO date and the selected window (24h):
 *          _nsds_date   = YYYY-MM-DD
 *          _nsds_window = "HH:MM-HH:MM"
 *
 * NOTES
 *   - Does not alter admin. Safe to roll back by restoring your previous JS file.
 *   - If NSDS REST is localized, we use NSDS.rest and NSDS.nonce.
 *
 * CHANGELOG
 *   1.8.0  NEW: Intercept both fetch and XHR for ORDDD endpoint so blocked/full
 *          slots never appear. Keeps prior DOM filter as fallback.
 *   1.7.0  jQuery ajaxTransport hijack (some builds use fetch instead of jQuery).
 *   1.6.0  Initial overlay with date detection + DOM filter.
 */

(function () {
  'use strict';

  /*───────────────────────────────────────────────────────────────────────────*
   * Utilities & styling
   *───────────────────────────────────────────────────────────────────────────*/
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  (function injectStyle(){
    if (document.getElementById('nsds-slot-style')) return;
    var css = [
      '.nsds-disabled{opacity:.45;pointer-events:none;filter:grayscale(1);} ',
      '.nsds-badge{font-size:11px;padding:2px 6px;margin-left:.4rem;border-radius:10px;display:inline-block;line-height:1.3;} ',
      '.nsds-badge.full{background:#e6e6e6;color:#444;} ',
      '.nsds-badge.blocked{background:#f8d7da;color:#842029;} '
    ].join('');
    var s=document.createElement('style'); s.id='nsds-slot-style'; s.textContent=css; document.head.appendChild(s);
  })();

  function ensureHidden(name, val) {
    var form = $('form.checkout, form.woocommerce-checkout') || document.body;
    var el = $('input[name="'+name+'"]');
    if (!el) { el = document.createElement('input'); el.type='hidden'; el.name=name; form.appendChild(el); }
    if (typeof val === 'string') el.value = val;
    return el;
  }

  function parseQuery(url) {
    var q = {};
    String(url || '').replace(/[?&]([^=#&]+)=([^&#]*)/g, function(_,k,v){ q[decodeURIComponent(k)] = decodeURIComponent(v); });
    return q;
  }

  // Tyche visible/hidden date fields
  function getTycheDateRaw() {
    var vis = $all('input[name^="e_deliverydate"]').find(function (el) { return el.offsetParent!==null && el.value; });
    if (vis && vis.value) return vis.value;
    var anyE = $all('input[name^="e_deliverydate"]').find(function (el) { return el.value; });
    if (anyE && anyE.value) return anyE.value;
    var hid = $all('input[name^="h_deliverydate"]').find(function (el) { return el.value; });
    if (hid && hid.value) return hid.value;
    return null;
  }

  // Normalize many date strings → ISO (YYYY-MM-DD)
  function toISODate(raw) {
    if (!raw) return null;
    raw = String(raw).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    var mDMY = raw.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
    if (mDMY) {
      var dd = String(mDMY[1]).padStart(2,'0'); var mm = String(mDMY[2]).padStart(2,'0');
      return mDMY[3] + '-' + mm + '-' + dd;
    }
    var mMDY = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (mMDY) {
      var mm2 = String(mMDY[1]).padStart(2,'0'); var dd2 = String(mMDY[2]).padStart(2,'0');
      return mMDY[3] + '-' + mm2 + '-' + dd2;
    }
    var d = new Date(raw);
    if (!isNaN(d.getTime())) {
      return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }
    return null;
  }

  // Label ↔ 24h converters
  function to24hWindow(label) {
    if (!label) return null;
    label = String(label).trim().replace(/–|—/g,'-');
    var m24 = label.match(/^(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2})$/);
    if (m24) return m24[1]+':'+m24[2]+'-'+m24[3]+':'+m24[4];
    var m12 = label.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)\s*-\s*(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
    if (m12) {
      function h(hh,mm,ap){ hh=+hh; ap=ap.toUpperCase(); if(ap==='PM'&&hh!==12) hh+=12; if(ap==='AM'&&hh===12) hh=0; return String(hh).padStart(2,'0')+':'+mm; }
      return h(m12[1],m12[2],m12[3])+'-'+h(m12[4],m12[5],m12[6]);
    }
    var mShort = label.match(/^(\d{1,2})\s*-\s*(\d{1,2})\s*(AM|PM)$/i);
    if (mShort) {
      var ap=mShort[3].toUpperCase();
      function hh(h){ h=+h; if(ap==='PM'&&h!==12) h+=12; if(ap==='AM'&&h===12) h=0; return String(h).padStart(2,'0')+':00'; }
      return hh(mShort[1])+'-'+hh(mShort[2]);
    }
    return null;
  }

  function to12hLabel(window24) {
    if (!window24) return '';
    var m = window24.match(/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/);
    if (!m) return '';
    function p(h, m) { h=+h; var ap=h>=12?'PM':'AM'; var hh=h%12; if(hh===0) hh=12; return hh+':'+m+' '+ap; }
    return p(m[1],m[2]) + ' - ' + p(m[3],m[4]);
  }

  function nsdsURL(dateISO) {
    var base = (window.NSDS && window.NSDS.rest) || '/wp-json/nsds/v1/slots';
    return base + '?date=' + encodeURIComponent(dateISO) + '&type=Delivery';
  }

  // Convert NorthStar JSON → ORDDD JSON (filtering blocked/full)
  function nsdsToOrddd(json) {
    var out = [];
    var slots = (json && Array.isArray(json.slots)) ? json.slots : [];
    slots.forEach(function (s) {
      if (!s || !s.window) return;
      var blocked = !!s.blocked;
      var remaining = parseInt(s.remaining || 0, 10) || 0;
      if (blocked || remaining <= 0) return; // exclude
      var ui = to12hLabel(String(s.window)) || String(s.label || s.window);
      out.push({ time_slot: ui, time_slot_i18n: ui, charges: '' });
    });
    return out;
  }

  /*───────────────────────────────────────────────────────────────────────────*
   * NETWORK INTERCEPTS: fetch + XHR
   *───────────────────────────────────────────────────────────────────────────*/

  var ORDDD_RE = /\/wp-json\/orddd\/v1\/delivery_schedule\/?/;

  // FETCH
  (function patchFetch(){
    if (!window.fetch || window.fetch.__nsdsPatched) return;
    var _fetch = window.fetch;
    window.fetch = function(input, init) {
      try {
        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        if (ORDDD_RE.test(url)) {
          var q = parseQuery(url);
          var iso = toISODate(q.date || getTycheDateRaw()) || '';
          if (!iso) return Promise.resolve(new Response('[]', {status:200, headers:{'Content-Type':'application/json'}}));
          var headers = (window.NSDS && NSDS.nonce) ? {'X-WP-Nonce': NSDS.nonce} : {};
          return _fetch(nsdsURL(iso), { credentials:'same-origin', headers: headers })
            .then(function(r){ return r.ok ? r.json() : []; })
            .then(function(json){
              var out = nsdsToOrddd(json);
              var body = JSON.stringify(out);
              return new Response(body, { status:200, headers:{'Content-Type':'application/json'} });
            })
            .catch(function(){ return new Response('[]', {status:200, headers:{'Content-Type':'application/json'}}); });
        }
      } catch(e) {}
      return _fetch(input, init);
    };
    window.fetch.__nsdsPatched = true;
  })();

  // XHR
  (function patchXHR(){
    if (!window.XMLHttpRequest || window.XMLHttpRequest.__nsdsPatched) return;

    var OrigXHR = window.XMLHttpRequest;
    function NSDS_XHR() {
      var xhr = new OrigXHR();
      var _open = xhr.open;
      var _send = xhr.send;

      xhr.__nsds = { hijack:false, method:'GET', url:'' };

      xhr.open = function(method, url) {
        xhr.__nsds.method = method ? String(method).toUpperCase() : 'GET';
        xhr.__nsds.url = url || '';
        xhr.__nsds.hijack = ORDDD_RE.test(xhr.__nsds.url);
        return _open.apply(xhr, arguments);
      };

      xhr.send = function() {
        if (!xhr.__nsds.hijack) return _send.apply(xhr, arguments);

        // Prevent real network call; synthesize response from NSDS
        try {
          var q = parseQuery(xhr.__nsds.url);
          var iso = toISODate(q.date || getTycheDateRaw()) || '';
          var headers = (window.NSDS && NSDS.nonce) ? {'X-WP-Nonce': NSDS.nonce} : {};

          fetch(nsdsURL(iso), { credentials:'same-origin', headers: headers })
            .then(function(r){ return r.ok ? r.json() : []; })
            .then(function(json){
              var out = nsdsToOrddd(json);
              var body = JSON.stringify(out);

              // Populate XHR object to mimic a successful response
              Object.defineProperty(xhr, 'readyState', { value: 4, configurable: true });
              Object.defineProperty(xhr, 'status', { value: 200, configurable: true });
              Object.defineProperty(xhr, 'responseText', { value: body, configurable: true });
              Object.defineProperty(xhr, 'response', { value: body, configurable: true });

              if (typeof xhr.onreadystatechange === 'function') xhr.onreadystatechange();
              if (typeof xhr.onload === 'function') xhr.onload();
            })
            .catch(function(){
              var body = '[]';
              Object.defineProperty(xhr, 'readyState', { value: 4, configurable: true });
              Object.defineProperty(xhr, 'status', { value: 200, configurable: true });
              Object.defineProperty(xhr, 'responseText', { value: body, configurable: true });
              Object.defineProperty(xhr, 'response', { value: body, configurable: true });
              if (typeof xhr.onreadystatechange === 'function') xhr.onreadystatechange();
              if (typeof xhr.onload === 'function') xhr.onload();
            });
        } catch (e) {
          // Fallback: act as empty success
          var body = '[]';
          Object.defineProperty(xhr, 'readyState', { value: 4, configurable: true });
          Object.defineProperty(xhr, 'status', { value: 200, configurable: true });
          Object.defineProperty(xhr, 'responseText', { value: body, configurable: true });
          Object.defineProperty(xhr, 'response', { value: body, configurable: true });
          if (typeof xhr.onreadystatechange === 'function') xhr.onreadystatechange();
          if (typeof xhr.onload === 'function') xhr.onload();
        }
      };

      return xhr;
    }

    NSDS_XHR.prototype = OrigXHR.prototype;
    window.XMLHttpRequest = NSDS_XHR;
    window.XMLHttpRequest.__nsdsPatched = true;
  })();

  /*───────────────────────────────────────────────────────────────────────────*
   * Fallback: DOM filter against NSDS allow-map
   *───────────────────────────────────────────────────────────────────────────*/
  var allowed = new Map(); // "HH:MM-HH:MM" -> { blocked, remaining }
  var lastISO = null;

  function findSlotNodes() {
    var btns = $all('.orddd-time-slots li, .orddd-timeslot, .orddd-slot-btn, .tyche-time-slot, .ui-timepicker-list li, .ui-timepicker-viewport li');
    var radios = $all('input[name="orddd_time_slot"], input[name="orddd_time_slot_0"]');
    return Array.from(new Set(btns.concat(radios)));
  }

  function disableNode(node, reason) {
    node.classList.add('nsds-disabled');
    node.setAttribute('aria-disabled','true');
    node.style.pointerEvents='none';
    node.style.opacity='0.45';
    if (!node.dataset.nsdsBadged) {
      var b=document.createElement('span');
      b.className='nsds-badge ' + (reason || 'blocked');
      b.textContent = (reason==='full'?'Full':'Unavailable');
      node.appendChild(b);
      node.dataset.nsdsBadged='1';
    }
    node.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); }, true);
    if (node.tagName==='INPUT') node.disabled = true;
  }

  function enableNode(node) {
    node.classList.remove('nsds-disabled');
    node.removeAttribute('aria-disabled');
    node.style.pointerEvents='';
    node.style.opacity='';
    if (node.dataset.nsdsBadged) {
      var b=node.querySelector('.nsds-badge'); if (b) b.remove();
      delete node.dataset.nsdsBadged;
    }
    if (node.tagName==='INPUT') node.disabled = false;
  }

  function applyFilter() {
    var nodes = findSlotNodes();
    if (!nodes.length) return;

    nodes.forEach(function(n){
      var txt = (n.innerText || n.textContent || '').trim();
      if (!txt && n.tagName==='INPUT') {
        var lab = $('label[for="'+n.id+'"]');
        if (lab) txt = (lab.innerText || lab.textContent || '').trim();
      }
      var w24 = to24hWindow(txt);
      var info = w24 ? allowed.get(w24) : null;
      var ok = !!info && !info.blocked && info.remaining > 0;
      if (ok) enableNode(n);
      else disableNode(n, info ? (info.blocked ? 'blocked' : 'full') : 'blocked');
    });

    ensureHidden('_nsds_date', lastISO || '');
  }

  function fetchAllowed(iso, done) {
    fetch(nsdsURL(iso), {
      credentials:'same-origin',
      headers: (window.NSDS && NSDS.nonce) ? {'X-WP-Nonce': NSDS.nonce} : {}
    })
      .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
      .then(function(json){
        allowed.clear();
        var list = (json && Array.isArray(json.slots)) ? json.slots : [];
        list.forEach(function(s){
          if (!s || !s.window) return;
          allowed.set(String(s.window), {
            blocked: !!s.blocked,
            remaining: parseInt(s.remaining || 0, 10) || 0
          });
        });
        done && done(true);
      })
      .catch(function(){ done && done(false); });
  }

  function syncNow() {
    var raw = getTycheDateRaw();
    var iso = toISODate(raw);
    if (!iso) return;
    ensureHidden('_nsds_date', iso);

    if (iso !== lastISO || allowed.size === 0) {
      lastISO = iso;
      fetchAllowed(iso, function(){ applyFilter(); });
    } else {
      applyFilter();
    }
  }

  // Capture chosen slot → _nsds_window
  document.addEventListener('click', function(e){
    var t = e.target && e.target.closest && e.target.closest(
      '.orddd-time-slots li, .orddd-timeslot, .orddd-slot-btn, .tyche-time-slot, .ui-timepicker-list li, .ui-timepicker-viewport li, label, input[name="orddd_time_slot"], input[name="orddd_time_slot_0"]'
    );
    if (!t) return;
    var txt = (t.innerText || t.textContent || '').trim();
    if (!txt && t.tagName==='LABEL') {
      var forId=t.getAttribute('for'); if (forId) { var lab=$('label[for="'+forId+'"]'); if (lab) txt=(lab.innerText||lab.textContent||'').trim(); }
    }
    var w24 = to24hWindow(txt);
    if (w24) ensureHidden('_nsds_window', w24);
  }, true);

  // Respond to date changes
  document.addEventListener('change', function(e){
    var nm = (e.target && e.target.name ? e.target.name.toLowerCase() : '');
    if (/(^|_)deliverydate(_|$)/.test(nm)) { ensureHidden('_nsds_window',''); syncNow(); }
  });

  // Observe Tyche re-renders and kick sync
  var mo = new MutationObserver(function(){ clearTimeout(mo._t); mo._t = setTimeout(syncNow, 60); });
  mo.observe(document.documentElement, { childList:true, subtree:true });

  // Initial sync
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncNow); else syncNow();

})();
