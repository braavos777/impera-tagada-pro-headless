<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\RestApi;

use ImperaTagadaProHeadless\Core\Config\SharedSecret;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\RestApi\Action;
use ImperaTagadaProHeadless\Core\RestApi\Contract\AbstractRoute;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /card/frame-link - hands a headless storefront the proxy's card form
 * URL, built like the one PaymentGateway::render_iframe() puts into the
 * classic checkout, but issued for the storefront rather than for this shop.
 *
 * The session token is an HMAC of a host (Utils::siteToken), and the proxy
 * builds frame-ancestors and the bridge's parent origins from the host it
 * resolves the token to (RenderCardForm::frameAncestors). Encrypting the
 * storefront's host here is what lets that storefront - and only it - frame
 * the form and open the bridge, with nothing changed on the proxy. The host
 * has to be on the proxy's Allowed Domains list. The proxy drops the port, so
 * a storefront on a non-default port (http://localhost:3000) also needs its
 * origin in the proxy's storefront origins.
 *
 * Public on purpose: the URL carries no secret, and replaying it is harmless
 * for the same frame-ancestors reason. The shared key never leaves WordPress.
 * Payments are unaffected: the capture still names this shop as merchant_site.
 */
final class CardFrameRoute extends AbstractRoute
{
    public const BASE_URL = '/card/frame-link';

    public function defineActions(): array
    {
        return [
            Action::post(self::BASE_URL, [$this, 'frameLink']),
        ];
    }

    public function frameLink(WP_REST_Request $request): WP_REST_Response
    {
        $logger      = ImperaMain::logger();
        $settings    = ImperaMain::resolve(WcGatewaySettings::class);
        $proxyDomain = $settings->getProxyDomain();

        // A public route must not hand out links for a gateway that is switched off.
        if ($settings->get('enabled') !== 'yes') {
            $logger->warn('Card frame link requested but the gateway is disabled.');

            return self::unavailable('impera_tagada_disabled');
        }

        // Without a domain buildDomain() yields a bare "https://", which the
        // storefront would load as a blank iframe.
        if ($proxyDomain === '') {
            $logger->error('Card frame link requested but the proxy domain is not configured.');

            return self::unavailable('impera_tagada_no_proxy_domain');
        }

        // The gateway is hidden at checkout without it (PaymentGateway::is_available()),
        // and the proxy would refuse to be framed by an unlisted storefront anyway.
        $storefrontOrigin = $settings->getStorefrontOrigin();
        $storefrontHost   = (string) wp_parse_url($storefrontOrigin, PHP_URL_HOST);

        if ($storefrontOrigin === '' || $storefrontHost === '') {
            $logger->error('Card frame link requested but the storefront origin is not configured.');

            return self::unavailable('impera_tagada_no_storefront_origin');
        }

        // The redirect flow has no card form to frame.
        if (!$settings->isPaymentMethodAdvanced()) {
            $logger->error('Card frame link requested but the checkout flow is not the card form.');

            return self::unavailable('impera_tagada_not_advanced');
        }

        try {
            $url = Utils::buildFullProxyUrl(
                $proxyDomain,
                $settings->getMode(),
                ImperaMain::resolve(SharedSecret::class)->get(),
                $storefrontHost
            );
        } catch (Throwable $e) {
            $logger->error('Card frame link: ' . $e->getMessage());

            return self::unavailable('impera_tagada_no_shared_key');
        }

        // The token stays out of the log.
        $logger->info(sprintf('Card frame link issued | storefront=%s proxy=%s', $storefrontOrigin, $proxyDomain));

        return new WP_REST_Response([
            'url'    => $url,
            // What the storefront pins its half of the bridge to.
            'origin' => Utils::buildDomain($proxyDomain),
        ], 200);
    }

    public function permissionCallback(WP_REST_Request $request): bool
    {
        return true;
    }

    /**
     * A headless storefront has no logged-in user and so no `wp_rest` nonce:
     * the default middleware would answer every call with 403.
     */
    public function globalPermissionMiddleware(): array
    {
        return [];
    }

    private static function unavailable(string $code): WP_REST_Response
    {
        return new WP_REST_Response([
            'code'    => $code,
            'message' => 'Card payment is not available right now.',
        ], 503);
    }
}
