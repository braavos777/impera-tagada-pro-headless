<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy;

use ImperaTagadaProHeadless\Core\Config\PluginConfig;
use ImperaTagadaProHeadless\Core\Contract\LoggerInterface;
use ImperaTagadaProHeadless\Core\Support\ShopKey;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\ProxyRequestException;

/**
 * The only place that talks to the proxy plugin (impera-tagada-gateway).
 *
 * Every call is a signed server-to-server POST with this shop's own key
 * (ShopKey, protocol v1): X-Key-Id, X-Timestamp, X-Request-Id and
 * X-Signature over method, route, timestamp, request id and the body hash.
 * The body is encoded once and that very string is both signed and sent: the
 * proxy checks the bytes it received, and a second encoding may escape them
 * differently.
 *
 * The proxy takes the shop from the key; a merchant_site in the body must name
 * that same shop, and a shop missing from its allowed domains gets 403.
 *
 * A 2xx answer must carry X-Signature over its body (the proxy signs with
 * this shop's secret). Errors are read without it: WordPress's own 401/403 are
 * never signed, and their code is what the log needs.
 *
 * Addressed through ?rest_route= so it works whatever the proxy's permalinks.
 */
final class ProxyClient
{

    public const PAYMENT_STATUS_ROUTE = '/payment/status';

    public const PAYMENT_CAPTURE_ROUTE = '/payment/advance/capture';


    public const SIGNATURE_HEADER = 'X-Signature';

    private const TIMEOUT = 45;

    public function __construct(
        private readonly PluginConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }


    public function advancedCapture(string $proxyDomain, array $payload): array
    {
        return $this->post($proxyDomain, self::PAYMENT_CAPTURE_ROUTE, $payload);
    }

    /**
     * "What became of this attempt?" - only ever reads a payment.
     */
    public function paymentStatus(string $proxyDomain, array $payload): array
    {
        return $this->post($proxyDomain, self::PAYMENT_STATUS_ROUTE, $payload);
    }

    public function endpoint(string $proxyDomain, string $route): string
    {
        return add_query_arg(
            'rest_route',
            '/' . trim($this->config->proxyRestNamespace, '/') . $route,
            Utils::buildDomain($proxyDomain) . '/'
        );
    }

    /**
     * @throws ProxyRequestException
     */
    private function post(string $proxyDomain, string $route, array $payload): array
    {

        if ($proxyDomain === '' || $this->config->proxyRestNamespace === '') {
            $this->logger->error(sprintf(
                'Proxy client not configured: domain %s, namespace %s | route=%s',
                $proxyDomain !== '' ? 'set' : 'MISSING',
                $this->config->proxyRestNamespace !== '' ? 'set' : 'MISSING',
                $route
            ));

            throw new ProxyRequestException('The proxy client is not configured: domain or proxy namespace is missing.');
        }

        if (!ShopKey::isConfigured()) {
            throw new ProxyRequestException('The shop key for the proxy is not configured.');
        }

        $url  = $this->endpoint($proxyDomain, $route);
        $body = wp_json_encode($payload);

        if ($body === false) {
            throw new ProxyRequestException('The request body could not be encoded as JSON.');
        }

        // The body is encoded once: exactly these bytes are signed and sent.
        $response = wp_remote_post($url, [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => ShopKey::headers('POST', '/' . trim($this->config->proxyRestNamespace, '/') . $route, $body),
            'body'        => $body,
        ]);



        if (is_wp_error($response)) {
            $this->logger->error(sprintf(
                'Proxy unreachable  %s',
                $response->get_error_message(),
            ));
            throw new ProxyRequestException(sprintf('Proxy %s is unreachable: %s', $url, $response->get_error_message()));
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $raw     = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true) ?: null;
        $signed  = ShopKey::isValidAnswer($raw, (string) wp_remote_retrieve_header($response, self::SIGNATURE_HEADER));

        if ($status < 200 || $status >= 300) {
            // Our own routes answer error_code/error_message, WP's permission
            // layer code/message - both are worth the log line.
            $decoded      = is_array($decoded) ? $decoded : [];
            $errorCode    = (string) ($decoded['error_code'] ?? $decoded['code'] ?? '');
            $errorMessage = (string) ($decoded['error_message'] ?? $decoded['message'] ?? '');

            $this->logger->error(sprintf(
                'Proxy refused: HTTP %d %s "%s"',
                $status,
                $errorCode !== '' ? $errorCode : '-',
                $errorMessage,
            ));

            // Only a signed refusal is the proxy's word (a 409's blocking
            // attempt is trusted only then); anything else is just a status.
            throw new ProxyRequestException(
                sprintf('Proxy %s answered HTTP %d %s: %s', $url, $status, $errorCode, $errorMessage),
                $status,
                $errorCode,
                null,
                $signed ? $decoded : []
            );
        }

        if (!$signed) {
            $this->logger->error(sprintf(
                'Proxy answered HTTP %d with an invalid signature.',
                $status
            ));

            throw new ProxyRequestException(sprintf('Proxy %s answered with an invalid signature.', $url), $status);
        }

        if (!is_array($decoded)) {
            $this->logger->error(sprintf(
                'Proxy answered HTTP %d without a JSON body %s',
                $status,
                mb_substr(trim(wp_strip_all_tags( wp_remote_retrieve_body($response) )), 0, 300)
            ));

            throw new ProxyRequestException(sprintf('Proxy %s answered HTTP %d without a JSON body.', $url, $status), $status);
        }

        return $decoded;
    }

}
