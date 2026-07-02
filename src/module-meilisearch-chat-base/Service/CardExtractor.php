<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns the raw documents from a Meilisearch `_meiliSearchSources` tool call into
 * normalized product-card objects for the storefront. Trusts the indexed copy only
 * for product *identity/display* — stock/price are re-validated by Magento at
 * add-to-cart time. Every field is null-guarded: a malformed item is skipped, never
 * fatal, so the text answer keeps streaming even if cards fail.
 *
 * Card object contract:
 *   { id, name, sku, brand, price, price_formatted, in_stock, image, url }
 */
class CardExtractor
{
    /** Cap cards server-side: sources can return 20+ docs. */
    public const MAX_CARDS = 6;

    private const PRODUCT_URL_SUFFIX_PATH = 'catalog/seo/product_url_suffix';

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency
    ) { }

    /**
     * Decode a `_meiliSearchSources` tool-call `arguments` JSON string into cards.
     * Returns [] on malformed JSON or when there are no sources.
     *
     * @param string $argumentsJson
     * @return array<int, array<string, mixed>>
     */
    public function cardsFromArguments(string $argumentsJson): array
    {
        if (trim($argumentsJson) === '') {
            return [];
        }

        try {
            $decoded = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $sources = (\is_array($decoded) && isset($decoded['sources']) && \is_array($decoded['sources']))
            ? $decoded['sources']
            : [];

        return $this->buildCards($sources);
    }

    /**
     * @param array<int, mixed> $sources
     * @return array<int, array<string, mixed>>
     */
    public function buildCards(array $sources): array
    {
        $cards = [];
        $seenIds = [];

        foreach ($sources as $source) {
            if (!\is_array($source)) {
                continue;
            }

            $card = $this->mapSource($source);
            if ($card === null) {
                continue;
            }

            $id = $card['id'];
            if ($id !== null) {
                if (isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;
            }

            $cards[] = $card;
            if (\count($cards) >= self::MAX_CARDS) {
                break;
            }
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private function mapSource(array $source): ?array
    {
        $name = $this->str($source, 'name');
        if ($name === '') {
            // A card with no name is useless to render; skip it.
            return null;
        }

        $price = isset($source['price_0']) && is_numeric($source['price_0'])
            ? (float) $source['price_0']
            : null;

        return [
            'id' => isset($source['id']) && is_numeric($source['id']) ? (int) $source['id'] : null,
            'name' => $name,
            'sku' => $this->str($source, 'sku'),
            'brand' => $this->str($source, 'brand'),
            'price' => $price,
            'price_formatted' => $price !== null ? $this->formatPrice($price) : null,
            'in_stock' => $this->isInStock($source),
            'image' => $this->resolveImageUrl($this->str($source, 'image')),
            'url' => $this->resolveProductUrl($this->str($source, 'url_key')),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return bool
     */
    private function isInStock(array $source): bool
    {
        $stock = $source['stock'] ?? null;
        if (\is_array($stock) && \array_key_exists('is_in_stock', $stock)) {
            return (bool) $stock['is_in_stock'];
        }

        return false;
    }

    /**
     * @param string $path Media-relative path, e.g. "/i/m/foo.jpg"
     * @return string|null Absolute media URL or null when no image
     */
    private function resolveImageUrl(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $mediaBase = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        return rtrim($mediaBase, '/') . '/catalog/product/' . ltrim($path, '/');
    }

    /**
     * @param string $urlKey
     * @return string|null Absolute storefront PDP URL or null when no url_key
     */
    private function resolveProductUrl(string $urlKey): ?string
    {
        if ($urlKey === '') {
            return null;
        }

        $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        $suffix = (string) ($this->scopeConfig->getValue(
            self::PRODUCT_URL_SUFFIX_PATH,
            ScopeInterface::SCOPE_STORE
        ) ?? '');

        return rtrim($baseUrl, '/') . '/' . ltrim($urlKey, '/') . $suffix;
    }

    /**
     * @param float $price
     * @return string
     */
    private function formatPrice(float $price): string
    {
        return $this->priceCurrency->format($price, false);
    }

    /**
     * @param array<string, mixed> $source
     * @param string $key
     * @return string
     */
    private function str(array $source, string $key): string
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }
}
