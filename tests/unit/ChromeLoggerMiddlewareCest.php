<?php

namespace Kodus\Logging\Test\Unit;

use Kodus\Logging\ChromeLogger;
use Kodus\Logging\ChromeLoggerMiddleware;
use mindplay\middleman\Dispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UnitTester;
use Zend\Diactoros\Response\TextResponse;
use Zend\Diactoros\ServerRequest;

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

        return new TextResponse("Hello");
    }
}

class ChromeLoggerMiddlewareCest
{
    public function logDuringMiddlewareDispatch(UnitTester $I)
    {
        $logger = new ChromeLogger();

        $logger_middleware = new ChromeLoggerMiddleware($logger);

        $mock_middleware = new MockMiddleware($logger);

        $dispatcher = new Dispatcher([
            $logger_middleware,
            $mock_middleware
        ]);

        $response = $dispatcher->dispatch(new ServerRequest());

        $header = $response->getHeaderLine(ChromeLogger::HEADER_NAME);

        $data = json_decode(base64_decode($header), true);

        $I->assertSame([[["running mock middleware"], "info"]], $data["rows"]);
    }
}
