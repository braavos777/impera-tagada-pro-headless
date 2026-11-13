<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Support;

use ImperaTagadaProHeadless\Core\ImperaMain;
use WP_REST_Request;

/**
 * This shop's own key for the Tagada proxy: a key id and a secret, issued on
 * the proxy's "Shop keys" page for this shop only - without it the gateway
 * is not offered at all.
 *
 * Source: the wp-config constants <PREFIX>_KEY_ID / <PREFIX>_SECRET
 * (PluginConfig::constantName('key_id' / 'secret')) when defined, otherwise
 * the gateway settings fields shop_key_id / shop_key_secret.
 *
 * Signature protocol v1 - must match SignedRequest on the proxy byte for byte:
 * headers X-Key-Id, X-Timestamp, X-Request-Id, X-Signature, where the signature
 * is HMAC-SHA256 under the secret of
 *     "v1\n" METHOD "\n" rest_route "\n" timestamp "\n" request_id "\n" sha256_hex(body)
 * The body is encoded once and exactly those bytes are signed and sent. The
 * proxy's answers carry X-Signature = HMAC-SHA256(raw body, secret).
 */
final class ShopKey
{
    public const VERSION = 'v1';

    public const MAX_SKEW = 300;

    public static function keyId(): string
    {
        $constant = ImperaMain::config()->constantName('key_id');

        if (defined($constant) && trim((string) constant($constant)) !== '') {
            return trim((string) constant($constant));
        }

        return trim((string) (self::settings()['shop_key_id'] ?? ''));
    }

    public static function secret(): string
    {
        $constant = ImperaMain::config()->constantName('secret');

        if (defined($constant) && trim((string) constant($constant)) !== '') {
            return trim((string) constant($constant));
        }

        return trim((string) (self::settings()['shop_key_secret'] ?? ''));
    }

    public static function isDefinedInConfig(): bool
    {
        $constant = ImperaMain::config()->constantName('secret');

        return defined($constant) && trim((string) constant($constant)) !== '';
    }

    public static function isConfigured(): bool
    {
        return preg_match('/^mk_[a-f0-9]{16}$/', self::keyId()) === 1 && strlen(self::secret()) >= 32;
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $method, string $route, string $body): array
    {
        $timestamp = (string) time();
        $requestId = wp_generate_uuid4();

        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-Key-Id'     => self::keyId(),
            'X-Timestamp'  => $timestamp,
            'X-Request-Id' => $requestId,
            'X-Signature'  => self::sign($method, $route, $timestamp, $requestId, $body),
        ];
    }

    /**
     * An answer of the proxy: X-Signature over the exact body, our secret.
     */
    public static function isValidAnswer(string $body, string $signature): bool
    {
        return self::isConfigured()
            && $signature !== ''
            && hash_equals(hash_hmac('sha256', $body, self::secret()), $signature);
    }

    /**
     * A push from the proxy: our own key id, a fresh timestamp, the signature
     * over the raw body, and a request id not seen before.
     */
    public static function verify(WP_REST_Request $request): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        $keyId     = trim((string) $request->get_header('X-Key-Id'));
        $timestamp = trim((string) $request->get_header('X-Timestamp'));
        $requestId = trim((string) $request->get_header('X-Request-Id'));
        $signature = trim((string) $request->get_header('X-Signature'));

        if (
            !hash_equals(self::keyId(), $keyId)
            || preg_match('/^\d{9,11}$/', $timestamp) !== 1
            || abs(time() - (int) $timestamp) > self::MAX_SKEW
            || preg_match('/^[A-Za-z0-9-]{16,64}$/', $requestId) !== 1
            || $signature === ''
        ) {
            return false;
        }

        $expected = self::sign($request->get_method(), $request->get_route(), $timestamp, $requestId, (string) $request->get_body());

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $replayKey = ltrim(ImperaMain::config()->keyPrefix, '_') . '_rid_' . md5($keyId . '|' . $requestId);

        if (get_transient($replayKey) !== false) {
            return false;
        }

        set_transient($replayKey, 1, 2 * self::MAX_SKEW + 60);

        return true;
    }

    public static function sign(string $method, string $route, string $timestamp, string $requestId, string $body): string
    {
        $payload = implode("\n", [
            self::VERSION,
            strtoupper($method),
            $route,
            $timestamp,
            $requestId,
            hash('sha256', $body),
        ]);

        return hash_hmac('sha256', $payload, self::secret());
    }

    private static function settings(): array
    {
        $settings = get_option('woocommerce_' . ImperaMain::config()->gatewayId . '_settings', []);

        return is_array($settings) ? $settings : [];
    }
}
