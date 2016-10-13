kodus/chrome-logger
===================

[![PHP Version](https://img.shields.io/badge/php-5.6%2B-blue.svg)](https://packagist.org/packages/kodus/chrome-logger)

[PSR-3](http://www.php-fig.org/psr/psr-3/) and [PSR-7](http://www.php-fig.org/psr/psr-7/) compliant alternative
to the original [ChromeLogger](https://craig.is/writing/chrome-logger) for PHP by Craig Campbell. [Because](#because).


## Usage

It's PSR-3, so:

```php
$logger = new ChromeLogger();

$logger->notice("awesome sauce!");
```

Assuming you have a PSR-7 `ResponseInterface` instance, such as in a middleware stack, you can populate
the Response as follows:

```php
$response = $logger->writeToResponse($response);
```

If you're not using PSR-7, emitting the headers old-school is also possible with `ChromeLogger::emitHeader()`.

The reserved `"exception"` key in PSR-3 [context values](http://www.php-fig.org/psr/psr-3/#1-3-context) is supported -
the following will result in a stack-trace:

```php
try {
    something_dumb();
} catch (Exception $e) {
    $logger->error("ouch!", ["exception" => $e]);
}
```

Any PHP values injected via the context array will be serialized for client-side inspection - including complex
object graphs and explicit serialization of problematic types like `Exception` and `DateTime`.


## Limitations

We do not currently support log-entry grouping or tables, as supported by the original ChromeLogger for PHP, as
these concepts are not supported by PSR-3.

We do not make use of the reserved `'___class_name'` key used to color-code objects in ChromeLogger, because this
does not work for nested object graphs - instead, we consistently indicate the object type as `type` in the console
output, which works well enough, given that object properties are visually qualified with `$` prefix in the output.
(Improving this in the future would require changes to the ChromeLogger extension.)


## Why?

The original ChromeLogger for PHP has a static API, and aggressively emits headers, making it unsuitable
for use in a [PSR-15](https://github.com/http-interop/http-middleware) based (or other) middleware stack.
Static classes generally aren't much fun if you enjoy writing testable code.

This library also implements the PSR-3 `LoggerInterface`, which makes it easy to substitute this logger
for any other.

Note that, while we aware of the `ChromePHPHandler` which comes with the popular logging framework
[monolog](https://github.com/Seldaek/monolog/), `kodus/chrome-logger` has no external dependencies
beyond the PSR interfaces, and uses `ResponseInterface::withHeader()` to populate PSR-7 Response objects,
as opposed to making `header()` calls.
