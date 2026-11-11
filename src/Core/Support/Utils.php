<?php

declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Countable;
use WP_REST_Request;
use InvalidArgumentException;
use RuntimeException;

final class Utils
{

    private const LOCALE_PATTERN = '/^[a-z]{2}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?$/';

    /**
     * HKDF info for the session token key - keeps it apart from the HMAC key
     * that signs requests (both come from the same shared key).
     */
    private const SITE_TOKEN_INFO = 'impera-tagada/merchant-site-v1';

    public static function nowTime(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(wp_timezone_string()));
    }

    public static function parseDateTime(mixed $val): ?DateTimeImmutable
    {
        if (empty($val) || !is_scalar($val)) {
            return null;
        }

        $dateString = trim((string)$val);

        if ($dateString === '0000-00-00 00:00:00' || $dateString === '0000-00-00') {
            return null;
        }

        try {
            return new DateTimeImmutable($dateString);
        } catch (Exception $e) {
            return null;
        }
    }

    public static function parseInteger(mixed $val, ?int $default = null): ?int
    {

        if (!is_scalar($val) || $val === null) {
            return $default;
        }

        $strVal = trim((string)$val);

        $filtered = filter_var($strVal, FILTER_VALIDATE_INT);

        if ($filtered !== false) {
            return $filtered;
        }

        if (filter_var($strVal, FILTER_VALIDATE_FLOAT) !== false) {
            return (int)round((float)$strVal);
        }

        return $default;
    }

    public static function parseFloat(mixed $val, ?float $default = null): ?float
    {

        if (!is_scalar($val) || $val === null) {
            return $default;
        }

        $strVal = trim((string)$val);

        $strVal = str_replace(',', '.', $strVal);

        $strVal = preg_replace('/\s+/u', '', $strVal);

        $filtered = filter_var($strVal, FILTER_VALIDATE_FLOAT);

        if ($filtered !== false) {
            return $filtered;
        }

        return $default;
    }

    public static function parseBool(mixed $val, ?bool $default = null): ?bool
    {
        if ($val === null) {
            return $default;
        }

        if (is_bool($val)) {
            return $val;
        }

        if (!is_scalar($val)) {
            return $default;
        }

        $strVal = strtolower(trim((string)$val));

        // It recognizes as true: "1", "true", "on", "yes"
        // How false: "0", "false", "off", "no", ""
        $filtered = filter_var($strVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($filtered !== null) {
            return $filtered;
        }

        return $default;
    }

    /**
     * Scraping text fields based on WordPress (removes HTML tags, escapes).
     */
    public static function parseText(mixed $val, string $default = ''): string
    {
        if (!is_scalar($val) || $val === null) {
            return $default;
        }

        return sanitize_text_field((string)$val);
    }

    /**
     * Clearing domain names or keys (removes special characters, spaces, lowercase).
     */
    public static function parseKey(mixed $val, string $default = ''): string
    {
        if (!is_scalar($val) || $val === null) {
            return $default;
        }

        return sanitize_key((string)$val);
    }

    /**
     * Email clearing.
     */
    public static function parseEmail(mixed $val, string $default = ''): string
    {
        if (!is_scalar($val) || $val === null) {
            return $default;
        }

        return sanitize_email((string)$val);
    }

    public static function safeNumberFormat(
        mixed $val,
        int $decimals = 2,
        string $decimalSeparator = '.',
        string $thousandsSeparator = '',
        string $default = '0.00'
    ): string
    {

        if ($val === null || !is_scalar($val)) {
            return $default;
        }

        $str = trim((string)$val);

        $str = str_replace(',', '.', $str);
        $str = preg_replace('/[^\d.]/', '', $str);

        if ($str === '' || !is_numeric($str)) {
            return $default;
        }

        $parts = explode('.', $str);

        $int = $parts[0] ?? '0';
        $dec = $parts[1] ?? '';

        $dec = substr(str_pad($dec, $decimals, '0'), 0, $decimals);

        return $int . $decimalSeparator . $dec;
    }

    /**
     * Returns a clean domain (host) from home_url(), without scheme, "www.", port and path.
     * Example: https://www.example.com:8080/shop → example.com
     */
    public static function homeDomain(): string
    {
        $host = wp_parse_url(site_url(), PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return '';
        }

        return strtolower(preg_replace('/^www\./i', '', $host));
    }

    public static function buildDomain(string $domainNane): string
    {
        $domain = preg_replace('#^https?://#', '', $domainNane);
        $domain = rtrim($domain, '/');

        return "https://{$domain}";
    }

    /**
     * URL of the card form iframe on the proxy. $mode is 'test' or 'live' and
     * comes from the gateway settings only - never from the request.
     *
     * The shop's domain is not in it: the proxy gets "token" - an HMAC of the
     * domain under a key derived from the shared key - and finds the domain by
     * computing the same token for every entry of its allowed list
     * (DomainGuard::resolveSiteToken on the proxy).
     *
     * The token is always this shop's home domain, which has to be on the
     * proxy's Allowed Domains list. The proxy does not restrict who frames the
     * form (no frame-ancestors - it would show the domain in the browser), so
     * the same link serves the classic checkout and a headless storefront.
     *
     * @throws RuntimeException when the shared key is not configured.
     */
    public static function buildFullProxyUrl(string $proxyDomain, string $mode, string $sharedKey): string
    {
        if ($sharedKey === '') {
            throw new RuntimeException('The proxy shared key is not configured.');
        }

        $baseUrl = self::buildDomain($proxyDomain);

        $payload =  [
            'payment_method'       => 'tagada',
            'token'                => self::siteToken(self::homeDomain(), $sharedKey),
            'currency'             => strtoupper(get_woocommerce_currency()),
            'mode'                 => $mode,
            'locale'               => self::locale()
        ];

        $encodedPayload = self::base64Encode((string) wp_json_encode($payload));

        return "{$baseUrl}/checkout?session={$encodedPayload}";
    }

    /**
     * Must match DomainGuard::siteToken() on the proxy byte for byte: the same
     * info string, the host lowercased and without "www.".
     */
    public static function siteToken(string $host, string $sharedKey): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('/^www\./', '', rtrim($host, '.'));
        $key  = hash_hkdf('sha256', $sharedKey, 32, self::SITE_TOKEN_INFO);

        return hash_hmac('sha256', $host, $key);
    }




    /**
     *
     *
     * Supported shortcodes:
     *   [order_id]   — ID of the current WC order
     *   [last_word]  — The last word from product_name
     *   [keep_original] — Leave the name as it is
     *   [rand_N]     — A random string with a length of N (N > 1)
     *
     * @param string        $template    Template, such as "Order #[order_id]" or "[rand_10] product"
     * @param string        $productName Original Product Name
     * @param int|string|null $orderId    Order number (needed for [order_id])
     * @return string
     */
    public static function applyTitleShortcodes(string $template, string $productName, int|string|null $orderId = null): string
    {
        // [keep_original] — Return the name unchanged
        if (str_contains($template, '[keep_original]')) {
            return $productName;
        }

        $result = $template;

        // [order_id]
        if (str_contains($result, '[order_id]')) {
            $result = str_replace(
                '[order_id]',
                $orderId !== null ? (string) $orderId : '',
                $result,
            );
        }

        // [last_word] — last word from productName
        if (str_contains($result, '[last_word]')) {
            $words    = preg_split('/\s+/', trim($productName));
            $lastWord = !empty($words) ? end($words) : $productName;

            $result = str_replace('[last_word]', $lastWord, $result);
        }

        // [rand_N] — a random string of length N, each occurrence is independent
        if (preg_match_all('/\[rand_(\d+)]/', $result, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $length = (int) $match[1];

                if ($length < 2) {
                    continue;
                }

                // str_replace replaces ALL occurrences — use preg_replace with limit=1
                $result = preg_replace(
                    '/' . preg_quote($match[0], '/') . '/',
                    StringUtil::generateRandomString($length),
                    $result,
                    1, // replace only the first occurrence per iteration
                );
            }
        }

        return $result;
    }

    public static function base64Encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64Decode(string $encodedValue): string
    {
        $decoded = base64_decode(strtr($encodedValue, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64 payload');
        }

        return $decoded;
    }

    public static function isEmpty($value)
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_numeric($value) || is_bool($value)) {
            return false;
        }

        if ($value instanceof Countable) {
            return count($value) === 0;
        }

        return empty($value);
    }

    public static function isNotEmpty($value)
    {
        return !self::isEmpty($value);
    }

    public static function locale(): ?string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $locale = str_replace('_', '-', (string) $locale);

        if (preg_match(self::LOCALE_PATTERN, $locale) === 1) {
            return $locale;
        }

        $short = substr($locale, 0, 5);

        return preg_match(self::LOCALE_PATTERN, $short) === 1 ? $short : null;
    }


}
