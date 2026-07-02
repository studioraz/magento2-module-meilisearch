<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\TimeOutException;
use Psr\Http\Message\StreamInterface;
use Walkwizus\MeilisearchBase\Model\Config\ServerSettings;
use Walkwizus\MeilisearchChatBase\Exception\ChatException;
use Walkwizus\MeilisearchChatBase\Model\Config\ChatSettings;

/**
 * Opens a streaming chat completion against Meilisearch and returns the raw
 * SSE body for the controller to transform. Maps SDK/transport failures to a
 * {@see ChatException} carrying a user-safe message (the original cause is kept
 * for server-side logging).
 */
class ChatManager
{
    /**
     * The only model Meilisearch currently exposes. Hardcoded by design (not
     * admin-configurable); change here if Meilisearch adds model selection.
     */
    public const MODEL = 'gpt-5.2';

    /**
     * @param ChatClientFactory $clientFactory
     * @param ChatSettings $chatSettings
     * @param ServerSettings $serverSettings
     */
    public function __construct(
        private readonly ChatClientFactory $clientFactory,
        private readonly ChatSettings $chatSettings,
        private readonly ServerSettings $serverSettings
    ) { }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param int|string|null $store
     * @return StreamInterface
     * @throws ChatException
     */
    public function streamCompletion(array $messages, $store = null): StreamInterface
    {
        $this->assertConfigured($store);

        $workspace = $this->chatSettings->getWorkspace($store);

        try {
            $client = $this->clientFactory->create($store);

            return $client->chatWorkspace($workspace)->streamCompletion([
                'model' => self::MODEL,
                'messages' => $messages,
                'stream' => true,
                // Declaring these Meilisearch tools is what makes the endpoint stream the
                // search progress + source documents (in choices[].delta.tool_calls). Without
                // them the compat endpoint returns the assistant text only — no product cards.
                'tools' => self::meiliTools(),
            ]);
        } catch (ApiException $e) {
            throw new ChatException($this->messageForStatus($e->httpStatus ?? 0), $e);
        } catch (TimeOutException | CommunicationException $e) {
            throw new ChatException(__('The assistant is unavailable right now. Please try again.'), $e);
        } catch (\Throwable $e) {
            throw new ChatException(
                __('Something went wrong with the assistant. Please try again.'),
                $e instanceof \Exception ? $e : null
            );
        }
    }

    /**
     * @param int|string|null $store
     * @return void
     * @throws ChatException
     */
    private function assertConfigured($store): void
    {
        try {
            $missing = $this->serverSettings->getServerSettingsAddress() === ''
                || $this->chatSettings->getChatApiKey($store) === ''
                || $this->chatSettings->getWorkspace($store) === '';
        } catch (\Throwable $e) {
            throw new ChatException(
                __('The assistant is not configured.'),
                $e instanceof \Exception ? $e : null
            );
        }

        if ($missing) {
            throw new ChatException(__('The assistant is not configured.'));
        }
    }

    /**
     * Meilisearch tool declarations. Their presence in the request is the trigger for the
     * server to stream `_meiliSearchProgress` / `_meiliSearchSources` tool calls.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function meiliTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => '_meiliSearchProgress',
                    'description' => 'Provides information about the current Meilisearch search operation',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'call_id' => ['type' => 'string'],
                            'function_name' => ['type' => 'string'],
                            'function_parameters' => ['type' => 'string'],
                        ],
                        'required' => ['call_id', 'function_name', 'function_parameters'],
                        'additionalProperties' => false,
                    ],
                    'strict' => true,
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => '_meiliSearchSources',
                    'description' => 'Provides sources of the search',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'call_id' => ['type' => 'string'],
                            'documents' => ['type' => 'array', 'items' => ['type' => 'object']],
                        ],
                        'required' => ['call_id', 'documents'],
                        'additionalProperties' => false,
                    ],
                    'strict' => true,
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => '_meiliAppendConversationMessage',
                    'description' => 'Append a new message to the conversation based on what happened internally',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'role' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                            'tool_calls' => ['type' => ['array', 'null']],
                            'tool_call_id' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['role', 'content', 'tool_calls', 'tool_call_id'],
                        'additionalProperties' => false,
                    ],
                    'strict' => true,
                ],
            ],
        ];
    }

    /**
     * @param int $status
     * @return \Magento\Framework\Phrase
     */
    private function messageForStatus(int $status): \Magento\Framework\Phrase
    {
        return match (true) {
            $status === 429 => __('The assistant is busy. Please try again in a moment.'),
            $status >= 500 => __('The assistant is temporarily unavailable. Please try again.'),
            default => __('Something went wrong with the assistant. Please try again.'),
        };
    }
}
