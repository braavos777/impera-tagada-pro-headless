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
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\ApplyPaymentResult;
use Throwable;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /payment/notify - the proxy pushes a payment result here on every
 * Tagada webhook it handled (payment succeeded / failed / rejected / refunded),
 * after re-reading the payment from Tagada.
 *
 * Server-to-server, so the nonce check is replaced by the proxy's v1
 * signature with this shop's own key (ShopKey::verify).
 * The body is the proxy's PaymentResponse plus `event`; the proxy addresses
 * it as ?rest_route=<restNamespace>/payment/notify on the shop's host.
 *
 * The proxy does not retry a refused push, so only a body that cannot be
 * applied at all is answered with 4xx; a result this route deliberately leaves
 * alone (unknown status, amount mismatch) is 200 with applied:false.
 */
final class PaymentNotifyRoute extends AbstractRoute
{
    public const BASE_URL = '/payment/notify';

    public static function url(): string
    {
        return rest_url(ImperaMain::config()->restNamespace . self::BASE_URL);
    }

    public function defineActions(): array
    {
        return [
            Action::post(self::BASE_URL, [$this, 'notify']),
        ];
    }

    public function notify(WP_REST_Request $request): WP_REST_Response
    {
        $logger = ImperaMain::logger();
        $result = self::normalize($request->get_json_params());

        if ($result === null) {
            $logger->warn(sprintf('Proxy push refused: the body is not a valid notification | ip=%s', self::ip()));

            return new WP_REST_Response(['received' => false, 'error_message' => 'Invalid notification body.'], 400);
        }

        $response = ProxyPaymentResponse::fromProxyResponse($result);
        $source   = ApplyPaymentResult::pushSource(sanitize_text_field((string) $response->getEvent()));
        $ids      = sprintf('pp=%s wc=%d source=%s', (string) $response->getTransactionId(), (int) $response->getWcOrderId(), $source);
        $order    = wc_get_order((int) $response->getWcOrderId());

        $logger->info(sprintf(
            'Proxy push received: status %s (%s), success %s | %s',
            $response->getStatus(),
            $response->getSubStatus() !== '' ? $response->getSubStatus() : '-',
            $response->isSuccess() ? 'yes' : 'no',
            $ids
        ));

        if (!$order instanceof WC_Order || $order->get_payment_method() !== ImperaMain::config()->gatewayId) {
            $logger->warn(sprintf('Proxy push for an unknown order or another payment method | %s', $ids));

            return new WP_REST_Response(['received' => false, 'error_message' => 'Unknown order.'], 404);
        }

        // Everything else - the attempt, the payment, the mode, the currency,
        // the amount - is checked by PaymentResultValidator inside
        // ApplyPaymentResult before anything is written; a late pending or
        // failure never touches a paid order.
        try {
            $applied = ImperaMain::resolve(ApplyPaymentResult::class)->apply($order->get_id(), $response, $source);
        } catch (Throwable $e) {
            $logger->error(sprintf('Proxy push crashed: %s: %s | %s', $e::class, $e->getMessage(), $ids), [
                'trace' => $e->getTraceAsString(),
            ]);

            // 500: the proxy retries.
            return new WP_REST_Response(['received' => false, 'error_message' => 'The notification could not be applied.'], 500);
        }

        if ($applied['outcome'] === ApplyPaymentResult::OUTCOME_CONFLICT) {
            return self::answer($response, $applied['order'], false);
        }

        $order = $applied['order'];

        return self::answer($response, $order, true);
    }

    /**
     * The proxy's push, signed with this shop's own key (protocol v1 -
     * ShopKey::verify: key id, fresh timestamp, signature over the raw body,
     * an unused request id).
     */
    public function globalPermissionMiddleware(): array
    {
        return [
            static function (WP_REST_Request $request, callable $next) {
                if (!ShopKey::verify($request)) {
                    ImperaMain::logger()->error(sprintf(
                        'Proxy push refused: %s | ip=%s route=%s',
                        ShopKey::isConfigured() ? 'bad signature, key, timestamp or a replay' : 'no shop key configured',
                        self::ip(),
                        $request->get_route()
                    ));

                    return new WP_Error('invalid_signature', 'The request signature does not match.', ['status' => 401]);
                }

                return $next($request);
            },
        ];
    }

    public function permissionCallback(WP_REST_Request $request): bool
    {
        return true;
    }

    /**
     * ProxyPaymentResponse is strictly typed: a field of the wrong type would
     * be a TypeError instead of a 400. The order, the payment and its status
     * are required; the optional fields are dropped when they are not strings.
     *
     * @return array|null null when the body cannot be a notification.
     */
    private static function normalize(mixed $body): ?array
    {
        if (!is_array($body)) {
            return null;
        }

        $wcOrderId = $body['wcOrderId'] ?? null;
        $wcOrderId = is_int($wcOrderId) || (is_string($wcOrderId) && ctype_digit($wcOrderId)) ? (int) $wcOrderId : 0;

        if (
            $wcOrderId <= 0
            || !is_string($body['status'] ?? null) || $body['status'] === ''
            || !is_string($body['transactionId'] ?? null) || $body['transactionId'] === ''
            || !is_numeric($body['amountMinor'] ?? null)
            || !is_string($body['currency'] ?? null) || $body['currency'] === ''
        ) {
            return null;
        }

        $body['wcOrderId'] = $wcOrderId;
        $body['success']   = (bool) ($body['success'] ?? false);

        foreach (['subStatus', 'requireAction', 'errorMessage', 'event'] as $key) {
            if (array_key_exists($key, $body) && !is_string($body[$key])) {
                unset($body[$key]);
            }
        }

        return $body;
    }

    private static function answer(ProxyPaymentResponse $response, WC_Order $order, bool $applied): WP_REST_Response
    {
        return new WP_REST_Response([
            'received'       => true,
            'applied'        => $applied,
            'payment_status' => $response->getStatus(),
            'order_status'   => $order->get_status(),
        ], 200);
    }

    private static function ip(): string
    {
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }
}
