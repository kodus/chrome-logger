<?php

namespace Kodus\Logging\Test\Unit;

use Kodus\Logging\ChromeLogger;
use Mockery;
use Mockery\MockInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use UnitTester;

class Foo
{
    public $foo = "FOO";
    protected $bar = "BAR";
    private $baz = "BAZ";
}

class Bar extends Foo
{
    public $bat = "BAT";
}

class Baz
{
    public $foo;
    public $bar;
    public $baz;

    public function __construct()
    {
        $this->foo = new Foo();
        $this->bar = new Bar();
        $this->baz = $this;
    }
}

class ChromeLoggerCest
{
    public function writeLogMessages(UnitTester $I)
    {
        $logger = new ChromeLogger();

        $logger->debug("DEBUG");
        $logger->info("INFO");
        $logger->notice("NOTICE");
        $logger->warning("WARNING");
        $logger->error("ERROR");
        $logger->critical("CRITICAL");
        $logger->alert("ALERT");
        $logger->emergency("EMERGENCY");

        $I->assertSame(
            [
                "version" => ChromeLogger::VERSION,
                "columns" => ["log", "type", "backtrace"],
                "rows"    => [
                    [["DEBUG"]],
                    [["INFO"], "info"],
                    [["NOTICE"], "info"],
                    [["WARNING"], "warn"],
                    [["ERROR"], "error"],
                    [["CRITICAL"], "error"],
                    [["ALERT"], "error"],
                    [["EMERGENCY"], "error"],
                ],
            ],
            $this->extractResult($logger)
        );
    }

    public function serializeContextValues(UnitTester $I)
    {
        $logger = new ChromeLogger();

        $logger->debug(
            "DE%BUG",
            [123, "hello", true, false, null, fopen(__FILE__, "r"), [1, 2, 3], ["a" => 1, "b" => 2]]
        );

        $logger->info("INFO", [new Baz()]);

        $object_graph = [
            '$foo' => [
                '$foo'          => 'FOO',
                '$bar'          => 'BAR',
                '$baz'          => 'BAZ',
                '___class_name' => Foo::class,
            ],
            '$bar' => [
                '$bat'                => 'BAT',
                '$foo'                => 'FOO',
                '$bar'                => 'BAR',
                Foo::class . '::$baz' => 'BAZ',
                '___class_name'       => Bar::class,
            ],
            '$baz' => [
                // NOTE: properties omitted for this object because it's a circular reference.
                '___class_name' => Baz::class,
            ],
            '___class_name' => Baz::class,
        ];

        $I->assertSame(
            [
                "version" => ChromeLogger::VERSION,
                "columns" => ["log", "type", "backtrace"],
                "rows"    => [
                    [["DE%%BUG", 123, "hello", true, false, null, /* resource: */ null, [1, 2, 3], ["a" => 1, "b" => 2]]],
                    [["INFO", $object_graph], "info"],
                ],
            ],
            $this->extractResult($logger)
        );
    }

    public function obtainStackTrace(UnitTester $I)
    {
        try {
            $this->foo();
        } catch (RuntimeException $e) {
            // gotcha!
        }

        assert(isset($e));

        $logger = new ChromeLogger();

        $logger->debug("DEBUG", ["exception" => $e, "hello" => "world"]);

        $logger->info("INFO", [1, 2, 3, "exception" => $e]);

        $data = $this->extractResult($logger);

        $I->assertEquals(2, count($data["rows"]));

        $I->assertEquals("DEBUG", $data["rows"][0][0][0]);
        $I->assertNotNull($data["rows"][0][0]["exception"]);
        $I->assertNotNull($data["rows"][0][0]["hello"]);
        $I->assertEquals("", $data["rows"][0][1]);
        $I->assertEquals($e->getTraceAsString(), $data["rows"][0][2]);

        $I->assertEquals("INFO", $data["rows"][1][0][0]);
        $I->assertEquals("info", $data["rows"][1][1]);
        $I->assertEquals($e->getTraceAsString(), $data["rows"][1][2]);
    }

    /**
     * Extract data from the data-header created by a ChromeLogger instance.
     *
     * @param ChromeLogger $logger
     *
     * @return array
     */
    private function extractResult(ChromeLogger $logger)
    {
        /**
         * @var MockInterface|ResponseInterface $response
         */

        $response = Mockery::mock(ResponseInterface::class);

        $calls = [];

        $response->shouldReceive("withHeader")->andReturnUsing(function ($header, $value) use (&$calls) {
            $calls[] = func_get_args();
        });

        $logger->writeToResponse($response);

        assert(count($calls) === 1);
        assert(count($calls[0]) === 2);
        assert($calls[0][0] === ChromeLogger::HEADER_NAME);

        return json_decode(base64_decode($calls[0][1]), true);
    }

    private function foo() {
        $this->bar();
    }

    private function bar()
    {
        throw new RuntimeException("ouch!"); // for stack-trace test!
    }
}
