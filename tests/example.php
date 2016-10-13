<?php

use Kodus\Logging\ChromeLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

// ChromeLogger output example - run on a web-server and open in a browser.

function foo()
{
    bar();
}

function bar()
{
    throw new RuntimeException("ouch!"); // for stack-trace test!
}

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

try {
    foo();
} catch (RuntimeException $e) {
    // gotcha!
}

assert(isset($e));

$logger = new ChromeLogger();

$resource = fopen(__FILE__, "r");

$logger->error("oops! looks like an exception, heh.", ["exception" => $e]);

$logger->debug(
    "Example with random values",
    [123, "hello", true, false, null, $resource, [1, 2, 3], ["a" => 1, "b" => 2]]
);

$logger->info("Example with labeled context", ["my object" => new Baz(), "my number" => 123]);

$logger->info(
    "INFO",
    [
        "TABLE: SQL Queries" => [
            ["time" => "10 msec", "sql" => "SELECT * FROM foo"],
            ["time" => "20 msec", "sql" => "SELECT * FROM baz"],
        ]
    ]
);

$logger->emitHeader();

header("Content-Type: text/html");

?>
<!DOCTYPE html>
<h1>ChromeLogger Example</h1>
<p>Use the <a href="https://craig.is/writing/chrome-logger" target="_blank">ChromeLogger</a> extension to inspect the results.</p>
