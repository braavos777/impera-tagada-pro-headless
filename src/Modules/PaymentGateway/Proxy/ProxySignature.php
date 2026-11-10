<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Proxy;

use ImperaTagadaProHeadless\Core\Config\SharedSecret;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Modules\PaymentGateway\Exceptions\ProxyRequestException;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;
use WP_REST_Request;

final class ProxySignature
{

    public function __construct(
        private readonly WcGatewaySettings $settings,
        private readonly SharedSecret $sharedSecret
    ) {
    }

    public function getSecretKey(): string
    {
        $secret = $this->sharedSecret->get();

        if ($secret === '') {

            ImperaMain::logger()->error('Proxy client not configured: shared key MISSING');

            throw new ProxyRequestException('The proxy client is not configured: shared key is missing.');
        }

        return $secret;
    }

    public function getClientKey(): string
    {
        $clientKey = $this->settings->getClientKey();

        if ($clientKey === '') {
            ImperaMain::logger()->error('Proxy client not configured: client key MISSING');

            throw new ProxyRequestException('The proxy client is not configured: client key is missing.');
        }

        return $clientKey;
    }

    public function signRequest(array $payload): string
    {
        $body = wp_json_encode($payload);

        if ($body === false) {
            throw new ProxyRequestException('The request body could not be encoded as JSON.');
        }

        return hash_hmac('sha256', $body, $this->getSecretKey());
    }

    public function payloadToJson(array $payload): string
    {
        $body = wp_json_encode($payload);

        if ($body === false) {
            throw new ProxyRequestException('The request body could not be encoded as JSON.');
        }

        return $body;
    }

    public function isValidRequestSignature(WP_REST_Request $request): bool
    {
        $signature = $request->get_header('X-Signature');

        /**
         * A missing header is an unsigned request, not a type error: passing
         * null into hash_equals() throws on PHP 8.
         */
        if (empty($signature) || !is_string($signature)) {
            return false;
        }

        $body = $request->get_body();

        $expected = hash_hmac(
            'sha256',
            (string) $body,
            $this->getSecretKey()
        );

        return hash_equals($expected, $signature);
    }

    public function isValidResponseSignature($response): bool
    {
        $signature = wp_remote_retrieve_header($response, 'X-Signature');

        if (empty($signature)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        $expected = hash_hmac(
            'sha256',
            (string) $body,
            $this->getSecretKey()
        );

        return hash_equals($expected, (string) $signature);
    }

    public function getResponseBody( $response ): ?array
    {
        return json_decode( wp_remote_retrieve_body( $response ), true ) ?: null;
    }
}
