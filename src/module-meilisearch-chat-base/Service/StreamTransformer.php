<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

use Psr\Http\Message\StreamInterface;

/**
 * Reads the raw upstream Meilisearch chat SSE and rewrites it for the browser:
 *  - assistant text deltas are passed through as minimal OpenAI-style chunks;
 *  - the internal `_meili*` tool calls are swallowed — `_meiliSearchSources` is
 *    turned into a single `event: products`, `_meiliSearchProgress` into an
 *    optional `event: status`;
 *  - the stream is finalized with `data: [DONE]` (even on premature EOF).
 *
 * Tool-call accumulation: Meilisearch emits each `_meili*` call as its own tool
 * call (often all at `index:0`), so we bucket by tool-call **id** — a new bucket
 * starts whenever an id+name appears, and id-less argument fragments append to
 * the current bucket (handles both whole and split arguments).
 */
class StreamTransformer
{
    private const READ_SIZE = 8192;

    /**
     * @param CardExtractor $cardExtractor
     */
    public function __construct(
        private readonly CardExtractor $cardExtractor
    ) { }

    /**
     * @param StreamInterface $stream Upstream SSE body
     * @param callable(string):void $emit Writes one downstream SSE block
     * @param callable():bool $aborted Returns true when the client has gone away
     * @return array{text: string, product_ids: array<int, int>, aborted: bool, completed: bool}
     *         Summary for response logging
     */
    public function transform(StreamInterface $stream, callable $emit, callable $aborted): array
    {
        $buffer = '';
        $tools = [];          // id => ['name' => string, 'args' => string]
        $emittedSources = []; // id => true
        $statusEmitted = false;
        $currentId = null;
        $summary = ['text' => '', 'product_ids' => [], 'aborted' => false, 'completed' => false];

        while (!$stream->eof()) {
            if ($aborted()) {
                $summary['aborted'] = true;
                return $summary;
            }

            $chunk = $stream->read(self::READ_SIZE);
            if ($chunk === '') {
                usleep(10000); // avoid a busy loop while the upstream is thinking
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($this->handleLine($line, $emit, $tools, $emittedSources, $statusEmitted, $currentId, $summary)) {
                    $emit("data: [DONE]\n\n");
                    $summary['completed'] = true;
                    return $summary;
                }
            }
        }

        // Trailing line without a newline.
        if (trim($buffer) !== ''
            && $this->handleLine(rtrim($buffer, "\r"), $emit, $tools, $emittedSources, $statusEmitted, $currentId, $summary)
        ) {
            $emit("data: [DONE]\n\n");
            $summary['completed'] = true;
            return $summary;
        }

        // Premature EOF (no upstream [DONE]): finalize cleanly, keep partial answer.
        $emit("data: [DONE]\n\n");

        return $summary;
    }

    /**
     * @param string $line
     * @param callable(string):void $emit
     * @param array<string, array{name: string, args: string}> $tools
     * @param array<string, bool> $emittedSources
     * @param bool $statusEmitted
     * @param string|null $currentId
     * @param array{text: string, product_ids: array<int, int>, aborted: bool, completed: bool} $summary
     * @return bool True when the stream is finished ([DONE] or error)
     */
    private function handleLine(
        string $line,
        callable $emit,
        array &$tools,
        array &$emittedSources,
        bool &$statusEmitted,
        ?string &$currentId,
        array &$summary
    ): bool {
        $line = trim($line);
        if ($line === '' || !str_starts_with($line, 'data:')) {
            return false;
        }

        $payload = trim(substr($line, 5));
        if ($payload === '[DONE]') {
            return true;
        }

        try {
            $json = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false; // ignore non-JSON keep-alives / comments
        }

        if (!\is_array($json)) {
            return false;
        }

        // Upstream/LLM-provider error object mid-stream.
        if (isset($json['error'])) {
            $emit($this->sse('error', ['message' => __('The assistant ran into a problem. Please try again.')->render()]));
            return true;
        }

        $delta = $json['choices'][0]['delta'] ?? null;
        if (!\is_array($delta)) {
            return false;
        }

        // Assistant text → pass through as a minimal chunk (strip any tool noise).
        if (isset($delta['content']) && \is_string($delta['content']) && $delta['content'] !== '') {
            $summary['text'] .= $delta['content'];
            $emit('data: ' . json_encode(['choices' => [['delta' => ['content' => $delta['content']]]]]) . "\n\n");
        }

        if (isset($delta['tool_calls']) && \is_array($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $call) {
                if (!\is_array($call)) {
                    continue;
                }
                $this->accumulateToolCall($call, $tools, $currentId);
            }
            $this->emitToolDerivedEvents($emit, $tools, $emittedSources, $statusEmitted, $summary);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $call
     * @param array<string, array{name: string, args: string}> $tools
     * @param string|null $currentId
     * @return void
     */
    private function accumulateToolCall(array $call, array &$tools, ?string &$currentId): void
    {
        $id = isset($call['id']) && \is_string($call['id']) && $call['id'] !== '' ? $call['id'] : null;
        $fn = $call['function'] ?? [];
        $name = \is_array($fn) && isset($fn['name']) && \is_string($fn['name']) ? $fn['name'] : '';
        $args = \is_array($fn) && isset($fn['arguments']) && \is_string($fn['arguments']) ? $fn['arguments'] : '';

        if ($id !== null) {
            // Start (or resume) a bucket for this tool call.
            if (!isset($tools[$id])) {
                $tools[$id] = ['name' => $name, 'args' => ''];
            } elseif ($name !== '') {
                $tools[$id]['name'] = $name;
            }
            $tools[$id]['args'] .= $args;
            $currentId = $id;
            return;
        }

        // Argument-only fragment: append to the in-progress bucket.
        if ($currentId !== null && isset($tools[$currentId])) {
            $tools[$currentId]['args'] .= $args;
        }
    }

    /**
     * @param callable(string):void $emit
     * @param array<string, array{name: string, args: string}> $tools
     * @param array<string, bool> $emittedSources
     * @param bool $statusEmitted
     * @param array{text: string, product_ids: array<int, int>, aborted: bool, completed: bool} $summary
     * @return void
     */
    private function emitToolDerivedEvents(
        callable $emit,
        array $tools,
        array &$emittedSources,
        bool &$statusEmitted,
        array &$summary
    ): void {
        foreach ($tools as $id => $tool) {
            if ($tool['name'] === '_meiliSearchProgress' && !$statusEmitted) {
                $emit($this->sse('status', ['state' => 'searching']));
                $statusEmitted = true;
                continue;
            }

            if ($tool['name'] === '_meiliSearchSources' && !isset($emittedSources[$id])) {
                $cards = $this->cardExtractor->cardsFromArguments($tool['args']);
                if ($cards !== []) {
                    $emit($this->sse('products', ['products' => $cards]));
                    $emittedSources[$id] = true;
                    foreach ($cards as $card) {
                        if (isset($card['id']) && \is_int($card['id'])) {
                            $summary['product_ids'][] = $card['id'];
                        }
                    }
                }
            }
        }
    }

    /**
     * @param string $event
     * @param array<string, mixed> $data
     * @return string
     */
    private function sse(string $event, array $data): string
    {
        return 'event: ' . $event . "\n" . 'data: ' . json_encode($data) . "\n\n";
    }
}
