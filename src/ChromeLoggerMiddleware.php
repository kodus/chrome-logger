<?php

namespace Kodus\Logging;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Add this to the *top* of your middleware stack - it will delegate to the rest of the
 * middleware stack unconditionally, then decorates the Response with ChromeLogger headers.
 */
class ChromeLoggerMiddleware implements MiddlewareInterface
{
    /**
     * @var ChromeLogger
     */
    private $logger;

    /**
     * @param ChromeLogger $logger
     */
    public function __construct(ChromeLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Process an incoming server request and return a response, optionally delegating
     * response creation to a handler.
     *
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $this->logger->writeToResponse($response);
    }
}
