<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Response;

use Magento\Framework\App\Http\Context;
use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Session\Config\ConfigInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime;
use Psr\Http\Message\StreamInterface;
use Walkwizus\MeilisearchChatBase\Service\ChatLogger;
use Walkwizus\MeilisearchChatBase\Service\StreamEmitter;
use Walkwizus\MeilisearchChatBase\Service\StreamTransformer;

/**
 * Magento HTTP response that streams a transformed Meilisearch completion as SSE.
 */
class SseResponse extends Http implements NotCacheableInterface
{
    /**
     * @var Http
     */
    private Http $response;

    /**
     * @var StreamInterface
     */
    private StreamInterface $stream;

    /**
     * @var string
     */
    private string $correlationId;

    /**
     * @param HttpRequest $request
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param Context $context
     * @param DateTime $dateTime
     * @param ConfigInterface $sessionConfig
     * @param Http $response
     * @param Json $json
     * @param StreamTransformer $transformer
     * @param ChatLogger $chatLogger
     * @param StreamEmitter $emitter
     * @param array $options Response options containing stream and correlation_id
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        HttpRequest $request,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        Context $context,
        DateTime $dateTime,
        ConfigInterface $sessionConfig,
        Http $response,
        private readonly Json $json,
        private readonly StreamTransformer $transformer,
        private readonly ChatLogger $chatLogger,
        private readonly StreamEmitter $emitter,
        array $options = []
    ) {
        parent::__construct($request, $cookieManager, $cookieMetadataFactory, $context, $dateTime, $sessionConfig);

        $stream = $options['stream'] ?? null;
        if (!$stream instanceof StreamInterface) {
            throw new LocalizedException(__('The SSE response requires a stream.'));
        }

        $correlationId = $options['correlation_id'] ?? null;
        if (!\is_string($correlationId) || trim($correlationId) === '') {
            throw new LocalizedException(__('The SSE response requires a correlation ID.'));
        }

        $this->response = $response;
        $this->stream = $stream;
        $this->correlationId = $correlationId;
        $this->configureHeaders();
    }

    /**
     * @inheritDoc
     */
    public function sendResponse()
    {
        $headersSent = false;

        try {
            $this->response->clearBody();
            $this->emitter->prepareEnvironment();
            $this->response->sendHeaders();
            $headersSent = true;

            $this->emitter->emit($this->event('meta', ['id' => $this->correlationId]));
            $start = microtime(true);
            $summary = $this->transformer->transform(
                $this->stream,
                $this->emitter->emit(...),
                $this->emitter->isClientAborted(...)
            );

            $this->safeLogResponse([
                'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                'answer' => $summary['text'],
                'product_ids' => $summary['product_ids'],
                'aborted' => $summary['aborted'],
                'completed' => $summary['completed'],
            ]);
        } catch (\Throwable $exception) {
            $this->safeLogError('stream failure', $exception);
            if ($headersSent) {
                $this->emitSafeFailure();
            }
        } finally {
            $this->closeStream();
        }

        // @phpstan-ignore-next-line return.void -- Mirrors Magento's File response fluent return.
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setHeader($name, $value, $replace = false)
    {
        $this->response->setHeader($name, $value, $replace);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getHeader($name)
    {
        return $this->response->getHeader($name);
    }

    /**
     * @inheritDoc
     */
    public function clearHeader($name)
    {
        $this->response->clearHeader($name);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function clearHeaders()
    {
        $this->response->clearHeaders();
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setBody($value)
    {
        $this->response->setBody($value);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function appendBody($value)
    {
        $this->response->appendBody($value);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function clearBody()
    {
        $this->response->clearBody();
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getContent()
    {
        return $this->response->getContent();
    }

    /**
     * @inheritDoc
     */
    public function setContent($value)
    {
        $this->response->setContent($value);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setHttpResponseCode($code)
    {
        $this->response->setHttpResponseCode($code);
        // The concrete Magento HTTP response is fluent despite its interface documentation.
        // @phpstan-ignore-next-line return.void
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getHttpResponseCode()
    {
        return $this->response->getHttpResponseCode();
    }

    /**
     * @inheritDoc
     */
    public function setStatusCode($code)
    {
        $this->response->setStatusCode($code);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getStatusCode()
    {
        return $this->response->getStatusCode();
    }

    /**
     * @inheritDoc
     */
    public function setStatusHeader($httpCode, $version = null, $phrase = null)
    {
        $this->response->setStatusHeader($httpCode, $version, $phrase);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setRedirect($url, $code = 302)
    {
        $this->response->setRedirect($url, $code);
        return $this;
    }

    /**
     * Apply headers early so Magento response observers can inspect or amend them.
     *
     * @return void
     */
    private function configureHeaders(): void
    {
        $this->response->setHeader('Content-Type', 'text/event-stream; charset=utf-8', true);
        $this->response->setHeader(
            'Cache-Control',
            'no-cache, no-store, must-revalidate, max-age=0, no-transform',
            true
        );
        $this->response->setHeader('Pragma', 'no-cache', true);
        $this->response->setHeader('X-Accel-Buffering', 'no', true);
        $this->response->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->response->clearHeader('Connection');
        $this->response->clearHeader('Content-Length');
        $this->response->setHttpResponseCode(200);
    }

    /**
     * Format a named SSE event.
     *
     * @param string $name
     * @param array $data
     * @return string
     */
    private function event(string $name, array $data): string
    {
        return 'event: ' . $name . "\n" . 'data: ' . $this->json->serialize($data) . "\n\n";
    }

    /**
     * Emit a user-safe terminal event when a post-header failure occurs.
     *
     * @return void
     */
    private function emitSafeFailure(): void
    {
        try {
            if ($this->emitter->isClientAborted()) {
                return;
            }

            $this->emitter->emit($this->event('error', [
                'message' => __('The assistant was interrupted. Please try again.')->render(),
            ]));
            $this->emitter->emit("data: [DONE]\n\n");
        } catch (\Throwable $exception) {
            $this->safeLogError('stream error response failure', $exception);
        }
    }

    /**
     * Close the upstream response without letting cleanup replace the streamed response.
     *
     * @return void
     */
    private function closeStream(): void
    {
        try {
            $this->stream->close();
        } catch (\Throwable $exception) {
            $this->safeLogError('stream close failure', $exception);
        }
    }

    /**
     * Log a completed stream without allowing a logging failure to alter its wire response.
     *
     * @param array $summary
     * @return void
     */
    private function safeLogResponse(array $summary): void
    {
        try {
            $this->chatLogger->logResponse($this->correlationId, $summary);
        } catch (\Throwable $exception) {
            $this->safeLogError('response logging failure', $exception);
        }
    }

    /**
     * Log a transport failure without allowing the logging backend to break streaming cleanup.
     *
     * @param string $message
     * @param \Throwable $exception
     * @return void
     */
    private function safeLogError(string $message, \Throwable $exception): void
    {
        try {
            $this->chatLogger->logError($this->correlationId, $message, $exception);
        } catch (\Throwable) {
            // Streaming and cleanup must remain no-throw when the logging backend is unavailable.
            return;
        }
    }
}
