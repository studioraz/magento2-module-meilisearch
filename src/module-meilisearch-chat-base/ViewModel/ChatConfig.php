<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\ViewModel;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Walkwizus\MeilisearchChatBase\Model\Config\ChatSettings;

/**
 * Exposes safe, presentation-facing config to the storefront chat widget.
 * Never exposes the chat API key or the Meilisearch host — the browser only
 * ever talks to the same-origin Magento proxy.
 */
class ChatConfig implements ArgumentInterface
{
    /**
     * @param ChatSettings $chatSettings
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly ChatSettings $chatSettings,
        private readonly UrlInterface $url
    ) { }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->chatSettings->isEnabled();
    }

    /**
     * Same-origin streaming proxy URL.
     *
     * @return string
     */
    public function getCompletionsUrl(): string
    {
        return $this->url->getUrl('meilisearchchat/ajax/completions');
    }

    /**
     * @return string
     */
    public function getAddToCartUrl(): string
    {
        return $this->url->getUrl('checkout/cart/add');
    }

    /**
     * @return string
     */
    public function getWidgetTitle(): string
    {
        return $this->chatSettings->getWidgetTitle();
    }

    /**
     * @return string
     */
    public function getWelcomeMessage(): string
    {
        return $this->chatSettings->getWelcomeMessage();
    }

    /**
     * @return string
     */
    public function getDisclaimerText(): string
    {
        return $this->chatSettings->getDisclaimerText();
    }

    public function getButtonPosition(): string
    {
        return $this->chatSettings->getButtonPosition();
    }

    public function getButtonBottomDistance(): string
    {
        return $this->chatSettings->getButtonBottomDistance();
    }

    /**
     * Frontend config bundle for the Alpine component (JSON-encoded into a data attr).
     *
     * @return array<string, mixed>
     */
    public function getJsConfig(): array
    {
        return [
            'completionsUrl' => $this->getCompletionsUrl(),
            'addToCartUrl' => $this->getAddToCartUrl(),
            'widgetTitle' => $this->getWidgetTitle(),
            'welcomeMessage' => $this->getWelcomeMessage(),
        ];
    }
}
