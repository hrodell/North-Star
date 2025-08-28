/**
 * NSDS Front-End Controller (PATCH v2)
 * -----------------------------------------------------------------------------
 * Developer Summary
 * Purpose
 *  - Load Setup/Removal service cards when user selects a height.
 *  - Intercept product form submit to optionally show reminder popup if services
 *    are missing.
 *  - (Patch v2) Persist popup-chosen services across multi-step submission
 *    flows (e.g., Address Gate modal or other interceptors) so they are not
 *    lost when the form is re-submitted.
 *
 * Key Patch Changes (v2)
 *  - Introduces a per-form data flag: data('nsdsServicesCommitted', true)
 *    after the user confirms selections in the reminder popup.
 *  - Stops unconditionally removing existing hidden add_setup/add_removal
 *    inputs once committed.
 *  - Mirrors popup selections onto the base service card checkboxes
 *    (#northstar-setup-checkbox / #northstar-removal-checkbox) so future
 *    interception logic treats them as â€œalready selectedâ€ and naturally
 *    re-adds hidden fields if needed.
 *  - Adds idempotent hidden input creation (will update value if already there).
 *  - Adds guard to avoid re-showing reminder popup if services are committed
 *    unless BOTH remain unchecked intentionally (user removed them).
 *  - Defensive checks so multiple interceptors (Address Gate) do not strip
 *    committed selections.
 *
 * Notes
 *  - If user re-opens page or changes height after committing, previous
 *    commitments are cleared (height change triggers reload of cards).
 */

jQuery(document).ready(function($){
    var $heightDropdown = $('select[name="attribute_pa_height"]');
    var nsdsPopupComplete = false; // Internal loop guard for OUR popup only.

    function ajaxFailReload(xhr){
        if (xhr && xhr.status === 403) window.location.reload();
    }

    // Utility: Add/update a hidden field.
    function ensureHidden($form, name, value){
        var $f = $form.find('input[name="'+name+'"]');
        if ($f.length) {
            if ($f.val() !== String(value)) $f.val(value);
        } else {
            $form.append('<input type="hidden" name="'+name+'" value="'+value+'">');
        }
    }

    // Load service cards for the current height selection
    function loadServiceCards() {
        var heightSlug = $heightDropdown.val();
        var $container = $('#tree-service-container');
        if (!heightSlug) {
            $container.html('<div>Please select a tree height to see service options.</div>');
            return;
        }
        $.post(
            nsdsTreeServiceAjax.ajax_url,
            { action: 'nsds_get_tree_services', height: heightSlug, nonce: nsdsTreeServiceAjax.nonce }
        ).done(function(response) {
            $container.html(response);
            // If we previously committed selections, reflect them visually (e.g. on height restore)
            var $form = $('form.cart').first();
            if ($form.data('nsdsServicesCommitted')) {
                var committed = $form.data('nsdsCommittedValues') || {};
                if (committed.setup && $('#northstar-setup-checkbox').length) {
                    $('#northstar-setup-checkbox').prop('checked', true);
                }
                if (committed.removal && $('#northstar-removal-checkbox').length) {
                    $('#northstar-removal-checkbox').prop('checked', true);
                }
            }
        }).fail(ajaxFailReload);
    }

    $heightDropdown.on('change', function(){
        // Height change invalidates prior commitments.
        var $form = $('form.cart').first();
        $form.removeData('nsdsServicesCommitted')
             .removeData('nsdsCommittedValues');
        $form.find('input[name="add_setup"],input[name="add_removal"]').remove();
        loadServiceCards();
    });
    if ($heightDropdown.length && $heightDropdown.val()) loadServiceCards();

    function showServiceReminderPopup(heightSlug, missingServices, $form, onComplete) {
        $.post(
            nsdsTreeServiceAjax.ajax_url,
            {
                action: 'nsds_get_service_reminder_popup',
                height: heightSlug,
                missing_services: missingServices,
                nonce: nsdsTreeServiceAjax.nonce
            }
        ).done(function(response) {
            var popupId = "northstar-service-popup-overlay";
            var popupHtml =
                '<div id="'+popupId+'" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(24,37,44,0.35);display:flex;align-items:center;justify-content:center;z-index:9999;">' +
                    '<div style="background:#fff;border-radius:18px;box-shadow:0 8px 38px rgba(44,62,80,0.13);padding:36px 32px 28px 32px;max-width:680px;width:96vw;position:relative;">' +
                        '<button class="northstar-service-popup-close" style="position:absolute;top:18px;right:18px;font-size:2em;background:none;border:none;cursor:pointer;">&times;</button>' +
                        '<h2 style="font-size:1.3em;font-weight:700;margin-bottom:14px;color:#24516a;text-align:center;">Are you sure you don\'t need a hand?</h2>' +
                        response +
                    '</div>' +
                '</div>';

            $('body').append(popupHtml);
            var $popup = $('#' + popupId);

            $popup.find('.northstar-service-popup-close').on('click', function() {
                $popup.remove();
            });
            $popup.find('.northstar-skip-service-btn').on('click', function() {
                $popup.remove();
                onComplete({});
            });

            $popup.find('.northstar-add-services-btn').on('click', function() {
                var selected = {};
                $popup.find('.northstar-reminder-checkbox:checked').each(function(){
                    var pid = $(this).data('product-id');
                    if ($(this).attr('name') === 'choose_setup')   selected.setup   = pid;
                    if ($(this).attr('name') === 'choose_removal') selected.removal = pid;
                });

                // Convenience auto-add if only one type was missing and user left it unchecked
                if (!selected.setup && missingServices.length === 1 && missingServices[0] === 'setup') {
                    var setupBox = $popup.find('input[name="choose_setup"]');
                    if (setupBox.length) selected.setup = setupBox.data('product-id');
                }
                if (!selected.removal && missingServices.length === 1 && missingServices[0] === 'removal') {
                    var removalBox = $popup.find('input[name="choose_removal"]');
                    if (removalBox.length) selected.removal = removalBox.data('product-id');
                }

                $popup.remove();
                onComplete(selected);
            });
        }).fail(ajaxFailReload);
    }

    $('form.cart').off('submit.nsds').on('submit.nsds', function(e){
        var $form = $(this);

        // Skip if weâ€™re letting a popup-triggered re-submit pass through
        if (nsdsPopupComplete) {
            nsdsPopupComplete = false;
            return true;
        }

        var committed = !!$form.data('nsdsServicesCommitted');

        // Collect current card checkbox states (base cards, not popup)
        var $setupCard   = $('#northstar-setup-checkbox');
        var $removalCard = $('#northstar-removal-checkbox');
        var cardSetupChecked   = ($setupCard.length && $setupCard.is(':checked'));
        var cardRemovalChecked = ($removalCard.length && $removalCard.is(':checked'));

        // If we previously committed via popup, mirror those values to card checkboxes
        if (committed) {
            var committedVals = $form.data('nsdsCommittedValues') || {};
            if (committedVals.setup && $setupCard.length) {
                $setupCard.prop('checked', true);
                cardSetupChecked = true;
            }
            if (committedVals.removal && $removalCard.length) {
                $removalCard.prop('checked', true);
                cardRemovalChecked = true;
            }
        }

        // IMPORTANT PATCH:
        // Only remove existing hidden inputs if we have NOT yet committed popup selections.
        if ( ! committed ) {
            $form.find('input[name="add_setup"],input[name="add_removal"]').remove();
        }

        // Determine missing before potential popup
        var missing = [];
        if ( ! cardSetupChecked )   missing.push('setup');
        if ( ! cardRemovalChecked ) missing.push('removal');

        // If already committed AND we have hidden fields (or card boxes now checked) -> just ensure hidden fields and allow submit.
        if ( committed && (cardSetupChecked || cardRemovalChecked) ) {
            var committedVals2 = $form.data('nsdsCommittedValues') || {};
            if (cardSetupChecked && committedVals2.setup) {
                ensureHidden($form, 'add_setup', committedVals2.setup);
            }
            if (cardRemovalChecked && committedVals2.removal) {
                ensureHidden($form, 'add_removal', committedVals2.removal);
            }
            return true;
        }

        // If some services missing (and not yet committed) show popup
        if (missing.length > 0) {
            e.preventDefault();
            var heightSlug = $heightDropdown.val();

            showServiceReminderPopup(heightSlug, missing, $form, function(selected){
                // If user selected something, mark commitment & reflect in base card UI
                var committedValues = {};

                if (cardSetupChecked && $setupCard.data('product-id')) {
                    // Already chosen on base card
                    ensureHidden($form, 'add_setup', $setupCard.data('product-id'));
                    committedValues.setup = $setupCard.data('product-id');
                }
                if (cardRemovalChecked && $removalCard.data('product-id')) {
                    ensureHidden($form, 'add_removal', $removalCard.data('product-id'));
                    committedValues.removal = $removalCard.data('product-id');
                }

                // Newly chosen via popup
                if (missing.includes('setup') && selected.setup) {
                    ensureHidden($form, 'add_setup', selected.setup);
                    committedValues.setup = selected.setup;
                    if ($setupCard.length) $setupCard.prop('checked', true);
                }
                if (missing.includes('removal') && selected.removal) {
                    ensureHidden($form, 'add_removal', selected.removal);
                    committedValues.removal = selected.removal;
                    if ($removalCard.length) $removalCard.prop('checked', true);
                }

                // If nothing was ultimately selected (user skipped), do NOT mark committed.
                if (Object.keys(committedValues).length > 0) {
                    $form.data('nsdsServicesCommitted', true);
                    $form.data('nsdsCommittedValues', committedValues);
                }

                // Re-submit (bypass popup loop)
                nsdsPopupComplete = true;
                $form.trigger('submit');
            });

            return false;
        }

        // No popup needed (both already checked on card). Add hidden inputs & submit.
        if (cardSetupChecked && $setupCard.data('product-id')) {
            ensureHidden($form, 'add_setup', $setupCard.data('product-id'));
        }
        if (cardRemovalChecked && $removalCard.data('product-id')) {
            ensureHidden($form, 'add_removal', $removalCard.data('product-id'));
        }

        // Mark committed for consistency (so subsequent gating/modal re-submits keep selections)
        if (cardSetupChecked || cardRemovalChecked) {
            var committedValuesDirect = {};
            if (cardSetupChecked && $setupCard.data('product-id')) {
                committedValuesDirect.setup = $setupCard.data('product-id');
            }
            if (cardRemovalChecked && $removalCard.data('product-id')) {
                committedValuesDirect.removal = $removalCard.data('product-id');
            }
            if (Object.keys(committedValuesDirect).length > 0) {
                $form.data('nsdsServicesCommitted', true);
                $form.data('nsdsCommittedValues', committedValuesDirect);
            }
        }
    });
});