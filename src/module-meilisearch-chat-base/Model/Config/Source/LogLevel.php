<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogLevel implements OptionSourceInterface
{
    public const OFF = 'off';
    public const ERRORS = 'errors';
    public const FULL = 'full';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::OFF, 'label' => __('Off')],
            ['value' => self::ERRORS, 'label' => __('Errors only')],
            ['value' => self::FULL, 'label' => __('Full (request + response)')],
        ];
    }
}
