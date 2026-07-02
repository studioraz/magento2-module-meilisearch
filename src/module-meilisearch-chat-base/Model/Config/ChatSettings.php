<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\Encryptor;
use Magento\Store\Model\ScopeInterface;
use Walkwizus\MeilisearchChatBase\Model\Config\Source\LogLevel;

class ChatSettings
{
    public const XML_PATH_ENABLED = 'meilisearch_chat/settings/enabled';
    public const XML_PATH_CHAT_API_KEY = 'meilisearch_chat/settings/chat_api_key';
    public const XML_PATH_WORKSPACE = 'meilisearch_chat/settings/workspace';
    public const XML_PATH_LOG_LEVEL = 'meilisearch_chat/settings/log_level';
    public const XML_PATH_WIDGET_TITLE = 'meilisearch_chat/display/widget_title';
    public const XML_PATH_WELCOME_MESSAGE = 'meilisearch_chat/display/welcome_message';
public const XML_PATH_DISCLAIMER_TEXT        = 'meilisearch_chat/display/disclaimer_text';
    public const XML_PATH_BUTTON_POSITION        = 'meilisearch_chat/display/button_position';
    public const XML_PATH_BUTTON_BOTTOM_DISTANCE = 'meilisearch_chat/display/button_bottom_distance';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Encryptor $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Encryptor $encryptor
    ) { }

    /**
     * @param int|string|null $store
     * @return bool
     */
    public function isEnabled($store = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * @param int|string|null $store
     * @return string
     * @throws \Exception
     */
    public function getChatApiKey($store = null): string
    {
        $key = $this->getValue(self::XML_PATH_CHAT_API_KEY, $store);
        return $key !== '' ? $this->encryptor->decrypt($key) : '';
    }

    /**
     * @param int|string|null $store
     * @return string
     */
    public function getWorkspace($store = null): string
    {
        return $this->getValue(self::XML_PATH_WORKSPACE, $store);
    }

    /**
     * @param int|string|null $store
     * @return string
     */
    public function getLogLevel($store = null): string
    {
        $value = $this->getValue(self::XML_PATH_LOG_LEVEL, $store);
        return $value !== '' ? $value : LogLevel::ERRORS;
    }

    /**
     * @param int|string|null $store
     * @return string
     */
    public function getWidgetTitle($store = null): string
    {
        return $this->getValue(self::XML_PATH_WIDGET_TITLE, $store);
    }

    /**
     * @param int|string|null $store
     * @return string
     */
    public function getWelcomeMessage($store = null): string
    {
        return $this->getValue(self::XML_PATH_WELCOME_MESSAGE, $store);
    }

    /**
     * @param int|string|null $store
     * @return string
     */
    public function getDisclaimerText($store = null): string
    {
        return $this->getValue(self::XML_PATH_DISCLAIMER_TEXT, $store);
    }

    public function getButtonPosition($store = null): string
    {
        $val = $this->getValue(self::XML_PATH_BUTTON_POSITION, $store);
        return in_array($val, ['left', 'right', 'auto'], true) ? $val : 'auto';
    }

    public function getButtonBottomDistance($store = null): string
    {
        return $this->getValue(self::XML_PATH_BUTTON_BOTTOM_DISTANCE, $store) ?: '1rem';
    }

    /**
     * @param string $path
     * @param int|string|null $store
     * @return string
     */
    private function getValue(string $path, $store = null): string
    {
        return (string) ($this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store) ?? '');
    }
}
