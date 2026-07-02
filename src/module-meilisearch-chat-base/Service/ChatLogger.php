<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

use Psr\Log\LoggerInterface;
use Walkwizus\MeilisearchChatBase\Model\Config\ChatSettings;
use Walkwizus\MeilisearchChatBase\Model\Config\Source\LogLevel;

/**
 * Level-aware, redaction-safe logging for the chat proxy. Writes to a dedicated
 * channel (var/log/meilisearch_chat.log via DI). The chat API key is never passed
 * in and never logged. Every entry carries the per-request correlation id so a
 * shopper-reported issue maps back to its request/response.
 */
class ChatLogger
{
    /**
     * @param LoggerInterface $logger Dedicated meilisearch_chat channel (see di.xml)
     * @param ChatSettings $chatSettings
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ChatSettings $chatSettings
    ) { }

    /**
     * @param string $correlationId
     * @param string $workspace
     * @param array<int, array{role: string, content: string}> $messages
     * @return void
     */
    public function logRequest(string $correlationId, string $workspace, array $messages): void
    {
        if (!$this->isFull()) {
            return;
        }

        $this->logger->info('chat request', [
            'cid' => $correlationId,
            'workspace' => $workspace,
            'model' => ChatManager::MODEL,
            'messages' => $messages,
        ]);
    }

    /**
     * @param string $correlationId
     * @param array<string, mixed> $summary
     * @return void
     */
    public function logResponse(string $correlationId, array $summary): void
    {
        if (!$this->isFull()) {
            return;
        }

        $this->logger->info('chat response', ['cid' => $correlationId] + $summary);
    }

    /**
     * @param string $correlationId
     * @param string $message
     * @param \Throwable|null $cause
     * @return void
     */
    public function logError(string $correlationId, string $message, ?\Throwable $cause = null): void
    {
        if ($this->chatSettings->getLogLevel() === LogLevel::OFF) {
            return;
        }

        $context = ['cid' => $correlationId];
        if ($cause !== null) {
            $context['exception'] = $cause::class . ': ' . $cause->getMessage();
        }

        $this->logger->error('chat error: ' . $message, $context);
    }

    /**
     * @return bool
     */
    private function isFull(): bool
    {
        return $this->chatSettings->getLogLevel() === LogLevel::FULL;
    }
}
