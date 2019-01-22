<?php

namespace Kodus\Logging\Test\Unit;

use Codeception\Util\FileSystem;
use function json_decode;
use Kodus\Logging\ChromeLogger;
use Mockery;
use Mockery\MockInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use function str_repeat;
use UnitTester;
use Zend\Diactoros\Response;

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

    public function persistToLocalFiles(UnitTester $I)
    {
        $logger = new class extends ChromeLogger
        {
            private $time;

            public function __construct()
            {
                // NOTE: no call to parent::_construct() here because PHP demands horrible code.

                $this->time = time();
            }

            public function skipTime(int $time)
            {
                $this->time += $time;
            }

            protected function getTime(): int
            {
                return $this->time;
            }
        };

        $local_path = dirname(__DIR__) . "/_output/log";

        FileSystem::deleteDir($local_path);

        mkdir($local_path);

        $public_path = "/log";

        $logger->setLimit(2048); // will be ignored!

        $logger->usePersistence($local_path, $public_path);

        $unique_locations = [];

        $generate_log = function () use ($I, $logger, $local_path, &$unique_locations) {
            // write 20*20*10 = 4000 bytes (over the 2048 limit, which should be ignored)

            $num_rows = 20;

            for ($i=1; $i<= $num_rows; $i++) {
                $logger->debug(str_repeat("0123456789", 20));
            }

            $response = new Response(fopen("php://temp", "rw+"));

            $response = $logger->writeToResponse($response);

            $location = $response->getHeaderLine("X-ServerLog-Location");

            $unique_locations[$location] = true;

            $I->assertRegExp('/^\/log\/log-.*\.json$/', $location);

            $contents = file_get_contents("{$local_path}/" . basename($location));

            $I->assertCount($num_rows, json_decode($contents, true)["rows"],
                "all rows should be written to file, despite being over the header size limit");
        };

        $num_files = 2;

        for ($i=1; $i<= $num_files; $i++) {
            $generate_log();
        }

        $I->assertCount($num_files, $unique_locations,
            "every generated log should have a unique file-name");

        $I->assertCount($num_files, glob("{$local_path}/*.json"),
            "no files should have been garbage-collected at this point");

        $logger->skipTime(60 + 1); // log-files expire after 60 seconds

        $generate_log();

        $I->assertCount(1, glob("{$local_path}/*.json"),
            "previous {$num_files} log-files should be garbage-collected");
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
