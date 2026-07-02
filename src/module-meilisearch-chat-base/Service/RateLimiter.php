<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

use Magento\Framework\App\CacheInterface;
use Walkwizus\MeilisearchChatBase\Exception\ChatException;

/**
 * Fixed-window request limiter backed by the Magento cache. The chat endpoint is
 * anonymous and each call costs LLM tokens, so this caps requests per identifier
 * (session + IP) to blunt bot-driven abuse. Not a precise token bucket — adequate
 * and cheap for v1.
 */
class RateLimiter
{
    public const LIMIT = 20;
    public const WINDOW_SECONDS = 60;
    private const CACHE_PREFIX = 'meilisearch_chat_rl_';

    /**
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly CacheInterface $cache
    ) { }

    /**
     * @param string $identifier
     * @return void
     * @throws ChatException
     */
    public function assert(string $identifier): void
    {
        $key = self::CACHE_PREFIX . hash('sha256', $identifier);
        $count = (int) $this->cache->load($key);

        if ($count >= self::LIMIT) {
            throw new ChatException(__('Too many requests. Please slow down and try again.'));
        }

        $this->cache->save((string) ($count + 1), $key, [], self::WINDOW_SECONDS);
    }
}
