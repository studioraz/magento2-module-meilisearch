<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Meilisearch\Client;
use Walkwizus\MeilisearchBase\Model\Config\ServerSettings;
use Walkwizus\MeilisearchChatBase\Model\Config\ChatSettings;

/**
 * Builds a Meilisearch SDK client dedicated to chat: configured with the chat
 * API key and, crucially, a Guzzle client constructed with `stream => true` so
 * the chat-completion body is read lazily (token-by-token) rather than buffered.
 *
 * Guzzle's PSR-18 `sendRequest()` (used by the SDK's `postStream()`) merges the
 * constructor default options, and does NOT force `stream`, so the default below
 * is what makes streaming work.
 */
class ChatClientFactory
{
    private const CONNECT_TIMEOUT = 5;
    private const READ_TIMEOUT = 60;

    /**
     * @param ServerSettings $serverSettings
     * @param ChatSettings $chatSettings
     * @param HttpFactory $httpFactory
     */
    public function __construct(
        private readonly ServerSettings $serverSettings,
        private readonly ChatSettings $chatSettings,
        private readonly HttpFactory $httpFactory
    ) { }

    /**
     * @param int|string|null $store
     * @return Client
     * @throws \Exception
     */
    public function create($store = null): Client
    {
        $guzzle = new GuzzleClient([
            'stream' => true,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'read_timeout' => self::READ_TIMEOUT,
            'http_errors' => false,
        ]);

        return new Client(
            $this->serverSettings->getServerSettingsAddress(),
            $this->chatSettings->getChatApiKey($store),
            $guzzle,
            $this->httpFactory,
            [],
            $this->httpFactory
        );
    }
}
