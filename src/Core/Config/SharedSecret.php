<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Core\Config;

use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;

/**
 * The HMAC secret shared with the proxy.
 *
 * It is never written down in the plugin code. Lookup order:
 *  1. the wp-config.php constant - for a site that keeps secrets out of the DB;
 *  2. the `shared_key` field of the gateway settings - the normal place;
 *  3. the legacy standalone option, kept so a key set before the gateway
 *     settings existed keeps working.
 */
final class SharedSecret
{
    private const CONSTANT_SUFFIX = 'key';

    public function __construct(
        private readonly PluginConfig $config,
        private readonly WcGatewaySettings $gatewaySettings,
    ) {
    }

    public function get(): string
    {
        if ($this->isDefinedInConfig()) {
            return trim((string) constant($this->constantName()));
        }

        $fromSettings = $this->gatewaySettings->getSharedKey();

        if ($fromSettings !== '') {
            return $fromSettings;
        }

        return trim((string) get_option($this->config->option(OptionKey::SharedKey), ''));
    }

    public function isConfigured(): bool
    {
        return $this->get() !== '';
    }

    /**
     * True when wp-config.php sets a non-empty key: it then wins over the
     * gateway settings field, which the settings screen has to say.
     */
    public function isDefinedInConfig(): bool
    {
        $constant = $this->constantName();

        return defined($constant) && trim((string) constant($constant)) !== '';
    }

    /**
     * Name of the wp-config.php constant, e.g. IMPERA_TAGADA_PRO_HEADLESS_KEY.
     */
    public function constantName(): string
    {
        return $this->config->constantName(self::CONSTANT_SUFFIX);
    }
}
