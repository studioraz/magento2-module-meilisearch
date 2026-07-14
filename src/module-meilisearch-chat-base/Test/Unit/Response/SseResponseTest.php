<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Test\Unit\Response;

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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Walkwizus\MeilisearchChatBase\Response\SseResponse;
use Walkwizus\MeilisearchChatBase\Service\ChatLogger;
use Walkwizus\MeilisearchChatBase\Service\StreamEmitter;
use Walkwizus\MeilisearchChatBase\Service\StreamTransformer;

class SseResponseTest extends TestCase
{
    /**
     * @var HttpRequest|MockObject
     */
    private HttpRequest $request;

    /**
     * @var CookieManagerInterface|MockObject
     */
    private CookieManagerInterface $cookieManager;

    /**
     * @var CookieMetadataFactory|MockObject
     */
    private CookieMetadataFactory $cookieMetadataFactory;

    /**
     * @var Context|MockObject
     */
    private Context $context;

    /**
     * @var DateTime|MockObject
     */
    private DateTime $dateTime;

    /**
     * @var ConfigInterface|MockObject
     */
    private ConfigInterface $sessionConfig;

    /**
     * @var Http|MockObject
     */
    private Http $httpResponse;

    /**
     * @var StreamTransformer|MockObject
     */
    private StreamTransformer $transformer;

    /**
     * @var ChatLogger|MockObject
     */
    private ChatLogger $chatLogger;

    /**
     * @var StreamEmitter|MockObject
     */
    private StreamEmitter $emitter;

    /**
     * @var StreamInterface|MockObject
     */
    private StreamInterface $stream;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->cookieMetadataFactory = $this->createMock(CookieMetadataFactory::class);
        $this->context = $this->createMock(Context::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->sessionConfig = $this->createMock(ConfigInterface::class);
        $this->httpResponse = $this->getMockBuilder(Http::class)
            ->setConstructorArgs($this->httpDependencies())
            ->onlyMethods(['sendHeaders'])
            ->getMock();
        $this->transformer = $this->createMock(StreamTransformer::class);
        $this->chatLogger = $this->createMock(ChatLogger::class);
        $this->emitter = $this->createMock(StreamEmitter::class);
        $this->stream = $this->createMock(StreamInterface::class);
    }

    public function testConstructorRequiresStream(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The SSE response requires a stream.');

        $this->createResponse(['correlation_id' => 'cid-1']);
    }

    public function testConstructorRequiresCorrelationId(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The SSE response requires a correlation ID.');

        $this->createResponse(['stream' => $this->stream, 'correlation_id' => '  ']);
    }

    public function testConstructorConfiguresNonCacheableSseResponse(): void
    {
        $this->httpResponse->setHeader('Connection', 'keep-alive', true);
        $this->httpResponse->setHeader('Content-Length', '123', true);

        $response = $this->createResponse();

        self::assertInstanceOf(NotCacheableInterface::class, $response);
        self::assertSame('text/event-stream; charset=utf-8', $this->headerValue('Content-Type'));
        self::assertSame(
            'max-age=0, must-revalidate, no-cache, no-store, no-transform',
            $this->headerValue('Cache-Control')
        );
        self::assertSame('no-cache', $this->headerValue('Pragma'));
        self::assertSame('no', $this->headerValue('X-Accel-Buffering'));
        self::assertSame('nosniff', $this->headerValue('X-Content-Type-Options'));
        self::assertFalse($this->httpResponse->getHeader('Connection'));
        self::assertFalse($this->httpResponse->getHeader('Content-Length'));
        self::assertSame(200, $this->httpResponse->getHttpResponseCode());
    }

    public function testDelegatesBodyHeadersAndStatusToWrappedResponse(): void
    {
        $response = $this->createResponse();

        self::assertSame($response, $response->setHeader('X-Test', 'yes', true));
        self::assertSame('yes', $this->headerValue('X-Test'));
        self::assertSame($response, $response->setBody('first'));
        self::assertSame($response, $response->appendBody('-second'));
        self::assertSame('first-second', $response->getContent());
        self::assertSame($response, $response->clearBody());
        self::assertSame('', $this->httpResponse->getContent());
        self::assertSame($response, $response->setHttpResponseCode(202));
        self::assertSame(202, $response->getHttpResponseCode());
        self::assertSame(202, $response->getStatusCode());
        self::assertSame($response, $response->setRedirect('/next', 307));
        self::assertSame(307, $this->httpResponse->getStatusCode());
        self::assertSame('/next', $this->headerValue('Location'));
    }

    public function testSendResponseEmitsMetaTransformsLogsAndClosesStream(): void
    {
        $calls = [];
        $frames = [];
        $summary = [
            'text' => 'answer',
            'product_ids' => [11, 12],
            'aborted' => false,
            'completed' => true,
        ];

        $this->httpResponse->setContent('unexpected output');
        $this->httpResponse->expects(self::once())
            ->method('sendHeaders')
            ->willReturnCallback(function () use (&$calls): Http {
                $calls[] = 'headers';
                return $this->httpResponse;
            });
        $this->emitter->expects(self::once())
            ->method('prepareEnvironment')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'prepare';
            });
        $this->emitter->expects(self::exactly(2))
            ->method('emit')
            ->willReturnCallback(static function (string $payload) use (&$frames): void {
                $frames[] = $payload;
            });
        $this->emitter->expects(self::once())->method('isClientAborted')->willReturn(false);
        $this->transformer->expects(self::once())
            ->method('transform')
            ->willReturnCallback(
                function (StreamInterface $stream, callable $emit, callable $aborted) use ($summary): array {
                    self::assertSame($this->stream, $stream);
                    self::assertFalse($aborted());
                    $emit("data: transformed\n\n");
                    return $summary;
                }
            );
        $this->chatLogger->expects(self::once())
            ->method('logResponse')
            ->with('cid-1', self::callback(static function (array $logged) use ($summary): bool {
                return \is_int($logged['elapsed_ms'] ?? null)
                    && ($logged['answer'] ?? null) === $summary['text']
                    && ($logged['product_ids'] ?? null) === $summary['product_ids']
                    && ($logged['aborted'] ?? null) === $summary['aborted']
                    && ($logged['completed'] ?? null) === $summary['completed'];
            }));
        $this->chatLogger->expects(self::never())->method('logError');
        $this->stream->expects(self::once())->method('close');

        $response = $this->createResponse();

        // @phpstan-ignore-next-line method.void -- Runtime behavior intentionally mirrors Magento's File response.
        self::assertSame($response, $response->sendResponse());
        self::assertSame(['prepare', 'headers'], $calls);
        self::assertSame('', $this->httpResponse->getContent());
        self::assertSame("event: meta\ndata: {\"id\":\"cid-1\"}\n\n", $frames[0]);
        self::assertSame("data: transformed\n\n", $frames[1]);
    }

    public function testTransformFailureEmitsSafeTerminalFramesAndClosesStream(): void
    {
        $exception = new \RuntimeException('private upstream failure');
        $frames = [];

        $this->httpResponse->expects(self::once())->method('sendHeaders')->willReturnSelf();
        $this->emitter->expects(self::once())->method('prepareEnvironment');
        $this->emitter->expects(self::exactly(3))
            ->method('emit')
            ->willReturnCallback(static function (string $payload) use (&$frames): void {
                $frames[] = $payload;
            });
        $this->emitter->expects(self::once())->method('isClientAborted')->willReturn(false);
        $this->transformer->expects(self::once())->method('transform')->willThrowException($exception);
        $this->chatLogger->expects(self::once())
            ->method('logError')
            ->with('cid-1', 'stream failure', $exception);
        $this->chatLogger->expects(self::never())->method('logResponse');
        $this->stream->expects(self::once())->method('close');

        $this->createResponse()->sendResponse();

        $joined = implode('', $frames);
        self::assertStringContainsString('event: error', $joined);
        self::assertStringContainsString('The assistant was interrupted. Please try again.', $joined);
        self::assertStringNotContainsString('private upstream failure', $joined);
        self::assertStringEndsWith("data: [DONE]\n\n", $joined);
    }

    public function testTransformFailureDoesNotEmitTerminalFramesAfterClientAbort(): void
    {
        $exception = new \RuntimeException('stream failed');
        $frames = [];

        $this->httpResponse->expects(self::once())->method('sendHeaders')->willReturnSelf();
        $this->emitter->expects(self::once())->method('prepareEnvironment');
        $this->emitter->expects(self::once())
            ->method('emit')
            ->willReturnCallback(static function (string $payload) use (&$frames): void {
                $frames[] = $payload;
            });
        $this->emitter->expects(self::once())->method('isClientAborted')->willReturn(true);
        $this->transformer->expects(self::once())->method('transform')->willThrowException($exception);
        $this->chatLogger->expects(self::once())->method('logError');
        $this->stream->expects(self::once())->method('close');

        $this->createResponse()->sendResponse();

        self::assertCount(1, $frames);
        self::assertStringContainsString('event: meta', $frames[0]);
    }

    public function testResponseLoggerFailureDoesNotEmitAnotherTerminalSequenceOrEscape(): void
    {
        $loggingException = new \RuntimeException('logging backend failed');
        $frames = [];
        $summary = [
            'text' => 'complete',
            'product_ids' => [],
            'aborted' => false,
            'completed' => true,
        ];

        $this->httpResponse->expects(self::once())->method('sendHeaders')->willReturnSelf();
        $this->emitter->expects(self::once())->method('prepareEnvironment');
        $this->emitter->expects(self::exactly(2))
            ->method('emit')
            ->willReturnCallback(static function (string $payload) use (&$frames): void {
                $frames[] = $payload;
            });
        $this->emitter->expects(self::never())->method('isClientAborted');
        $this->transformer->expects(self::once())
            ->method('transform')
            ->willReturnCallback(static function (
                StreamInterface $stream,
                callable $emit,
                callable $aborted
            ) use ($summary): array {
                $emit("data: [DONE]\n\n");
                return $summary;
            });
        $this->chatLogger->expects(self::once())
            ->method('logResponse')
            ->willThrowException($loggingException);
        $this->chatLogger->expects(self::once())
            ->method('logError')
            ->with('cid-1', 'response logging failure', $loggingException)
            ->willThrowException(new \RuntimeException('error logger also failed'));
        $this->stream->expects(self::once())->method('close');

        $this->createResponse()->sendResponse();

        $joined = implode('', $frames);
        self::assertSame(1, substr_count($joined, "data: [DONE]\n\n"));
        self::assertStringNotContainsString('event: error', $joined);
    }

    public function testCloseAndErrorLoggerFailuresDoNotEscapeCleanup(): void
    {
        $closeException = new \RuntimeException('close failed');
        $summary = [
            'text' => '',
            'product_ids' => [],
            'aborted' => false,
            'completed' => true,
        ];

        $this->httpResponse->expects(self::once())->method('sendHeaders')->willReturnSelf();
        $this->emitter->expects(self::once())->method('prepareEnvironment');
        $this->emitter->expects(self::once())->method('emit');
        $this->transformer->expects(self::once())->method('transform')->willReturn($summary);
        $this->chatLogger->expects(self::once())->method('logResponse');
        $this->chatLogger->expects(self::once())
            ->method('logError')
            ->with('cid-1', 'stream close failure', $closeException)
            ->willThrowException(new \RuntimeException('error logger failed'));
        $this->stream->expects(self::once())->method('close')->willThrowException($closeException);

        $this->createResponse()->sendResponse();
    }

    public function testHeaderFailureStillClosesStreamWithoutWritingBody(): void
    {
        $exception = new \RuntimeException('headers failed');

        $this->httpResponse->expects(self::once())->method('sendHeaders')->willThrowException($exception);
        $this->emitter->expects(self::once())->method('prepareEnvironment');
        $this->emitter->expects(self::never())->method('emit');
        $this->transformer->expects(self::never())->method('transform');
        $this->chatLogger->expects(self::once())
            ->method('logError')
            ->with('cid-1', 'stream failure', $exception);
        $this->stream->expects(self::once())->method('close');

        $this->createResponse()->sendResponse();
    }

    /**
     * @param array<string, mixed>|null $options
     * @return SseResponse
     */
    private function createResponse(?array $options = null): SseResponse
    {
        return new SseResponse(
            $this->request,
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->context,
            $this->dateTime,
            $this->sessionConfig,
            $this->httpResponse,
            new Json(),
            $this->transformer,
            $this->chatLogger,
            $this->emitter,
            $options ?? ['stream' => $this->stream, 'correlation_id' => 'cid-1']
        );
    }

    /**
     * @return array<int, object>
     */
    private function httpDependencies(): array
    {
        return [
            $this->request,
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->context,
            $this->dateTime,
            $this->sessionConfig,
        ];
    }

    /**
     * @param string $name
     * @return string
     */
    private function headerValue(string $name): string
    {
        $header = $this->httpResponse->getHeader($name);
        self::assertNotFalse($header);

        return $header->getFieldValue();
    }
}
