kodus/chrome-logger
===================

[![PHP Version](https://img.shields.io/badge/php-8.0%2B-blue.svg)](https://packagist.org/packages/kodus/chrome-logger)
[![Build Status](https://travis-ci.org/kodus/chrome-logger.svg?branch=master)](https://travis-ci.org/kodus/chrome-logger)
[![Code Coverage](https://scrutinizer-ci.com/g/kodus/chrome-logger/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/kodus/chrome-logger/?branch=master)

Alternative to the original [ChromeLogger](https://craig.is/writing/chrome-logger) for PHP by Craig Campbell, using:

 * [PSR-3](http://www.php-fig.org/psr/psr-3/) compliant interface for logging,
 * [PSR-7](http://www.php-fig.org/psr/psr-7/) HTTP message abstraction for the models, and
 * [PSR-15](https://www.php-fig.org/psr/psr-15/) compliant middleware for quick integration.

✨ An alternative to the ChromeLogger extension [is also available](http://github.com/kodus/server-log)
and is [highly recommended](#header-size-limit).

## Usage

The logging interface is PSR-3 compliant, so:

```php
$logger = new ChromeLogger();

$logger->notice("awesome sauce!");
```

Note that this will have a [header-size limit](#header-size-limit) by default.

Using a PSR-7 compliant `ResponseInterface` instance, such as in a middleware stack, you can populate
the Response as follows:

```php
$response = $logger->writeToResponse($response);
```

Or just add an instance of the included PSR-15 `ChromeLoggerMiddleware` to the top of your middleware stack.

If you're not using PSR-7, emitting the headers old-school is also possible with `ChromeLogger::emitHeader()`.

### Logging Table Data

Since PSR-3 does not offer any explicit support for tables, we support tables via the context array.

For example:

```php
$logger->info(
    "INFO",
    [
        "table: SQL Queries" => [
            ["time" => "10 msec", "sql" => "SELECT * FROM foo"],
            ["time" => "20 msec", "sql" => "SELECT * FROM baz"],
        ]
    ]
);
```

This works because the `"table:"` key prefix in the context array is recognized and treated specially.

### Logging a Stack Trace from an Exception

The reserved `"exception"` key in PSR-3 [context values](http://www.php-fig.org/psr/psr-3/#1-3-context) is supported -
the following will result in a stack-trace:

```php
try {
    something_dumb();
} catch (Exception $e) {
    $logger->error("ouch, this looks bad!", ["exception" => $e]);
}
```

Any PHP values injected via the context array will be serialized for client-side inspection - including complex
object graphs and explicit serialization of problematic types like `Exception` and `DateTime`.


<a name="header-size-limit"></a>
### Header Size Limit

[Chrome has a 250KB header size limit](https://cs.chromium.org/chromium/src/net/http/http_stream_parser.h?q=ERR_RESPONSE_HEADERS_TOO_BIG&sq=package:chromium&dr=C&l=159)
and many popular web-servers (including NGINX and Apache) also have a limit.

By default, the beginning of the log will be truncated to keep the header size under the limit.

You can change this limit using the `ChromeLogger::setLimit()` method - but a better approach is
to enable logging to local files, which will persist in a web-accessible folder for 60 seconds:

```php
$logger->usePersistence("/var/www/mysite/webroot/logs", "/logs");
```

Note that this isn't supported by the ChromeLogger extension - you will need to install the alternative
[Server Log Chrome extension](http://github.com/kodus/server-log) instead. (It is backwards
compatible with the header-format of the original ChromeLogger extension, so you can use this as
a drop-in replacement for the original extension.)


## Limitations

We do not currently support log-entry grouping, as supported by the original ChromeLogger for PHP, as
this concept is not supported by PSR-3.

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
