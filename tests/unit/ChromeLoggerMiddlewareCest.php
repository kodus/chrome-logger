<?php

namespace Kodus\Logging\Test\Unit;

use Kodus\Logging\ChromeLogger;
use Kodus\Logging\ChromeLoggerMiddleware;
use Kodus\Logging\Test\Mocks\MockMiddleware;
use mindplay\middleman\Dispatcher;
use Nyholm\Psr7\ServerRequest;
use UnitTester;

class ChromeLoggerMiddlewareCest
{
    public function logDuringMiddlewareDispatch(UnitTester $I): void
    {
        $logger = new ChromeLogger();

        $logger_middleware = new ChromeLoggerMiddleware($logger);

        $mock_middleware = new MockMiddleware($logger);

        $dispatcher = new Dispatcher([
            $logger_middleware,
            $mock_middleware
        ]);

        $response = $dispatcher->handle(new ServerRequest('GET', ''));

        $header = $response->getHeaderLine(ChromeLogger::HEADER_NAME);

        $data = json_decode(base64_decode($header), true);

        $I->assertSame([[["running mock middleware"], "info"]], $data["rows"]);
    }
}
