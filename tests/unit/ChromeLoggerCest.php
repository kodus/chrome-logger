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

        $resource = fopen(__FILE__, "r");

        $logger->debug(
            "DE%BUG",
            [123, "label" => "hello", true, false, null, $resource, [1, 2, 3], ["a" => 1, "b" => 2]]
        );

        $resource_id = explode('#', (string) $resource);

        $logger->info("INFO", [new Baz()]);

        $object_graph = [
            'type' => Baz::class,
            '$foo' => [
                'type' => Foo::class,
                '$foo'          => 'FOO',
                '$bar'          => 'BAR',
                '$baz'          => 'BAZ',
            ],
            '$bar' => [
                'type'       => Bar::class,
                '$bat'                => 'BAT',
                '$foo'                => 'FOO',
                '$bar'                => 'BAR',
                Foo::class . '::$baz' => 'BAZ',
            ],
            '$baz' => [
                // NOTE: properties omitted for this object because it's a circular reference.
                'type' => Baz::class,
            ],
        ];

        $resource_info = [
            'type' => "resource<stream>",
            'id' => array_pop($resource_id)
        ];

        $I->assertSame(
            [
                "version" => ChromeLogger::VERSION,
                "columns" => ["log", "type", "backtrace"],
                "rows"    => [
                    [["DE%%BUG", 123, "label:", "hello", true, false, null, $resource_info, [1, 2, 3], ["a" => 1, "b" => 2]]],
                    [["INFO", $object_graph], "info"],
                ],
            ],
            $this->extractResult($logger)
        );

        fclose($resource);
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

        $data = $this->extractResult($logger);

        $exception_trace = explode("\n", $e->__toString());
        $exception_title = array_shift($exception_trace);
        $exception_trace = implode("\n", $exception_trace);

        $I->assertEquals([
            [["DEBUG", "hello:", "world"]],
            [[$exception_title], "groupCollapsed"],
            [[$exception_trace], "info"],
            [[], "groupEnd"]
        ], $data["rows"]);
    }

    public function renderTables(UnitTester $I)
    {
        $logger = new ChromeLogger();

        $table_rows = [
            ["foo" => "bar", "bar" => "bat"],
            ["foo" => "wup", "bar" => "baz"],
        ];

        $logger->debug("DEBUG", ["table: Some Data" => $table_rows]);

        $data = $this->extractResult($logger);

        $I->assertEquals([
            [["DEBUG"]],
            [["Some Data"], "groupCollapsed"],
            [[$table_rows], "table"],
            [[], "groupEnd"]
        ], $data["rows"]);
    }

    public function truncateExcessiveLogData(UnitTester $I)
    {
        $logger = new ChromeLogger();

        $logger->setLimit(10*1024);

        for ($n=0; $n<200; $n++) {
            $message = str_repeat("0123456789", rand(1,20)); // between 10 and 200 bytes

            $logger->debug($message);
        }

        $data = $this->extractResult($logger); // NOTE: assertion about header-size is built into this method

        $I->assertEquals(ChromeLogger::LIMIT_WARNING, end($data["rows"])[0][0]);
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
        assert(strlen($calls[0][1]) <= $logger->getLimit());

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
