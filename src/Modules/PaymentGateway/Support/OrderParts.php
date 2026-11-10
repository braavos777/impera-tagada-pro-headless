<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaTagadaProHeadless\Modules\PaymentGateway\Support;


use ImperaTagadaProHeadless\Core\Config\MetaKey;
use ImperaTagadaProHeadless\Core\Config\SessionKey;
use ImperaTagadaProHeadless\Core\ImperaMain;
use ImperaTagadaProHeadless\Core\Support\Calculator;
use ImperaTagadaProHeadless\Core\Support\StringUtil;
use ImperaTagadaProHeadless\Core\Support\Utils;
use ImperaTagadaProHeadless\Modules\PaymentGateway\WcGatewaySettings;
use WC_Order;
use WC_Geolocation;


final class OrderParts
{

    private const LOCALE_PATTERN = '/^[a-z]{2}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?$/';

    private ?array $money = null;


    private array|null|false $shipping = false;

    public function __construct(
        private readonly WC_Order $order,
        private readonly WcGatewaySettings $settings
    ) {
    }


    public function amount(mixed $value): string
    {
        $currency = $this->order->get_currency();

        return Calculator::fromMinor(Calculator::toMinor((string) $value, $currency), $currency);
    }


    public function invoiceId(): string
    {
        return StringUtil::clip($this->settings->getInvoicePrefix() . $this->order->get_order_number(), 127);
    }



    public function locale(): ?string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $locale = str_replace('_', '-', (string) $locale);

        if (preg_match(self::LOCALE_PATTERN, $locale) === 1) {
            return $locale;
        }

        $short = substr($locale, 0, 5);

        return preg_match(self::LOCALE_PATTERN, $short) === 1 ? $short : null;
    }


    public function customer(): array
    {
        return $this->filled([
            'ipAddress'     => $this->order->get_customer_ip_address() ?: WC_Geolocation::get_ip_address(),
            'userAgent' => $this->order->get_customer_user_agent() ?:  mb_substr(wc_get_user_agent(), 0, 1024),
        ]);
    }



    public function billingAddress(): ?array
    {
        return $this->address($this->order->get_address('billing'));
    }

    /**
     * The shipping address, or the billing one when the order was placed
     * without a shipping address.
     */
    public function shippingAddress(): ?array
    {
        return $this->address($this->order->get_address('shipping')) ?? $this->billingAddress();
    }

    public function customerEmail(string $email): ?string
    {
        return $email !== '' && mb_strlen($email) <= 254 && is_email($email) ? $email : null;
    }


    public function customerPhone(string $phone): ?string
    {
        $raw    = trim($phone);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits !== '' && (str_starts_with($raw, '+') || str_starts_with($digits, '00'))) {
            $digits = ltrim($digits, '0');
            $code   = $this->callingCode($this->order->get_billing_country());

            if ($code !== '' && str_starts_with($digits, $code)) {
                $digits = substr($digits, strlen($code));
            }
        }

        if ($digits === '' || strlen($digits) > 14) {
            return null;
        }

        return $digits;
    }

    private function address(array $source): ?array
    {
        $country = strtoupper(trim((string) ($source['country'] ?? '')));

        if (strlen($country) !== 2) {
            return null;
        }

        return $this->filled([
            'firstName' => StringUtil::clip($source['first_name'] ?? '', 300),
            'lastName' => StringUtil::clip($source['last_name'] ?? '', 300),
            'address1' => StringUtil::clip($source['address_1'] ?? '', 300),
            'address2' => StringUtil::clip($source['address_2'] ?? '', 300),
            'city' => StringUtil::clip($source['city'] ?? '', 120),
            'state' => StringUtil::clip($source['state'] ?? '', 300),
            'postal' => StringUtil::clip($source['postcode'] ?? '', 60),
            'zip' => StringUtil::clip($source['postcode'] ?? '', 60),
            'country' => $country,
            'phone' => $this->customerPhone($source['phone'] ?? ''),
            'email' => $this->customerEmail($source['email'] ?? ''),
        ]);
    }


    public function buildReturnUrl(): string
    {
        return $this->merchantUrl(SessionKey::ReturnFlag);
    }

    public function buildCancelUrl(): string
    {
        return $this->merchantUrl(SessionKey::CancelFlag);
    }

    /**
     * Drops nulls and empty strings
     */
    public function filled(array $parts): array
    {
        return array_filter($parts, static fn ($part) => Utils::isNotEmpty($part));
    }

    private function merchantUrl(SessionKey $flag): string
    {
        return add_query_arg([
            ImperaMain::config()->session($flag) => '1',
            'wc_order_id'                       => $this->order->get_id(),
            'order_key'                         => $this->order->get_order_key(),
        ], site_url('/'));
    }


    /**
     * Digits of the country's calling code ("1" for US), '' when unknown.
     */
    private function callingCode(string $country): string
    {
        if ($country === '' || !function_exists('WC') || !WC()->countries) {
            return '';
        }

        $code = WC()->countries->get_country_calling_code($country);

        return preg_replace('/\D+/', '', is_array($code) ? (string) reset($code) : (string) $code) ?? '';
    }
}
