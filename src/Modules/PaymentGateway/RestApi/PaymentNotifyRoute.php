<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\RestApi;

use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\RestApi\Action;
use ImperaTagadaProHeadless\Core\RestApi\Contract\AbstractRoute;
use ImperaTagadaProHeadless\Core\Support\Calculator;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\PaymentProcessException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\ProxyRequestException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Responses\ProxyPaymentResponse;
use ImperaTagadaProHeadless\Modules\PaymentGateway\UseCases\ApplyPaymentResult;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy\ProxyClient;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy\ProxySignature;
use RuntimeException;
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
 * Server-to-server, so the nonce check is replaced by the proxy's signature:
 * HMAC-SHA256 over the raw body with the shared secret, plus the client key.
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

        // The order already carries its Tagada payment: a push about another
        // one (an earlier attempt, a mixed-up id) must not touch it.
        $knownTransactionId = (string) $order->get_meta(ImperaMain::config()->meta(MetaKey::TransactionId));

        if ($knownTransactionId !== '' && $knownTransactionId !== $response->getTransactionId()) {
            $logger->warn(sprintf('Proxy push refused: the order belongs to payment %s | %s', $knownTransactionId, $ids));

            $order->add_order_note(sprintf(
                'Tagada proxy notification ignored: payment %s (%s) is not the payment of this order (%s).',
                $response->getTransactionId(),
                $response->getStatus(),
                $knownTransactionId
            ));

            return new WP_REST_Response(['received' => false, 'error_message' => 'The payment belongs to another order.'], 409);
        }

        if (!$response->isCompleted() && !$response->isPending() && !$response->isFailed()) {
            $logger->warn(sprintf('Proxy push not applied: status %s is not handled | %s', $response->getStatus(), $ids));

            $order->add_order_note(sprintf(
                'Tagada proxy notification: payment %s is %s (%s); the order is left as it is.',
                $response->getTransactionId(),
                $response->getStatus(),
                $source
            ));

            return self::answer($response, $order, false);
        }

        if ($response->isCompleted() && !$order->is_paid()) {
            $mismatch = self::amountMismatch($order, $response);

            if ($mismatch !== null) {
                $logger->error(sprintf('Proxy push not applied: %s | %s', $mismatch, $ids));

                $order->update_status('on-hold', sprintf(
                    'Tagada payment %s succeeded, but %s (%s). Check the payment before shipping.',
                    $response->getTransactionId(),
                    $mismatch,
                    $source
                ));

                return self::answer($response, $order, false);
            }
        }

        try {
            ImperaMain::resolve(ApplyPaymentResult::class)->apply($order, $response, $source);
        } catch (PaymentProcessException|RuntimeException $e) {
            // ApplyPaymentResult has already logged and noted the details.
            $logger->warn(sprintf('Proxy push not applied: %s | %s', $e->getMessage(), $ids));

            return new WP_REST_Response(['received' => false, 'error_message' => 'The notification was refused.'], 409);
        } catch (Throwable $e) {
            $logger->error(sprintf('Proxy push crashed: %s: %s | %s', $e::class, $e->getMessage(), $ids), [
                'trace' => $e->getTraceAsString(),
            ]);

            return new WP_REST_Response(['received' => false, 'error_message' => 'The notification could not be applied.'], 500);
        }

        return self::answer($response, $order, true);
    }

    /**
     * The proxy has no nonce. Its signature over the exact body bytes, with
     * the secret from SharedSecret (so a wp-config.php key counts), and the
     * client key both have to match.
     */
    public function globalPermissionMiddleware(): array
    {
        return [
            static function (WP_REST_Request $request, callable $next) {
                try {
                    $reason = ImperaMain::resolve(ProxySignature::class)->isValidRequestSignature($request)
                        ? null
                        : 'bad signature';
                } catch (ProxyRequestException) {
                    $reason = 'no shared key configured';
                }

                if ($reason !== null) {
                    ImperaMain::logger()->error(sprintf(
                        'Proxy push refused: %s | ip=%s route=%s',
                        $reason,
                        self::ip(),
                        $request->get_route()
                    ));

                    return new WP_Error('invalid_signature', 'The request signature does not match.', ['status' => 401]);
                }

                $clientKey = ImperaMain::resolve(WcGatewaySettings::class)->getClientKey();

                if ($clientKey === '' || !hash_equals($clientKey, (string) $request->get_header(ProxyClient::CLIENT_KEY_HEADER))) {
                    ImperaMain::logger()->error(sprintf(
                        'Proxy push refused: %s | ip=%s route=%s',
                        $clientKey === '' ? 'no client key configured' : 'unknown client key',
                        self::ip(),
                        $request->get_route()
                    ));

                    return new WP_Error('invalid_client_key', 'Unknown client key.', ['status' => 401]);
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

    /**
     * What the shop sells is checked too: the proxy compares the charge only
     * with what it was asked for at checkout.
     */
    private static function amountMismatch(WC_Order $order, ProxyPaymentResponse $response): ?string
    {
        $currency = strtoupper($order->get_currency());

        if ($response->getCurrency() !== $currency) {
            return sprintf('the currency is %s, the order is in %s', $response->getCurrency(), $currency);
        }

        $orderMinor = Calculator::toMinor($order->get_total(), $currency);

        if ((int) $response->getGrossAmount(true) !== $orderMinor) {
            return sprintf(
                'the amount is %s %s, the order total is %s %s',
                $response->getGrossAmount(),
                $currency,
                Calculator::fromMinor($orderMinor, $currency),
                $currency
            );
        }

        return null;
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
