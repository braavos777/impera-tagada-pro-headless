(async function ($) {

    const imperaData = {
        imperaTagadaProHeadless
    }

    const settings = imperaData.imperaTagadaProHeadless;

    // Named the way PaymentGateway::payment_fields() names it. The proxy domain
    // is not posted: there is no rotation, the gateway reads it from its settings.
    const tokenField = `${settings.gatewayId}-token`;
    const containerId = settings.frameId.replace(/-area$/, '-credit-form-container');

    const Helper = {
        handleShowHidePaypalButton: function () {

            if ($('input[name="payment_method"]:checked').val() === settings.gatewayId) {
                $(`#${containerId}`).show();
                $('button#place_order').hide();
            } else {
                $(`#${containerId}`).hide();
                $('button#place_order').show();
            }
        }
    }

    const imperaTagadaProHeadlessLog = async (message, type = 'error') => {

        fetch(`${imperaData.imperaTagadaProHeadless.baseApiUrl}/log`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': imperaData.imperaTagadaProHeadless.nonce
            },
            body: JSON.stringify({
                type,
                message
            })
        });

    }

    const blockElement = ($el) => {
        $el.block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6,
                cursor: 'wait',

            },
        });
    };

    const unblockElement = ($el) => {
        $el.unblock();
    };

    let impera_checkout_form = null;

    // Last resort: a frame that dies mid-tokenization must not leave the
    // checkout blocked for good.
    const WATCHDOG_MS = 60000;
    let watchdog = null;

    const disarmWatchdog = () => {
        clearTimeout(watchdog);
        watchdog = null;
    };

    const armWatchdog = () => {
        disarmWatchdog();
        watchdog = setTimeout(() => {
            unblockElement(impera_checkout_form);
            imperaTagadaProHeadlessLog('Card form: no token within 60 s, checkout unblocked', 'warning');
        }, WATCHDOG_MS);
    };

    $(document).ready(function() {

        impera_checkout_form = $('form#order_review').length ? $('form#order_review') : $('form.checkout')//pay_for_order//checkout page;

        //Support==========================================

        $('body').on('updated_checkout', Helper.handleShowHidePaypalButton);

        $(document).on('payment_method_selected', Helper.handleShowHidePaypalButton)

        // WooCommerce refused the order (decline, a field error): the token is
        // spent, so it is dropped here and the iframe asks for the card again.
        // The order-pay page posts normally and reloads, so it never gets here.
        $(document.body).on('checkout_error', () => {
            disarmWatchdog();
            impera_checkout_form.find(`[name="${tokenField}"]`).val('');
            bridge?.emit('checkoutFailed');
        });
    });

    //Bridge======================================
    // WooCommerce re-renders the payment block (and so replaces the iframe) on every
    // updated_checkout: each new iframe sends its own "ready" and needs a new bridge.
    // The allowed origins are read from the iframe's src at that moment, so the
    // check always matches the frame that is actually on the page.
    let bridge = null;
    let connectedFrame = null;

    // The origin the iframe ends up on after a redirect between "host" and "www.host".
    const frameOrigins = (src) => {
        let url;

        try {
            url = new URL(src, window.location.href);
        } catch (e) {
            return [];
        }

        if (url.protocol !== 'https:' && url.protocol !== 'http:') {
            return [];
        }

        const host = url.host.replace(/^www\./, '');
        const schemes = url.protocol === 'http:' ? ['http:', 'https:'] : ['https:'];

        return schemes.flatMap((scheme) => [`${scheme}//${host}`, `${scheme}//www.${host}`]);
    };

    const isOurFrame = (origin, source) => {
        const frame = document.getElementById(imperaData.imperaTagadaProHeadless.frameId);

        // A "ready" still in flight from the frame we have just connected to
        // would open a second, dead channel and destroy the working one.
        if (!frame || frame === connectedFrame || source !== frame.contentWindow) {
            return false;
        }

        if (!frameOrigins(frame.src).includes(origin)) {
            return false;
        }

        connectedFrame = frame;
        return true;
    };

    for (;;) {
        const next = await Mj.connectFrame(isOurFrame);

        if (bridge) {
            bridge.destroy();
        }

        bridge = next;

        bridge.on('tagadaResizeIframe', ({scrollHeight}) => {
            $(`#${settings.frameId}`).attr('height', scrollHeight + 20);
        })

        bridge.on('tagadaCardFormSubmit', () => {
            blockElement(impera_checkout_form);
            armWatchdog();
        })

        // Empty fields or a card the tokenizer refused: the iframe has shown
        // the reason, the checkout just has to come back.
        bridge.on('tokenFailed', () => {
            disarmWatchdog();
            unblockElement(impera_checkout_form);
        });

        // Data from the bridge is untrusted. Never log the token itself.
        bridge.on('tokenCreated', (data) => {
            const tagadaToken = data && typeof data.tagadaToken === 'string' ? data.tagadaToken : '';

            disarmWatchdog();

            if (tagadaToken === '') {
                unblockElement(impera_checkout_form);
                return;
            }
            impera_checkout_form.find(`[name="${tokenField}"]`).val(tagadaToken);
            impera_checkout_form.removeClass('processing').unblock();
            impera_checkout_form.submit();
        });
    }

})(jQuery);

