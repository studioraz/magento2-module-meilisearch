<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Test\Unit\Service;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Walkwizus\MeilisearchChatBase\Service\CardExtractor;
use Walkwizus\MeilisearchChatBase\Service\StreamTransformer;

class StreamTransformerTest extends TestCase
{
    private StreamTransformer $transformer;

    protected function setUp(): void
    {
        // Real CardExtractor needs Magento collaborators; use an anonymous subclass
        // that maps sources minimally so we can assert transformer behavior in isolation.
        $cardExtractor = new class extends CardExtractor {
            public function __construct() {}
            public function cardsFromArguments(string $argumentsJson): array
            {
                $decoded = json_decode($argumentsJson, true);
                if (!is_array($decoded) || empty($decoded['sources'])) {
                    return [];
                }
                $cards = [];
                foreach ($decoded['sources'] as $s) {
                    if (!isset($s['name'])) {
                        continue;
                    }
                    $cards[] = ['id' => $s['id'] ?? null, 'name' => $s['name']];
                }
                return $cards;
            }
        };

        $this->transformer = new StreamTransformer($cardExtractor);
    }

    /**
     * @param string $sse
     * @return array{frames: string[], summary: array}
     */
    private function runTransform(string $sse): array
    {
        $frames = [];
        $emit = static function (string $payload) use (&$frames): void {
            $frames[] = $payload;
        };

        $summary = $this->transformer->transform(
            Utils::streamFor($sse),
            $emit,
            static fn (): bool => false
        );

        return ['frames' => $frames, 'summary' => $summary];
    }

    public function testSourcesBecomeProductsAndTextPassesThrough(): void
    {
        $sources = json_encode([
            'call_id' => 'c1',
            'sources' => [
                ['id' => 4875, 'name' => 'Orijen'],
                ['id' => 4027, 'name' => 'Now'],
            ],
        ]);

        $sse = implode('', [
            'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'p1', 'function' => ['name' => '_meiliSearchProgress', 'arguments' => '{}']],
            ]]]]]) . "\n",
            'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 's1', 'function' => ['name' => '_meiliSearchSources', 'arguments' => $sources]],
            ]]]]]) . "\n",
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'For a large breed dog, ']]]]) . "\n",
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'try Orijen.']]]]) . "\n",
            "data: [DONE]\n",
        ]);

        ['frames' => $frames, 'summary' => $summary] = $this->runTransform($sse);
        $joined = implode('', $frames);

        // _meili* tool noise must NOT leak downstream
        self::assertStringNotContainsString('_meiliSearch', $joined);

        // status + products synthesized
        self::assertStringContainsString("event: status\ndata: " . json_encode(['state' => 'searching']), $joined);
        self::assertStringContainsString('event: products', $joined);
        self::assertStringContainsString('"id":4875', $joined);

        // text passed through and terminated
        self::assertStringContainsString('"content":"For a large breed dog, "', $joined);
        self::assertStringContainsString("data: [DONE]\n\n", $joined);

        self::assertSame('For a large breed dog, try Orijen.', $summary['text']);
        self::assertSame([4875, 4027], $summary['product_ids']);
        self::assertTrue($summary['completed']);
    }

    public function testChitChatTurnEmitsNoProducts(): void
    {
        $sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'You are welcome!']]]]) . "\n"
            . "data: [DONE]\n";

        ['frames' => $frames] = $this->runTransform($sse);
        $joined = implode('', $frames);

        self::assertStringNotContainsString('event: products', $joined);
        self::assertStringContainsString('"content":"You are welcome!"', $joined);
        self::assertStringContainsString("data: [DONE]\n\n", $joined);
    }

    public function testInStreamErrorEmitsErrorEvent(): void
    {
        $sse = 'data: ' . json_encode(['error' => ['message' => 'provider blew up']]) . "\n";

        ['frames' => $frames] = $this->runTransform($sse);
        $joined = implode('', $frames);

        self::assertStringContainsString('event: error', $joined);
        self::assertStringNotContainsString('provider blew up', $joined); // internal detail not leaked
        self::assertStringContainsString("data: [DONE]\n\n", $joined);
    }

    public function testPrematureEofIsFinalized(): void
    {
        // No [DONE] from upstream.
        $sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'partial']]]]) . "\n";

        ['frames' => $frames, 'summary' => $summary] = $this->runTransform($sse);
        $joined = implode('', $frames);

        self::assertStringContainsString("data: [DONE]\n\n", $joined);
        self::assertFalse($summary['completed']);
        self::assertSame('partial', $summary['text']);
    }

    public function testSplitArgumentFragmentsAreReassembled(): void
    {
        $sources = json_encode(['sources' => [['id' => 9, 'name' => 'Split']]]);
        $half = (int) floor(strlen($sources) / 2);

        $sse = implode('', [
            'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 's1', 'function' => ['name' => '_meiliSearchSources', 'arguments' => substr($sources, 0, $half)]],
            ]]]]]) . "\n",
            'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => substr($sources, $half)]],
            ]]]]]) . "\n",
            "data: [DONE]\n",
        ]);

        ['frames' => $frames, 'summary' => $summary] = $this->runTransform($sse);
        self::assertStringContainsString('event: products', implode('', $frames));
        self::assertSame([9], $summary['product_ids']);
    }
}
