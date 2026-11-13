<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\RestApi;

use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\RestApi\Action;
use ImperaTagadaProHeadless\Core\RestApi\Contract\AbstractRoute;
use ImperaTagadaProHeadless\Core\Support\ShopKey;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /card/frame-link - hands a headless storefront the proxy's card form
 * URL, the same one PaymentGateway::render_iframe() puts into the classic
 * checkout.
 *
 * The session token is an HMAC of this shop's home domain (Utils::siteToken);
 * the domain itself is in neither the URL nor anything the proxy sends to the
 * browser. The proxy does not restrict who frames the form, so the storefront
 * needs no setting of its own.
 *
 * Public on purpose: the URL carries no secret, and the token the form makes
 * can only be charged through this shop's signed capture call. The shop key
 * never leaves WordPress.
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

        // The redirect flow has no card form to frame.
        if (!$settings->isPaymentMethodAdvanced()) {
            $logger->error('Card frame link requested but the checkout flow is not the card form.');

            return self::unavailable('impera_tagada_not_advanced');
        }

        try {
            $url = Utils::buildFullProxyUrl(
                $proxyDomain,
                $settings->getMode(),
                ShopKey::secret()
            );
        } catch (Throwable $e) {
            $logger->error('Card frame link: ' . $e->getMessage());

            return self::unavailable('impera_tagada_no_shop_key');
        }

        // The token stays out of the log.
        $logger->info(sprintf('Card frame link issued | proxy=%s', $proxyDomain));

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
