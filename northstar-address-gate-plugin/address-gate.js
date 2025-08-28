/**
 * Address Gate Front-End Controller
 * A-fixes: unified messages, typo correction, role="alert", no hard-coded fallbacks.
 */
(function(){
    if (window.NS_ADDR_GATE_LOADED) return;
    window.NS_ADDR_GATE_LOADED = true;

    var SOFT_COOKIE  = 'ns_addr_zip_pref';
    var TOKEN_COOKIE = 'ns_addr_token';
    var overlayId    = 'ns-addr-overlay';
    var bannerId     = 'ns-addr-soft-banner';
    var reentryGuard = false;
    var lastForm     = null;
    var lastTriggerEl = null;

    function hasToken(){ return document.cookie.indexOf(TOKEN_COOKIE + '=') !== -1; }

    function getCookie(name){
        if (!name || !document || !document.cookie) return '';
        var parts = document.cookie.split('; ');
        for (var i=0;i<parts.length;i++){
            var p = parts[i], eq = p.indexOf('=');
            if (eq === -1) continue;
            var key = decodeURIComponent(p.slice(0,eq));
            if (key === name) return decodeURIComponent(p.slice(eq+1));
        }
        return '';
    }

    function setSoftCookie(data){
        try {
            var v = encodeURIComponent(JSON.stringify(data||{}));
            document.cookie = SOFT_COOKIE + '=' + v + '; path=/; samesite=Lax; max-age=' + (60*60*24*7);
        } catch(e){}
    }

    function isHomeLike(){
        var bd=document.body; if(!bd) return false;
        var cl=bd.classList;
        return cl.contains('home') || cl.contains('front-page') || cl.contains('page-template-front-page');
    }
    function isCartLike(){
        var bd=document.body; if(!bd) return false;
        var cl=bd.classList;
        return cl.contains('woocommerce-cart') || /\/cart(\/|$)/i.test(location.pathname);
    }
    function isCheckoutLike(){
        var bd=document.body; if(!bd) return false;
        var cl=bd.classList;
        return cl.contains('woocommerce-checkout') || /\/checkout(\/|$)/i.test(location.pathname);
    }

    function softBannerEligible(){
        if (!(window.nsAddrAjax && nsAddrAjax.enable_soft)) return false;
        if (hasToken()) return false;
        if (nsAddrAjax.soft_exclude_home && isHomeLike()) return false;
        if (nsAddrAjax.soft_exclude_cart && isCartLike()) return false;
        if (isCheckoutLike()) return false;
        if (document.body && document.body.classList.contains('single-product')) return false;
        return true;
    }

    function injectSoftStyles(){
        if (document.getElementById('ns-addr-soft-css')) return;
        var css = `
#${bannerId}{
  position:fixed; z-index:10050; left:50%; transform:translateX(-50%);
  background:#fff; border:1px solid #dbe6ee; border-radius:16px;
  box-shadow:0 12px 30px rgba(44,62,80,0.14);
  width:min(760px, calc(100vw - 32px));
  padding:14px 16px; display:grid; row-gap:10px;
}
#${bannerId} .ns-addr-controls{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
#${bannerId} .ns-zip-input{
  flex:1 1 180px; min-width:140px; max-width:240px;
  height:42px; padding:10px 14px; border:1px solid #bcd3df; border-radius:10px;
  font-weight:700; text-align:center; letter-spacing:0.5px; color:#173e53; background:#fff; outline:none;
}
#${bannerId} .ns-zip-input:focus{ border-color:#88c0d9; box-shadow:0 0 0 3px rgba(33,140,93,0.12); }
#${bannerId} .ns-zip-check{
  height:42px; padding:0 16px; border:none; border-radius:10px;
  background:#218c5d; color:#fff; font-weight:800; cursor:pointer; letter-spacing:0.3px;
}
#${bannerId} .ns-zip-check:hover{ background:#1b724c; }
#${bannerId} .ns-zip-dismiss{
  height:42px; padding:0 10px; border:none; background:transparent; cursor:pointer;
  color:#2f4a59; font-weight:700; text-transform:uppercase; letter-spacing:1px;
}
#${bannerId} .ns-zip-dismiss:hover{ color:#173e53; text-decoration:underline; }
#${bannerId} .ns-zip-feedback{
  display:none; border-radius:12px; padding:10px 12px; font-weight:700;
  border:1px solid transparent; line-height:1.2;
}
#${bannerId} .ns-zip-feedback.success{ display:block; color:#065f46; background:#ecfdf5; border-color:#a7f3d0; }
#${bannerId} .ns-zip-feedback.error{ display:block; color:#b42318; background:#fee2e2; border-color:#fecaca; }
#${bannerId} .ns-zip-title{ display:flex; align-items:center; gap:8px; font-weight:800; color:#16384a; }
#${bannerId} .ns-zip-title .dot{ width:8px; height:8px; border-radius:50%; background:#218c5d; display:inline-block; }
@media (max-width:480px){
  #${bannerId} .ns-zip-dismiss{ order:3; height:auto; padding:6px 0; }
  #${bannerId} .ns-zip-check{ order:2; }
  #${bannerId} .ns-zip-input{ order:1; flex:1 1 100%; max-width:100%; }
}
        `.trim();
        var s=document.createElement('style');
        s.id='ns-addr-soft-css';
        s.textContent=css;
        document.head.appendChild(s);
    }

    function buildSoftBanner(){
        var copy = nsAddrAjax.soft_copy || {};
        var isTop = (nsAddrAjax && nsAddrAjax.soft_position) === 'top';
        var posStyle = isTop ? 'top:20px;' : 'bottom:20px;';
        return '' +
          '<div id="'+bannerId+'" style="'+posStyle+'">' +
            '<div class="ns-addr-controls">' +
              '<div class="ns-zip-title" style="flex:1 1 auto;min-width:140px;"><span class="dot"></span><span>'+(copy.title||'Delivery availability')+'</span></div>' +
              '<input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="5" class="ns-zip-input" placeholder="'+(copy.placeholder||'Enter ZIP code')+'" />' +
              '<button class="ns-zip-check">'+(copy.check_btn||'Check')+'</button>' +
              '<button class="ns-zip-dismiss">'+(copy.dismiss||'Not now')+'</button>' +
            '</div>' +
            '<div class="ns-zip-feedback" role="alert" aria-live="polite"></div>' +
          '</div>';
    }

    function wireSoftBanner($){
        var copy = nsAddrAjax.soft_copy || {};
        var $banner = $('#'+bannerId);
        var $zip = $banner.find('.ns-zip-input');
        var $feedback = $banner.find('.ns-zip-feedback');

        function showFeedback(msg,type){
            $feedback.removeClass('success error');
            $feedback.addClass(type === 'error' ? 'error' : 'success');
            $feedback.text(msg||'');
        }

        $banner.on('click', '.ns-zip-dismiss', function(e){
            e.preventDefault();
            $.post(nsAddrAjax.ajax_url, { action:'ns_addr_soft_dismiss', nonce:nsAddrAjax.nonce })
             .always(function(){ $banner.remove(); setSoftCookie({dismissed:true, ts:Date.now()}); });
        });

        $banner.on('click', '.ns-zip-check', function(){
            var v = ($zip.val()||'').replace(/\D/g,'').slice(0,5);
            if (v.length !== 5){ $zip.focus(); $zip.select(); return; }
            var $btn=$(this); $btn.prop('disabled',true).css('opacity','0.85');
            $.post(nsAddrAjax.ajax_url, { action:'ns_addr_check_zip', zip:v, nonce:nsAddrAjax.nonce })
             .done(function(resp){
                if (!resp || !resp.success) return;
                var ok = !!resp.data.in_zone;
                if (ok){
                    var msg = (copy.in_zone||'We deliver to {ZIP}.').replace('{ZIP}', v);
                    showFeedback(msg,'success');
                    setSoftCookie({zip:v,in_zone:true,ts:Date.now()});
                    setTimeout(function(){ $banner.fadeOut(150,function(){ $(this).remove(); }); },1200);
                } else {
                    showFeedback(copy.out_zone || (nsAddrAjax.hard_copy && nsAddrAjax.hard_copy.out_zone) || "We don't deliver to your area.", 'error');
                }
             })
             .fail(function(xhr){
                if (xhr && xhr.status === 403) location.reload();
                else showFeedback(
                    (nsAddrAjax.hard_copy && nsAddrAjax.hard_copy.validator_down) ||
                    "We're sorry, there are some issues with delivering to this address. Please contact our office at 301-933-4833.",
                    'error'
                );
             })
             .always(function(){ $btn.prop('disabled',false).css('opacity',''); });
        });
    }

    function buildOverlay(html){
        return '<div id="'+overlayId+'" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(24,37,44,0.35);display:flex;align-items:center;justify-content:center;z-index:10000;">' +
          '<div class="ns-addr-modal" role="dialog" aria-modal="true" style="background:#fff;border-radius:18px;box-shadow:0 8px 38px rgba(44,62,80,0.13);padding:28px 28px 24px 28px;max-width:720px;width:95vw;position:relative;">' +
            '<button class="ns-addr-close" aria-label="Close" style="position:absolute;top:12px;right:12px;font-size:2em;background:none;border:none;cursor:pointer;">&times;</button>' +
            html +
          '</div></div>';
    }

    function buildAddressForm(prefill){
        var c = (window.nsAddrAjax && nsAddrAjax.hard_copy) || {};
        prefill = prefill || {};
        var zipPref = (function(){
            var raw=getCookie(SOFT_COOKIE);
            try{ var j=JSON.parse(raw); return (j && j.zip) ? j.zip : ''; }catch(e){ return ''; }
        })();
        var zip = (prefill.zip5 || zipPref || '');
        return '' +
          '<h2 style="font-size:1.3em;font-weight:800;margin:0 0 14px;color:#24516a;text-align:center;">'+(c.modal_title||'Confirm delivery address')+'</h2>' +
          '<div class="ns-addr-grid" style="display:grid;grid-template-columns:1fr;gap:10px;">' +
            '<input type="text" name="addr1" placeholder="Street address" style="padding:12px;border:1px solid #cfe0e9;border-radius:10px;" />' +
            '<input type="text" name="addr2" placeholder="Apt, suite, etc. (optional)" style="padding:12px;border:1px solid #cfe0e9;border-radius:10px;" />' +
            '<div style="display:grid;grid-template-columns:1fr 120px 110px;gap:10px;">' +
              '<input type="text" name="city" placeholder="City" style="padding:12px;border:1px solid #cfe0e9;border-radius:10px;" />' +
              '<input type="text" name="state" placeholder="State" maxlength="2" style="padding:12px;border:1px solid #cfe0e9;border-radius:10px;text-transform:uppercase;" />' +
              '<input type="text" name="zip" placeholder="ZIP" maxlength="5" value="'+(zip||'')+'" style="padding:12px;border:1px solid #cfe0e9;border-radius:10px;text-align:center;font-weight:800;" />' +
            '</div>' +
            '<div class="ns-addr-error" role="alert" style="display:none;color:#b42318;background:#fee4e2;border:1px solid #fda29b;padding:8px 10px;border-radius:10px;font-weight:700;"></div>' +
            '<div style="display:flex;gap:10px;margin-top:10px;">' +
              '<button class="ns-addr-submit" style="flex:1;background:#218c5d;color:#fff;border:none;border-radius:12px;padding:14px 0;font-weight:800;letter-spacing:0.4px;">'+(c.submit_btn||'Confirm address')+'</button>' +
              '<button class="ns-addr-cancel" style="flex:1;background:#f0f4f7;color:#24516a;border:none;border-radius:12px;padding:14px 0;font-weight:800;">'+(c.cancel_btn||'Cancel')+'</button>' +
            '</div>' +
          '</div>';
    }

    function openAddressModal(prefill,onDone){
        closeAddressModal();
        var html = buildAddressForm(prefill||{});
        document.body.insertAdjacentHTML('beforeend', buildOverlay(html));
        wireAddressModal(onDone);
    }
    function closeAddressModal(){
        var el=document.getElementById(overlayId);
        if (el) el.remove();
    }

    function wireAddressModal(onDone){
        var c = (window.nsAddrAjax && nsAddrAjax.hard_copy) || {};
        var $ov = window.jQuery ? jQuery('#'+overlayId) : null;

        function showErr(msg){
            if ($ov) { $ov.find('.ns-addr-error').text(msg||'').show(); }
            else {
                var e=document.querySelector('#'+overlayId+' .ns-addr-error');
                if (e){ e.textContent=msg||''; e.style.display='block'; }
            }
        }
        function clearErr(){
            var e=document.querySelector('#'+overlayId+' .ns-addr-error');
            if (e){ e.style.display='none'; e.textContent=''; }
        }

        document.getElementById(overlayId).addEventListener('click', function(ev){
            if (ev.target.closest('.ns-addr-close') || ev.target.closest('.ns-addr-cancel')){
                ev.preventDefault(); closeAddressModal();
            }
        });

        document.getElementById(overlayId).addEventListener('click', function(ev){
            var btn = ev.target.closest('.ns-addr-submit');
            if (!btn) return;
            ev.preventDefault();
            clearErr();

            var root=document.querySelector('#'+overlayId+' .ns-addr-grid');
            var line1=(root.querySelector('input[name="addr1"]').value||'').trim();
            var line2=(root.querySelector('input[name="addr2"]').value||'').trim();
            var city =(root.querySelector('input[name="city"]').value||'').trim();
            var state=(root.querySelector('input[name="state"]').value||'').trim().toUpperCase();
            var zip  =((root.querySelector('input[name="zip"]').value)||'').replace(/\D/g,'').slice(0,5);

            if (!line1 || !city || state.length!==2 || zip.length!==5){ showErr(c.invalid_generic||'Please enter a valid US delivery address.'); return; }
            if (/\bP\.?\s*O\.?\s*BOX\b/i.test(line1)){ showErr(c.po_box||"We can't deliver to PO Boxes - please use a street address."); return; }

            if (!window.jQuery || !window.nsAddrAjax){ showErr('Validator not available.'); return; }
            jQuery.post(nsAddrAjax.ajax_url,{
                action:'ns_addr_validate_full',
                line1:line1,line2:line2,city:city,state:state,zip:zip,
                nonce:nsAddrAjax.nonce
            }).done(function(resp){
                if (!resp || !resp.success){
                    var msg=(resp&&resp.data&&resp.data.message)?resp.data.message:(c.invalid_generic||'Please enter a valid US delivery address.');
                    showErr(msg); return;
                }
                closeAddressModal();
                document.cookie = 'ns_addr_token=1; path=/; samesite=Lax; max-age=' + (60*60*24*30);
                if (typeof onDone==='function') onDone(resp.data.std||null);
            }).fail(function(xhr){
                if (xhr && xhr.status===403) location.reload();
                else showErr(c.validator_down || "We're sorry, there are some issues with delivering to this address. Please contact our office at 301-933-4833.");
            });
        });
    }

    function resumeOriginalAction(){
        reentryGuard = true;
        try {
            if (lastTriggerEl && lastTriggerEl.isConnected){
                var evt=new MouseEvent('click',{bubbles:true,cancelable:true});
                lastTriggerEl.dispatchEvent(evt);
            } else if (lastForm){
                lastForm.submit();
            }
        } finally {
            lastTriggerEl=null;
            lastForm=null;
            setTimeout(function(){ reentryGuard=false; },0);
        }
    }

    function onSubmitCapture(e){
        var form=e.target;
        if (!form || !(form.matches('form.cart') || form.matches('form[action*="add-to-cart"]'))) return;
        if (reentryGuard || hasToken()) return;
        e.preventDefault(); e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        lastForm=form;
        lastTriggerEl=form.querySelector('button.single_add_to_cart_button, button[name="add-to-cart"], input[name="add-to-cart"], [type="submit"]');
        openAddressModal({}, resumeOriginalAction);
    }
    function onClickCapture(e){
        var btn = e.target && e.target.closest('button.single_add_to_cart_button, a.single_add_to_cart_button, button[name="add-to-cart"], input[name="add-to-cart"], form.cart [type="submit"], form[action*="add-to-cart"] [type="submit"]');
        if (!btn) return;
        var form = btn.closest('form') || document.querySelector('form.cart') || btn.closest('form[action*="add-to-cart"]');
        if (!form || !(form.matches('form.cart') || form.matches('form[action*="add-to-cart"]'))) return;
        if (reentryGuard || hasToken()) return;
        e.preventDefault(); e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        lastForm=form;
        lastTriggerEl=btn;
        openAddressModal({}, resumeOriginalAction);
    }

    document.addEventListener('submit', onSubmitCapture, true);
    document.addEventListener('click',  onClickCapture,  true);

    function initSoft(){
        if (!window.jQuery) return;
        if (softBannerEligible()){
            setTimeout(function(){
                if (document.getElementById(bannerId)) return;
                injectSoftStyles();
                jQuery('body').append(buildSoftBanner());
                wireSoftBanner(jQuery);
            },900);
        }
    }
    if (document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded', initSoft);
    } else {
        initSoft();
    }
})();