<?php
/**
 * 2007-2019 PrestaShop SA and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\PrestaShop\Adapter\Cart\QueryHandler;

use Address;
use AddressFormat;
use Carrier;
use Cart;
use CartRule;
use Currency;
use Customer;
use Language;
use Link;
use PrestaShop\PrestaShop\Adapter\Cart\AbstractCartHandler;
use PrestaShop\PrestaShop\Core\Domain\Cart\Exception\CartNotFoundException;
use PrestaShop\PrestaShop\Core\Domain\Cart\Query\GetCartInformation;
use PrestaShop\PrestaShop\Core\Domain\Cart\QueryHandler\GetCartInformationHandlerInterface;
use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartDeliveryOption;
use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartInformation;
use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartInformation\CartAddress;
use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartInformation\CartProduct;
use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartInformation\CartShipping;
use PrestaShop\PrestaShop\Core\Domain\Cart\QueryResult\CartInformation\CartSummary;
use PrestaShop\PrestaShop\Core\Localization\CLDR\LocaleInterface;
use PrestaShop\PrestaShop\Core\Localization\Exception\LocalizationException;
use PrestaShop\PrestaShop\Core\Localization\Locale;
use PrestaShopException;

/**
 * Handles GetCartInformation query using legacy object models
 */
final class GetCartInformationHandler extends AbstractCartHandler implements GetCartInformationHandlerInterface
{
    /**
     * @var LocaleInterface
     */
    private $locale;

    /**
     * @var int
     */
    private $contextLangId;

    /**
     * @param Locale $locale
     * @param int $contextLangId
     */
    public function __construct(
        Locale $locale,
        int $contextLangId
    ) {
        $this->locale = $locale;
        $this->contextLangId = $contextLangId;
    }

    /**
     * @param GetCartInformation $query
     *
     * @return CartInformation
     *
     * @throws CartNotFoundException
     * @throws LocalizationException
     * @throws PrestaShopException
     */
    public function handle(GetCartInformation $query): CartInformation
    {
        $cart = $this->getCart($query->getCartId());
        $currency = new Currency($cart->id_currency);
        $language = new Language($cart->id_lang);

        $legacySummary = $cart->getSummaryDetails(null, true);
        $addresses = $this->getAddresses($cart);

        return new CartInformation(
            $cart->id,
            $this->extractProductsFromLegacySummary($legacySummary),
            (int) $currency->id,
            (int) $language->id,
            $this->extractCartRulesFromLegacySummary($legacySummary, $currency),
            $addresses,
            $this->extractSummaryFromLegacySummary($legacySummary, $currency),
            $addresses ? $this->extractShippingFromLegacySummary($cart, $legacySummary) : null
        );
    }

    /**
     * @param Cart $cart
     *
     * @return CartAddress[]
     */
    private function getAddresses(Cart $cart): array
    {
        $customer = new Customer($cart->id_customer);
        $cartAddresses = [];

        foreach ($customer->getAddresses($cart->id_lang) as $data) {
            $addressId = (int) $data['id_address'];
            $countryIsEnabled = (bool) Address::isCountryActiveById($addressId);

            // filter out disabled countries
            if (!$countryIsEnabled) {
                continue;
            }

            $cartAddresses[$addressId] = new CartAddress(
                $addressId,
                $data['alias'],
                AddressFormat::generateAddress(new Address($addressId), [], '<br />'),
                (int) $cart->id_address_delivery === $addressId,
                (int) $cart->id_address_invoice === $addressId
            );
        }

        return $cartAddresses;
    }

    /**
     * @param array $legacySummary
     * @param Currency $currency
     *
     * @return CartInformation\CartRule[]
     *
     * @throws LocalizationException
     */
    private function extractCartRulesFromLegacySummary(array $legacySummary, Currency $currency): array
    {
        $cartRules = [];

        foreach ($legacySummary['discounts'] as $discount) {
            $cartRules[] = new CartInformation\CartRule(
                (int) $discount['id_cart_rule'],
                $discount['name'],
                $discount['description'],
                $this->locale->formatPrice($discount['value_real'], $currency->iso_code)
            );
        }

        return $cartRules;
    }

    /**
     * @param array $legacySummary
     *
     * @return CartProduct[]
     */
    private function extractProductsFromLegacySummary(array $legacySummary): array
    {
        $products = [];
        foreach ($legacySummary['products'] as $product) {
            $products[] = new CartProduct(
                (int) $product['id_product'],
                isset($product['id_product_attribute']) ? (int) $product['id_product_attribute'] : 0,
                (int) $product['id_customization'],
                $product['name'],
                isset($product['attributes_small']) ? $product['attributes_small'] : '',
                $product['reference'],
                $product['price'],
                (int) $product['quantity'],
                $product['total'],
                (new Link())->getImageLink($product['link_rewrite'], $product['id_image'], 'small_default')
            );
        }

        return $products;
    }

    /**
     * @param Cart $cart
     * @param array $legacySummary
     *
     * @return CartShipping|null
     */
    private function extractShippingFromLegacySummary(Cart $cart, array $legacySummary): ?CartShipping
    {
        $deliveryOptionsByAddress = $cart->getDeliveryOptionList();
        $deliveryAddress = (int) $cart->id_address_delivery;

        //Check if there is any delivery options available for cart delivery address
        if (!array_key_exists($deliveryAddress, $deliveryOptionsByAddress)) {
            return null;
        }

        /** @var Carrier $carrier */
        $carrier = $legacySummary['carrier'];

        return new CartShipping(
            (string) $legacySummary['total_shipping'],
            $this->getFreeShippingValue($cart),
            $this->fetchCartDeliveryOptions($deliveryOptionsByAddress, $deliveryAddress),
            (int) $carrier->id ?: null
        );
    }

    private function getFreeShippingValue(Cart $cart): bool
    {
        $cartRules = $cart->getCartRules(CartRule::FILTER_ACTION_SHIPPING);
        $freeShipping = false;

        foreach ($cartRules as $cartRule) {
            if ($cartRule['id_cart_rule'] == CartRule::getIdByCode(CartRule::BO_ORDER_CODE_PREFIX . (int) $cart->id)) {
                $freeShipping = true;

                break;
            }
        }

        return $freeShipping;
    }

    /**
     * Fetch CartDeliveryOption[] DTO's from legacy array
     *
     * @param array $deliveryOptionsByAddress
     * @param int $deliveryAddressId
     *
     * @return array
     */
    private function fetchCartDeliveryOptions(array $deliveryOptionsByAddress, int $deliveryAddressId)
    {
        $deliveryOptions = [];
        // legacy multishipping feature allowed to split cart shipping to multiple addresses.
        // now when the multishipping feature is removed
        // the list of carriers should be shared across whole cart for single delivery address
        foreach ($deliveryOptionsByAddress[$deliveryAddressId] as $deliveryOption) {
            foreach ($deliveryOption['carrier_list'] as $carrier) {
                $carrier = $carrier['instance'];
                // make sure there is no duplicate carrier
                $deliveryOptions[(int) $carrier->id] = new CartDeliveryOption(
                    (int) $carrier->id,
                    $carrier->name,
                    $carrier->delay[$this->contextLangId]
                );
            }
        }

        //make sure array is not associative
        return array_values($deliveryOptions);
    }

    /**
     * @param array $legacySummary
     * @param Currency $currency
     *
     * @return CartInformation\CartSummary
     *
     * @throws LocalizationException
     */
    private function extractSummaryFromLegacySummary(array $legacySummary, Currency $currency): CartSummary
    {
        $discount = $this->locale->formatPrice($legacySummary['total_discounts_tax_exc'], $currency->iso_code);

        if (0 !== (int) $legacySummary['total_discounts_tax_exc']) {
            $discount = '-' . $discount;
        }

        return new CartSummary(
            $this->locale->formatPrice($legacySummary['total_products'], $currency->iso_code),
            $discount,
            $this->locale->formatPrice($legacySummary['total_shipping_tax_exc'], $currency->iso_code),
            $this->locale->formatPrice($legacySummary['total_tax'], $currency->iso_code),
            $this->locale->formatPrice($legacySummary['total_price'], $currency->iso_code),
            $this->locale->formatPrice($legacySummary['total_price_without_tax'], $currency->iso_code)
        );
    }
}
