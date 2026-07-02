<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown for chat proxy failures. Carries a user-safe message (shown to the
 * shopper via the SSE error event) separate from any internal detail that is
 * only ever logged server-side.
 */
class ChatException extends LocalizedException
{
    /**
     * @param Phrase $phrase User-safe message
     * @param \Exception|null $cause
     */
    public function __construct(Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
    }
}
