<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Config;

/**
 * Every value that differs between one gateway plugin and the next.
 *
 * The point is that this is the ONLY place such a value is written down: the
 * plugin entry file builds one of these, and nothing under Core/ or Modules/
 * ever names the plugin again. Porting the code to another gateway
 * is then a namespace rename plus a new constructor call.
 */
final readonly class PluginConfig
{
    /**
     * @param string $keyPrefix          Leading-underscore prefix for meta and option keys, e.g. '_impera_tagada_pro_headless'.
     * @param string[] $availableCurrencies
     * @param string $proxyRestNamespace REST namespace of the proxy plugin this gateway talks to,
     *                                   e.g. 'impera-tagada-gateway/v1' (see Modules\PaymentGateway\ProxyClient).
     */
    public function __construct(
        public string $slug,
        public string $file,
        public string $dir,
        public string $url,
        public string $version,
        public string $restNamespace,
        public string $viewPath,
        public string $keyPrefix,
        public string $gatewayId,
        public string $repoUrl,
        public array $availableCurrencies = [],
        public string $proxyRestNamespace = '',
    ) {
    }

    /**
     * WC order meta key, e.g. '_impera_tagada_pro_headless_order_id'.
     */
    public function meta(MetaKey $key): string
    {
        return $this->prefixed($key->value);
    }

    /**
     * WP option name, e.g. '_impera_tagada_pro_headless_shared_key'.
     */
    public function option(OptionKey $key): string
    {
        return $this->prefixed($key->value);
    }

    /**
     * WC session key. No leading underscore: session keys are not hidden meta.
     */
    public function session(SessionKey $key): string
    {
        return ltrim($this->prefixed($key->value), '_');
    }

    /**
     * Uppercase name of the wp-config.php constant holding a secret,
     * e.g. 'IMPERA_TAGADA_PRO_HEADLESS_KEY'. Derived so it needs no renaming either.
     */
    public function constantName(string $suffix): string
    {
        return strtoupper(ltrim($this->keyPrefix, '_') . '_' . ltrim($suffix, '_'));
    }

    /**
     * Public URL of a file under the plugin's assets/ directory.
     */
    public function asset(string $relativePath): string
    {
        return $this->url . 'assets/' . ltrim($relativePath, '/\\');
    }

    /**
     * Script/style handle, kept unique per plugin.
     */
    public function handle(string $suffix): string
    {
        return $this->slug . '-' . ltrim($suffix, '-');
    }

    private function prefixed(string $suffix): string
    {
        return rtrim($this->keyPrefix, '_') . '_' . ltrim($suffix, '_');
    }
}
