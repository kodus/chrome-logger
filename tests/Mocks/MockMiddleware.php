<?php

namespace Kodus\Logging\Test\Mocks;

use Kodus\Logging\ChromeLogger;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MockMiddleware implements MiddlewareInterface
{
    /**
     * @var ChromeLogger
     */
    private $logger;

    public function __construct(ChromeLogger $logger)
    {
        $this->logger = $logger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->logger->notice("running mock middleware");

        return new Response(headers: ['Content-Type' => 'text/plain'], body: "Hello");
    }
}
