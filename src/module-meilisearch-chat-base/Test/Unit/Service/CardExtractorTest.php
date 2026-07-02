<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Walkwizus\MeilisearchChatBase\Service\CardExtractor;

class CardExtractorTest extends TestCase
{
    private CardExtractor $extractor;

    protected function setUp(): void
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturnCallback(
            static fn (string $type = UrlInterface::URL_TYPE_LINK) => $type === UrlInterface::URL_TYPE_MEDIA
                ? 'https://shop.test/media/'
                : 'https://shop.test/'
        );

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('.html');

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn ($amount) => '₪' . number_format((float) $amount, 2)
        );

        $this->extractor = new CardExtractor($storeManager, $scopeConfig, $priceCurrency);
    }

    public function testMapsRealSourceToCard(): void
    {
        // Trimmed-down shape of a real `_meiliSearchSources` item (note the literal " in name).
        $args = json_encode([
            'call_id' => 'call_x',
            'sources' => [[
                'id' => 4875,
                'sku' => '64992724054',
                'name' => 'Orijen אוריגן 11.4 ק"ג מזון יבש לכלבים',
                'url_key' => 'orijen-test',
                'image' => '/i/m/images_itempics_64992724054_16042025151048.jpg',
                'stock' => ['is_in_stock' => true, 'qty' => 99999999],
                'price_0' => 429,
                'price_1' => 429,
                'brand' => "אוריג'ן Orijen",
            ]],
        ], JSON_THROW_ON_ERROR);

        $cards = $this->extractor->cardsFromArguments($args);

        self::assertCount(1, $cards);
        $card = $cards[0];
        self::assertSame(4875, $card['id']);
        self::assertSame('64992724054', $card['sku']);
        self::assertSame(429.0, $card['price']);
        self::assertSame('₪429.00', $card['price_formatted']);
        self::assertTrue($card['in_stock']);
        self::assertSame(
            'https://shop.test/media/catalog/product/i/m/images_itempics_64992724054_16042025151048.jpg',
            $card['image']
        );
        self::assertSame('https://shop.test/orijen-test.html', $card['url']);
        self::assertSame("אוריג'ן Orijen", $card['brand']);
    }

    public function testEmptySourcesReturnsEmpty(): void
    {
        self::assertSame([], $this->extractor->cardsFromArguments(json_encode(['sources' => []])));
        self::assertSame([], $this->extractor->cardsFromArguments(json_encode(['call_id' => 'x'])));
    }

    public function testMalformedJsonReturnsEmpty(): void
    {
        self::assertSame([], $this->extractor->cardsFromArguments('{not valid json'));
        self::assertSame([], $this->extractor->cardsFromArguments(''));
    }

    public function testNullGuardsMissingFields(): void
    {
        $cards = $this->extractor->buildCards([[
            'name' => 'No-id product',
            // no id, no price_0, no image, no url_key, no stock
        ]]);

        self::assertCount(1, $cards);
        $card = $cards[0];
        self::assertNull($card['id']);
        self::assertNull($card['price']);
        self::assertNull($card['price_formatted']);
        self::assertNull($card['image']);
        self::assertNull($card['url']);
        self::assertFalse($card['in_stock']);
    }

    public function testSkipsItemsWithoutName(): void
    {
        $cards = $this->extractor->buildCards([
            ['id' => 1],
            ['id' => 2, 'name' => 'Has name'],
        ]);

        self::assertCount(1, $cards);
        self::assertSame(2, $cards[0]['id']);
    }

    public function testDedupesById(): void
    {
        $cards = $this->extractor->buildCards([
            ['id' => 7, 'name' => 'First'],
            ['id' => 7, 'name' => 'Dup'],
            ['id' => 8, 'name' => 'Second'],
        ]);

        self::assertCount(2, $cards);
        self::assertSame([7, 8], array_column($cards, 'id'));
    }

    public function testCapsAtMaxCards(): void
    {
        $sources = [];
        for ($i = 1; $i <= CardExtractor::MAX_CARDS + 5; $i++) {
            $sources[] = ['id' => $i, 'name' => 'P' . $i];
        }

        self::assertCount(CardExtractor::MAX_CARDS, $this->extractor->buildCards($sources));
    }
}
