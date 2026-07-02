<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Service;

use Walkwizus\MeilisearchChatBase\Exception\ChatException;

/**
 * Normalizes client-supplied chat history before it is forwarded upstream.
 * Drops anything that could be used for prompt-/role-injection (only `user`
 * and `assistant` roles survive — a client `system` message is discarded, the
 * system prompt lives in the Meilisearch workspace) and enforces size caps for
 * cost protection.
 */
class MessageSanitizer
{
    public const MAX_MESSAGE_LENGTH = 4000;
    public const MAX_MESSAGES = 30;
    private const ALLOWED_ROLES = ['user', 'assistant'];

    /**
     * @param mixed $raw Decoded `messages` from the request body
     * @return array<int, array{role: string, content: string}>
     * @throws ChatException
     */
    public function sanitize(mixed $raw): array
    {
        if (!\is_array($raw) || $raw === []) {
            throw new ChatException(__('Please enter a message.'));
        }

        $messages = [];
        foreach ($raw as $message) {
            if (!\is_array($message)) {
                continue;
            }

            $role = isset($message['role']) && \is_string($message['role']) ? $message['role'] : '';
            if (!\in_array($role, self::ALLOWED_ROLES, true)) {
                continue;
            }

            $content = isset($message['content']) && \is_string($message['content']) ? trim($message['content']) : '';
            if ($content === '') {
                continue;
            }

            if (mb_strlen($content) > self::MAX_MESSAGE_LENGTH) {
                $content = mb_substr($content, 0, self::MAX_MESSAGE_LENGTH);
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        if ($messages === []) {
            throw new ChatException(__('Please enter a message.'));
        }

        if (\count($messages) > self::MAX_MESSAGES) {
            $messages = \array_slice($messages, -self::MAX_MESSAGES);
        }

        if (end($messages)['role'] !== 'user') {
            throw new ChatException(__('Please enter a message.'));
        }

        return array_values($messages);
    }
}
