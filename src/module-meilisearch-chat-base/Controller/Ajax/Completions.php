<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Session\SessionManagerInterface;
use Walkwizus\MeilisearchChatBase\Exception\ChatException;
use Walkwizus\MeilisearchChatBase\Model\Config\ChatSettings;
use Walkwizus\MeilisearchChatBase\Response\SseResponse;
use Walkwizus\MeilisearchChatBase\Response\SseResponseFactory;
use Walkwizus\MeilisearchChatBase\Service\ChatLogger;
use Walkwizus\MeilisearchChatBase\Service\ChatManager;
use Walkwizus\MeilisearchChatBase\Service\MessageSanitizer;
use Walkwizus\MeilisearchChatBase\Service\RateLimiter;

/**
 * Storefront proxy for streaming Meilisearch chat completions.
 *
 * The controller validates the request and delegates the long-lived SSE output
 * to a dedicated response so the chat key remains server-side.
 */
class Completions implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param JsonFactory $jsonFactory
     * @param JsonSerializer $jsonSerializer
     * @param SseResponseFactory $sseResponseFactory
     * @param FormKey $formKey
     * @param SessionManagerInterface $session
     * @param RemoteAddress $remoteAddress
     * @param ChatSettings $chatSettings
     * @param MessageSanitizer $sanitizer
     * @param RateLimiter $rateLimiter
     * @param ChatManager $chatManager
     * @param ChatLogger $chatLogger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly JsonSerializer $jsonSerializer,
        private readonly SseResponseFactory $sseResponseFactory,
        private readonly FormKey $formKey,
        private readonly SessionManagerInterface $session,
        private readonly RemoteAddress $remoteAddress,
        private readonly ChatSettings $chatSettings,
        private readonly MessageSanitizer $sanitizer,
        private readonly RateLimiter $rateLimiter,
        private readonly ChatManager $chatManager,
        private readonly ChatLogger $chatLogger
    ) {
    }

    /**
     * Validate the chat request and create its JSON or SSE response.
     *
     * @return Json|SseResponse
     */
    public function execute(): Json|SseResponse
    {
        $correlationId = bin2hex(random_bytes(8));

        if (!$this->chatSettings->isEnabled()) {
            return $this->jsonError(__('The assistant is not available.'), 404);
        }

        try {
            $body = $this->jsonSerializer->unserialize((string) $this->request->getContent());
        } catch (\InvalidArgumentException) {
            return $this->jsonError(__('Please enter a message.'), 400);
        }

        try {
            $messages = $this->sanitizer->sanitize(\is_array($body) ? ($body['messages'] ?? null) : null);
        } catch (ChatException $e) {
            return $this->jsonError($e->getMessage(), 400);
        }

        $rateIdentifier = $this->rateIdentifier();
        try {
            $this->rateLimiter->assert($rateIdentifier);
        } catch (ChatException $e) {
            return $this->jsonError($e->getMessage(), 429);
        }

        // Release the PHP session lock before opening the potentially long-lived
        // upstream response so the shopper can keep browsing during the stream.
        $this->session->writeClose();

        try {
            $stream = $this->chatManager->streamCompletion($messages);
        } catch (ChatException $e) {
            $this->chatLogger->logError($correlationId, $e->getMessage(), $e->getPrevious());

            return $this->jsonError($e->getMessage(), 503);
        }

        $this->chatLogger->logRequest($correlationId, $this->chatSettings->getWorkspace(), $messages);

        return $this->sseResponseFactory->create(['options' => [
            'stream' => $stream,
            'correlation_id' => $correlationId,
        ]]);
    }

    /**
     * Build the rate-limit key from the current session and remote address.
     *
     * @return string
     */
    private function rateIdentifier(): string
    {
        return $this->session->getSessionId() . '|' . (string) $this->remoteAddress->getRemoteAddress();
    }

    /**
     * Create a JSON error response.
     *
     * @param Phrase|string $message
     * @param int $httpCode
     * @return Json
     */
    private function jsonError(Phrase|string $message, int $httpCode): Json
    {
        return $this->jsonFactory->create()
            ->setHttpResponseCode($httpCode)
            ->setData(['error' => (string) $message]);
    }

    /**
     * Create the JSON replacement response for failed CSRF validation.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException(
            $this->jsonError(__('Invalid Form Key. Please refresh the page.'), 403)
        );
    }

    /**
     * Validate the form key from the request header or parameter.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $headerKey = $request instanceof HttpRequest
            ? (string) ($request->getHeader('X-Magento-Form-Key') ?: '')
            : '';
        $formKey = $headerKey !== '' ? $headerKey : (string) $request->getParam('form_key');

        return $formKey !== '' && hash_equals((string) $this->formKey->getFormKey(), $formKey);
    }
}
