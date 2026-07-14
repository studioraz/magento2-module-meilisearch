<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchChatBase\Test\Unit\Controller\Ajax;

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
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Walkwizus\MeilisearchChatBase\Controller\Ajax\Completions;
use Walkwizus\MeilisearchChatBase\Exception\ChatException;
use Walkwizus\MeilisearchChatBase\Model\Config\ChatSettings;
use Walkwizus\MeilisearchChatBase\Response\SseResponse;
use Walkwizus\MeilisearchChatBase\Response\SseResponseFactory;
use Walkwizus\MeilisearchChatBase\Service\ChatLogger;
use Walkwizus\MeilisearchChatBase\Service\ChatManager;
use Walkwizus\MeilisearchChatBase\Service\MessageSanitizer;
use Walkwizus\MeilisearchChatBase\Service\RateLimiter;

class CompletionsTest extends TestCase
{
    private const REQUEST_BODY = '{"messages":[{"role":"user","content":"Food for a puppy"}]}';
    private const MESSAGES = [
        ['role' => 'user', 'content' => 'Food for a puppy'],
    ];

    /** @var HttpRequest */
    private HttpRequest $request;
    /** @var JsonFactory */
    private JsonFactory $jsonFactory;
    /** @var JsonSerializer */
    private JsonSerializer $jsonSerializer;
    /** @var SseResponseFactory */
    private SseResponseFactory $sseResponseFactory;
    /** @var FormKey */
    private FormKey $formKey;
    /** @var SessionManagerInterface */
    private SessionManagerInterface $session;
    /** @var RemoteAddress */
    private RemoteAddress $remoteAddress;
    /** @var ChatSettings */
    private ChatSettings $chatSettings;
    /** @var MessageSanitizer */
    private MessageSanitizer $sanitizer;
    /** @var RateLimiter */
    private RateLimiter $rateLimiter;
    /** @var ChatManager */
    private ChatManager $chatManager;
    /** @var ChatLogger */
    private ChatLogger $chatLogger;
    /** @var Json */
    private Json $jsonResult;
    /** @var Completions */
    private Completions $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->jsonSerializer = $this->createMock(JsonSerializer::class);
        $this->sseResponseFactory = $this->createMock(SseResponseFactory::class);
        $this->formKey = $this->createMock(FormKey::class);
        $this->session = $this->createMock(SessionManagerInterface::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->chatSettings = $this->createMock(ChatSettings::class);
        $this->sanitizer = $this->createMock(MessageSanitizer::class);
        $this->rateLimiter = $this->createMock(RateLimiter::class);
        $this->chatManager = $this->createMock(ChatManager::class);
        $this->chatLogger = $this->createMock(ChatLogger::class);
        $this->jsonResult = $this->createMock(Json::class);

        $this->controller = new Completions(
            $this->request,
            $this->jsonFactory,
            $this->jsonSerializer,
            $this->sseResponseFactory,
            $this->formKey,
            $this->session,
            $this->remoteAddress,
            $this->chatSettings,
            $this->sanitizer,
            $this->rateLimiter,
            $this->chatManager,
            $this->chatLogger
        );
    }

    public function testDisabledAssistantReturnsNotFoundJson(): void
    {
        $this->chatSettings->expects(self::once())->method('isEnabled')->willReturn(false);
        $this->jsonSerializer->expects(self::never())->method('unserialize');
        $this->expectJsonError(404, 'The assistant is not available.');

        self::assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testMalformedJsonReturnsBadRequestWithoutSanitizing(): void
    {
        $this->chatSettings->expects(self::once())->method('isEnabled')->willReturn(true);
        $this->request->expects(self::once())->method('getContent')->willReturn('{broken');
        $this->jsonSerializer->expects(self::once())
            ->method('unserialize')
            ->with('{broken')
            // Magento's JSON serializer reports malformed input with this exception.
            ->willThrowException(new \InvalidArgumentException('Unable to unserialize value.'));
        $this->sanitizer->expects(self::never())->method('sanitize');
        $this->expectJsonError(400, 'Please enter a message.');

        self::assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testSanitizerFailureReturnsItsMessageAsBadRequest(): void
    {
        $this->expectDecodedRequest();
        $this->sanitizer->expects(self::once())
            ->method('sanitize')
            ->with(self::MESSAGES)
            ->willThrowException(new ChatException(new Phrase('Please enter a message.')));
        $this->rateLimiter->expects(self::never())->method('assert');
        $this->expectJsonError(400, 'Please enter a message.');

        self::assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testUnexpectedSanitizerExceptionIsNotTreatedAsMalformedJson(): void
    {
        $this->expectDecodedRequest();
        $this->sanitizer->expects(self::once())
            ->method('sanitize')
            ->with(self::MESSAGES)
            ->willThrowException(new \InvalidArgumentException('Unexpected sanitizer failure.'));
        $this->jsonFactory->expects(self::never())->method('create');
        $this->rateLimiter->expects(self::never())->method('assert');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected sanitizer failure.');

        $this->controller->execute();
    }

    public function testRateLimitFailureReturnsTooManyRequestsWithoutClosingSession(): void
    {
        $this->expectValidMessages();
        $this->expectRateIdentifier();
        $this->rateLimiter->expects(self::once())
            ->method('assert')
            ->with('session-id|192.0.2.10')
            ->willThrowException(new ChatException(new Phrase('Too many requests. Please slow down and try again.')));
        $this->session->expects(self::never())->method('writeClose');
        $this->chatManager->expects(self::never())->method('streamCompletion');
        $this->expectJsonError(429, 'Too many requests. Please slow down and try again.');

        self::assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testUpstreamFailureIsLoggedAndReturnsServiceUnavailable(): void
    {
        $cause = new \RuntimeException('Connection failed');
        $exception = new ChatException(new Phrase('The assistant is unavailable.'), $cause);

        $this->expectValidMessages();
        $this->expectAllowedRateLimit();
        $this->session->expects(self::once())->method('writeClose');
        $this->chatManager->expects(self::once())
            ->method('streamCompletion')
            ->with(self::MESSAGES)
            ->willThrowException($exception);
        $this->chatLogger->expects(self::once())
            ->method('logError')
            ->with(
                self::callback(static fn (string $id): bool => preg_match('/^[a-f0-9]{16}$/', $id) === 1),
                'The assistant is unavailable.',
                $cause
            );
        $this->sseResponseFactory->expects(self::never())->method('create');
        $this->expectJsonError(503, 'The assistant is unavailable.');

        self::assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testSuccessClosesSessionBeforeUpstreamAndDelegatesToSseResponse(): void
    {
        $sessionClosed = false;
        $correlationId = null;
        $stream = $this->createMock(StreamInterface::class);
        $response = $this->createMock(SseResponse::class);

        $this->expectValidMessages();
        $this->session->expects(self::once())
            ->method('getSessionId')
            ->willReturnCallback(function () use (&$sessionClosed): string {
                self::assertFalse($sessionClosed);

                return 'session-id';
            });
        $this->remoteAddress->expects(self::once())
            ->method('getRemoteAddress')
            ->willReturnCallback(function () use (&$sessionClosed): string {
                self::assertFalse($sessionClosed);

                return '192.0.2.10';
            });
        $this->rateLimiter->expects(self::once())
            ->method('assert')
            ->with('session-id|192.0.2.10')
            ->willReturnCallback(function () use (&$sessionClosed): void {
                self::assertFalse($sessionClosed);
            });
        $this->session->expects(self::once())
            ->method('writeClose')
            ->willReturnCallback(function () use (&$sessionClosed): void {
                $sessionClosed = true;
            });
        $this->chatManager->expects(self::once())
            ->method('streamCompletion')
            ->with(self::MESSAGES)
            ->willReturnCallback(function () use (&$sessionClosed, $stream): StreamInterface {
                self::assertTrue($sessionClosed);

                return $stream;
            });
        $this->chatSettings->expects(self::once())->method('getWorkspace')->willReturn('products');
        $this->chatLogger->expects(self::once())
            ->method('logRequest')
            ->willReturnCallback(
                function (string $id, string $workspace, array $messages) use (&$correlationId): void {
                    self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);
                    self::assertSame('products', $workspace);
                    self::assertSame(self::MESSAGES, $messages);
                    $correlationId = $id;
                }
            );
        $this->sseResponseFactory->expects(self::once())
            ->method('create')
            ->with(self::callback(
                static function (array $data) use ($stream, &$correlationId): bool {
                    return $data === ['options' => [
                        'stream' => $stream,
                        'correlation_id' => $correlationId,
                    ]];
                }
            ))
            ->willReturn($response);

        self::assertSame($response, $this->controller->execute());
    }

    public function testCsrfHeaderTakesPrecedenceOverFormKeyParameter(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->expects(self::once())
            ->method('getHeader')
            ->with('X-Magento-Form-Key')
            ->willReturn('header-key');
        $request->expects(self::never())->method('getParam');
        $this->formKey->expects(self::once())->method('getFormKey')->willReturn('header-key');

        self::assertTrue($this->controller->validateForCsrf($request));
    }

    public function testCsrfFallsBackToFormKeyParameterWhenHeaderIsEmpty(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->expects(self::once())
            ->method('getHeader')
            ->with('X-Magento-Form-Key')
            ->willReturn('');
        $request->expects(self::once())
            ->method('getParam')
            ->with('form_key')
            ->willReturn('parameter-key');
        $this->formKey->expects(self::once())->method('getFormKey')->willReturn('parameter-key');

        self::assertTrue($this->controller->validateForCsrf($request));
    }

    public function testCsrfValidationUsesThePassedRequestAndRejectsInvalidHeader(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->expects(self::once())
            ->method('getHeader')
            ->with('X-Magento-Form-Key')
            ->willReturn('invalid-key');
        $request->expects(self::never())->method('getParam');
        $this->request->expects(self::never())->method('getHeader');
        $this->formKey->expects(self::once())->method('getFormKey')->willReturn('expected-key');

        self::assertFalse($this->controller->validateForCsrf($request));
    }

    public function testCsrfFailureReturnsJsonForbiddenWithoutFlashMessages(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $this->expectJsonError(403, 'Invalid Form Key. Please refresh the page.');

        $exception = $this->controller->createCsrfValidationException($request);

        self::assertInstanceOf(InvalidRequestException::class, $exception);
        self::assertSame($this->jsonResult, $exception->getReplaceResult());
        self::assertNull($exception->getMessages());
    }

    private function expectDecodedRequest(): void
    {
        $this->chatSettings->expects(self::once())->method('isEnabled')->willReturn(true);
        $this->request->expects(self::once())->method('getContent')->willReturn(self::REQUEST_BODY);
        $this->jsonSerializer->expects(self::once())
            ->method('unserialize')
            ->with(self::REQUEST_BODY)
            ->willReturn(['messages' => self::MESSAGES]);
    }

    private function expectValidMessages(): void
    {
        $this->expectDecodedRequest();
        $this->sanitizer->expects(self::once())
            ->method('sanitize')
            ->with(self::MESSAGES)
            ->willReturn(self::MESSAGES);
    }

    private function expectRateIdentifier(): void
    {
        $this->session->expects(self::once())->method('getSessionId')->willReturn('session-id');
        $this->remoteAddress->expects(self::once())->method('getRemoteAddress')->willReturn('192.0.2.10');
    }

    private function expectAllowedRateLimit(): void
    {
        $this->expectRateIdentifier();
        $this->rateLimiter->expects(self::once())
            ->method('assert')
            ->with('session-id|192.0.2.10');
    }

    private function expectJsonError(int $statusCode, string $message): void
    {
        $this->jsonFactory->expects(self::once())->method('create')->willReturn($this->jsonResult);
        $this->jsonResult->expects(self::once())
            ->method('setHttpResponseCode')
            ->with($statusCode)
            ->willReturnSelf();
        $this->jsonResult->expects(self::once())
            ->method('setData')
            ->with(['error' => $message])
            ->willReturnSelf();
    }
}
