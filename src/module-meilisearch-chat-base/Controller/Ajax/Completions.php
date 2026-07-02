<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Session\SessionManagerInterface;
use Psr\Http\Message\StreamInterface;
use Walkwizus\MeilisearchChatBase\Exception\ChatException;
use Walkwizus\MeilisearchChatBase\Model\Config\ChatSettings;
use Walkwizus\MeilisearchChatBase\Service\ChatLogger;
use Walkwizus\MeilisearchChatBase\Service\ChatManager;
use Walkwizus\MeilisearchChatBase\Service\MessageSanitizer;
use Walkwizus\MeilisearchChatBase\Service\RateLimiter;
use Walkwizus\MeilisearchChatBase\Service\StreamTransformer;

/**
 * Storefront streaming proxy: receives the chat history, opens an upstream
 * Meilisearch chat completion, and rewrites the SSE for the browser (text +
 * event:products/status/error). The chat key stays server-side; the browser
 * never talks to Meilisearch directly.
 */
class Completions implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @param JsonFactory $jsonFactory
     * @param FormKey $formKey
     * @param SessionManagerInterface $session
     * @param RemoteAddress $remoteAddress
     * @param ChatSettings $chatSettings
     * @param MessageSanitizer $sanitizer
     * @param RateLimiter $rateLimiter
     * @param ChatManager $chatManager
     * @param StreamTransformer $transformer
     * @param ChatLogger $chatLogger
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly JsonFactory $jsonFactory,
        private readonly FormKey $formKey,
        private readonly SessionManagerInterface $session,
        private readonly RemoteAddress $remoteAddress,
        private readonly ChatSettings $chatSettings,
        private readonly MessageSanitizer $sanitizer,
        private readonly RateLimiter $rateLimiter,
        private readonly ChatManager $chatManager,
        private readonly StreamTransformer $transformer,
        private readonly ChatLogger $chatLogger
    ) { }

    /**
     * @return ResultInterface|HttpResponse
     */
    public function execute()
    {
        $correlationId = bin2hex(random_bytes(8));

        if (!$this->chatSettings->isEnabled()) {
            return $this->jsonError(__('The assistant is not available.'), 404);
        }

        // --- Request-time validation (clean JSON errors, no stream opened yet) ---
        try {
            $body = json_decode((string) $this->request->getContent(), true);
            $messages = $this->sanitizer->sanitize(\is_array($body) ? ($body['messages'] ?? null) : null);
        } catch (ChatException $e) {
            return $this->jsonError($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->jsonError(__('Please enter a message.'), 400);
        }

        try {
            $this->rateLimiter->assert($this->rateIdentifier());
        } catch (ChatException $e) {
            return $this->jsonError($e->getMessage(), 429);
        }

        // --- Open upstream. Call-time failures (bad key / workspace / network) ---
        try {
            $stream = $this->chatManager->streamCompletion($messages);
        } catch (ChatException $e) {
            $this->chatLogger->logError($correlationId, $e->getMessage(), $e->getPrevious());
            return $this->jsonError($e->getMessage(), 503);
        }

        $this->chatLogger->logRequest($correlationId, $this->chatSettings->getWorkspace(), $messages);

        return $this->stream($stream, $correlationId);
    }

    /**
     * @param StreamInterface $stream
     * @param string $correlationId
     * @return HttpResponse
     */
    private function stream(StreamInterface $stream, string $correlationId): HttpResponse
    {
        $this->prepareEnvironment();

        $this->response->setHeader('Content-Type', 'text/event-stream; charset=utf-8', true);
        $this->response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate', true);
        $this->response->setHeader('Pragma', 'no-cache', true);
        $this->response->setHeader('X-Accel-Buffering', 'no', true);
        $this->response->setHeader('Connection', 'keep-alive', true);
        $this->response->sendHeaders();

        // Release the PHP session lock so the shopper can keep browsing while the
        // (potentially long) answer streams.
        $this->session->writeClose();

        $emit = static function (string $payload): void {
            echo $payload;
            flush();
        };

        $emit('event: meta' . "\n" . 'data: ' . json_encode(['id' => $correlationId]) . "\n\n");

        $start = microtime(true);
        try {
            $summary = $this->transformer->transform(
                $stream,
                $emit,
                static fn (): bool => connection_aborted() === 1
            );
        } catch (\Throwable $e) {
            $this->chatLogger->logError($correlationId, 'stream failure', $e);
            $emit('event: error' . "\n" . 'data: '
                . json_encode(['message' => __('The assistant was interrupted. Please try again.')->render()]) . "\n\n");
            $emit("data: [DONE]\n\n");

            return $this->response;
        }

        $this->chatLogger->logResponse($correlationId, [
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
            'answer' => $summary['text'],
            'product_ids' => $summary['product_ids'],
            'aborted' => $summary['aborted'],
            'completed' => $summary['completed'],
        ]);

        return $this->response;
    }

    /**
     * Disable output buffering / gzip so SSE flushes immediately.
     *
     * @return void
     */
    private function prepareEnvironment(): void
    {
        set_time_limit(0);
        ignore_user_abort(false);

        if (\function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);
    }

    /**
     * @return string
     */
    private function rateIdentifier(): string
    {
        return $this->session->getSessionId() . '|' . (string) $this->remoteAddress->getRemoteAddress();
    }

    /**
     * @param \Magento\Framework\Phrase $message
     * @param int $httpCode
     * @return ResultInterface
     */
    private function jsonError(\Magento\Framework\Phrase $message, int $httpCode): ResultInterface
    {
        return $this->jsonFactory->create()
            ->setHttpResponseCode($httpCode)
            ->setData(['error' => $message->render()]);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $headerKey = (string) ($this->request->getHeader('X-Magento-Form-Key') ?: '');
        $formKey = $headerKey !== '' ? $headerKey : (string) $request->getParam('form_key');

        return $formKey !== '' && hash_equals((string) $this->formKey->getFormKey(), $formKey);
    }
}
