<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ButtonPosition implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto',  'label' => __('Automatic (follows theme direction)')],
            ['value' => 'left',  'label' => __('Bottom Left')],
            ['value' => 'right', 'label' => __('Bottom Right')],
        ];
    }
}
