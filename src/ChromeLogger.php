<?php

namespace Kodus\Logging;

use DateTimeInterface;
use Error;
use Exception;
use function file_put_contents;
use InvalidArgumentException;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * PSR-3 and PSR-7 compliant alternative to the original ChromeLogger by Craig Campbell.
 *
 * @link https://craig.is/writing/chrome-logger
 */
class ChromeLogger extends AbstractLogger implements LoggerInterface
{
    const VERSION = "4.1.0";

    const COLUMN_LOG       = "log";
    const COLUMN_BACKTRACE = "backtrace";
    const COLUMN_TYPE      = "type";

    const CLASS_NAME  = "type";
    const TABLES      = "tables";
    const HEADER_NAME = "X-ChromeLogger-Data";
    const LOCATION_HEADER_NAME = "X-ServerLog-Location";

    const LOG   = "log";
    const WARN  = "warn";
    const ERROR = "error";
    const INFO  = "info";

    const GROUP           = "group";
    const GROUP_END       = "groupEnd";
    const GROUP_COLLAPSED = "groupCollapsed";
    const TABLE           = "table";

    const DATETIME_FORMAT = "Y-m-d\\TH:i:s\\Z"; // ISO-8601 UTC date/time format

    const LIMIT_WARNING = "Beginning of log entries omitted - total header size over Chrome's internal limit!";

    /**
     * @var int header size limit (in bytes, defaults to 240KB)
     */
    protected $limit = 245760;

    /**
     * @var LogEntry[]
     */
    protected $entries = [];

    /**
     * @var string|null
     */
    private $local_path;

    /**
     * @var string|null
     */
    private $public_path;

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function log($level, $message, array $context = [])
    {
        $this->entries[] = new LogEntry($level, $message, $context);
    }

    /**
     * Allows you to override the internal 240 KB header size limit.
     *
     * (Chrome has a 250 KB limit for the total size of all headers.)
     *
     * @see https://cs.chromium.org/chromium/src/net/http/http_stream_parser.h?q=ERR_RESPONSE_HEADERS_TOO_BIG&sq=package:chromium&dr=C&l=159
     *
     * @param int $limit header size limit (in bytes)
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;
    }

    /**
     * @return int header size limit (in bytes)
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * Enables persistence to local log-files served from your web-root.
     *
     * Bypasses the header-size limitation (imposed by Chrome, NGINX, etc.) by avoiding the
     * large `X-ChromeLogger-Data` header and instead storing the log in a flat file.
     *
     * Requires the [ServerLog](https://github.com/mindplay-dk/server-log) Chrome extension,
     * which replaces the ChromeLogger extension - this does NOT work with the regular
     * ChromeLogger extension.
     *
     * @link https://github.com/mindplay-dk/server-log
     *
     * @param string $local_path  absolute local path to a dedicated log-folder in your public web-root,
     *                            e.g. "/var/www/mysite.com/webroot/log"
     * @param string $public_path absolute public path, e.g. "/log"
     */
    public function usePersistence(string $local_path, string $public_path)
    {
        if (! is_dir($local_path)) {
            throw new InvalidArgumentException("local path does not exist: {$local_path}");
        }

        $this->local_path = $local_path;
        $this->public_path = $public_path;
    }

    /**
     * Adds headers for recorded log-entries in the ChromeLogger format, and clear the internal log-buffer.
     *
     * (You should call this at the end of the request/response cycle in your PSR-7 project, e.g.
     * immediately before emitting the Response.)
     *
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    public function writeToResponse(ResponseInterface $response)
    {
        if (count($this->entries)) {
            if ($this->local_path) {
                $response = $response->withHeader(self::LOCATION_HEADER_NAME, $this->createLogFile());
            } else {
                $response = $response->withHeader(self::HEADER_NAME, $this->getHeaderValue());
            }
        }

        $this->entries = [];

        return $response;
    }

    /**
     * Emit the header for recorded log-entries directly using `header()`, and clear the internal buffer.
     *
     * (You can use this in a non-PSR-7 project, immediately before you start emitting the response body.)
     *
     * @throws RuntimeException if you've already started emitting the response body
     *
     * @return void
     */
    public function emitHeader()
    {
        if (count($this->entries)) {
            if (headers_sent()) {
                throw new RuntimeException("unable to emit ChromeLogger header: headers have already been sent");
            }

            if ($this->local_path) {
                header(self::LOCATION_HEADER_NAME . ": " . $this->createLogFile());
            } else {
                header(self::HEADER_NAME . ": " . $this->getHeaderValue());
            }

            $this->entries = [];
        }
    }

    /**
     * @return string raw value for the X-ChromeLogger-Data header
     */
    protected function getHeaderValue()
    {
        $data = $this->createData($this->entries);

        $value = $this->encodeData($data);

        if (strlen($value) > $this->limit) {
            $data["rows"][] = $this->createEntryData(new LogEntry(LogLevel::WARNING, self::LIMIT_WARNING))[0];

            // NOTE: the strategy here is to calculate an estimated overhead, based on the number
            //       of rows - because the size of each row may vary, this isn't necessarily accurate,
            //       so we may need repeat this more than once.

            while (strlen($value) > $this->limit) {
                $num_rows = count($data["rows"]); // current number of rows

                $row_size = strlen($value) / $num_rows; // average row-size

                $max_rows = (int) floor(($this->limit * 0.95) / $row_size); // 5% under the likely max. number of rows

                $excess = max(1, $num_rows - $max_rows); // remove at least 1 row

                $data["rows"] = array_slice($data["rows"], $excess); // remove excess rows

                $value = $this->encodeData($data); // encode again with fewer rows
            }
        }

        return $value;
    }

    /**
     * @return string public path to log-file
     */
    protected function createLogFile(): string
    {
        $this->collectGarbage();

        $content = json_encode(
            $this->createData($this->entries),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $filename = $this->createUniqueFilename();

        $local_path = "{$this->local_path}/{$filename}";

        if (@file_put_contents($local_path, $content) === false) {
            throw new RuntimeException("unable to write log-file: {$local_path}");
        }

        return "{$this->public_path}/{$filename}";
    }

    /**
     * @return string pseudo-random log filename
     */
    protected function createUniqueFilename(): string
    {
        return uniqid("log-", true) . ".json";
    }

    /**
     * Garbage-collects log-files older than one minute.
     */
    protected function collectGarbage()
    {
        foreach (glob("{$this->local_path}/*.json") as $path) {
            $age = $this->getTime() - filemtime($path);

            if ($age > 60) {
                @unlink($path);
            }
        }
    }

    /**
     * @return int
     */
    protected function getTime(): int
    {
        return time();
    }

    /**
     * Encodes the ChromeLogger-compatible data-structure in JSON/base64-format
     *
     * @param array $data header data
     *
     * @return string
     */
    protected function encodeData(array $data)
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $value = base64_encode($json);

        return $value;
    }

    /**
     * Internally builds the ChromeLogger-compatible data-structure from internal log-entries.
     *
     * @param LogEntry[] $entries
     *
     * @return array
     */
    protected function createData(array $entries)
    {
        $rows = [];

        foreach ($entries as $entry) {
            foreach ($this->createEntryData($entry) as $row) {
                $rows[] = $row;
            }
        }

        return [
            "version" => self::VERSION,
            "columns" => [self::COLUMN_LOG, self::COLUMN_TYPE, self::COLUMN_BACKTRACE],
            "rows"    => $rows,
        ];
    }

    /**
     * Encode an individual LogEntry in ChromeLogger-compatible format
     *
     * @param LogEntry $entry
     *
     * @return array log-entries in ChromeLogger row-format
     */
    protected function createEntryData(LogEntry $entry)
    {
        // NOTE: "log" level type is deliberately omitted from the following map, since
        //       it's the default entry-type in ChromeLogger, and can be omitted.

        static $LEVELS = [
            LogLevel::DEBUG     => self::LOG,
            LogLevel::INFO      => self::INFO,
            LogLevel::NOTICE    => self::INFO,
            LogLevel::WARNING   => self::WARN,
            LogLevel::ERROR     => self::ERROR,
            LogLevel::CRITICAL  => self::ERROR,
            LogLevel::ALERT     => self::ERROR,
            LogLevel::EMERGENCY => self::ERROR,
        ];

        $rows = []; // all rows returned by this function

        $row = []; // the first row returned by this function

        $message = $entry->message;
        $context = $entry->context;

        if (isset($context["exception"])) {
            // NOTE: per PSR-3, this reserved key could be anything, but if it is an Exception, we
            //       can use that Exception to obtain a stack-trace for output in ChromeLogger.

            $exception = $context["exception"];

            if ($exception instanceof Exception || $exception instanceof Error) {
                $stack_trace = explode("\n", $exception->__toString());
                $title = array_shift($stack_trace);

                $rows[] = [[$title], self::GROUP_COLLAPSED];
                $rows[] = [[implode("\n", $stack_trace)], self::INFO];
                $rows[] = [[], self::GROUP_END];
            }

            unset($context["exception"]);
        }

        $data = [str_replace("%", "%%", $message)];

        foreach ($context as $key => $value) {
            if (is_array($context[$key]) && preg_match("/^table:\\s*(.+)/ui", $key, $matches) === 1) {
                $title = $matches[1];

                $rows[] = [[$title], self::GROUP_COLLAPSED];
                $rows[] = [[$context[$key]], self::TABLE];
                $rows[] = [[], self::GROUP_END];

                unset($context[$key]);
            } else {
                if (!is_int($key)) {
                    $data[] = "{$key}:";
                }

                $data[] = $this->sanitize($value);
            }
        }

        $row[] = $data;

        $row[] = isset($LEVELS[$entry->level])
            ? $LEVELS[$entry->level]
            : self::LOG;

        // Optimization: ChromeLogger defaults to "log" if no entry-type is specified.

        if ($row[1] === self::LOG) {
            if (count($row) === 2) {
                unset($row[1]);
            } else {
                $row[1] = "";
            }
        }

        array_unshift($rows, $row); // append the first row

        return $rows;
    }

    /**
     * Internally marshall and sanitize context values, producing a JSON-compatible data-structure.
     *
     * @param mixed  $data      any PHP object, array or value
     * @param true[] $processed map where SPL object-hash => TRUE (eliminates duplicate objects from data-structures)
     *
     * @return mixed marshalled and sanitized context
     */
    protected function sanitize($data, &$processed = [])
    {
        if (is_array($data)) {
            /**
             * @var array $data
             */

            foreach ($data as $name => $value) {
                $data[$name] = $this->sanitize($value, $processed);
            }

            return $data;
        }

        if (is_object($data)) {
            /**
             * @var object $data
             */

            $class_name = get_class($data);

            $hash = spl_object_hash($data);

            if (isset($processed[$hash])) {
                // NOTE: duplicate objects (circular references) are omitted to prevent recursion.

                return [self::CLASS_NAME => $class_name];
            }

            $processed[$hash] = true;

            if ($data instanceof JsonSerializable) {
                // NOTE: this doesn't serialize to JSON, it only marshalls to a JSON-compatible data-structure

                $data = $this->sanitize($data->jsonSerialize(), $processed);
            } elseif ($data instanceof DateTimeInterface) {
                $data = $this->extractDateTimeProperties($data);
            } elseif ($data instanceof Exception || $data instanceof Error) {
                $data = $this->extractExceptionProperties($data);
            } else {
                $data = $this->sanitize($this->extractObjectProperties($data), $processed);
            }

            return array_merge([self::CLASS_NAME => $class_name], $data);
        }

        if (is_scalar($data)) {
            return $data; // bool, int, float
        }

        if (is_resource($data)) {
            $resource = explode("#", (string) $data);

            return [
                self::CLASS_NAME => "resource<" . get_resource_type($data) . ">",
                "id" => array_pop($resource)
            ];
        }

        return null; // omit any other unsupported types (e.g. resource handles)
    }

    /**
     * @param DateTimeInterface $datetime
     *
     * @return array
     */
    protected function extractDateTimeProperties(DateTimeInterface $datetime)
    {
        $utc = date_create_from_format("U", $datetime->format("U"), timezone_open("UTC"));

        return [
            "datetime" => $utc->format(self::DATETIME_FORMAT),
            "timezone" => $datetime->getTimezone()->getName(),
        ];
    }

    /**
     * @param object $object
     *
     * @return array
     */
    protected function extractObjectProperties($object)
    {
        $properties = [];

        $reflection = new ReflectionClass(get_class($object));

        // obtain public, protected and private properties of the class itself:

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue; // omit static properties
            }

            $property->setAccessible(true);

            $properties["\${$property->name}"] = $property->getValue($object);
        }

        // obtain any inherited private properties from parent classes:

        while ($reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties(ReflectionProperty::IS_PRIVATE) as $property) {
                $property->setAccessible(true);

                $properties["{$reflection->name}::\${$property->name}"] = $property->getValue($object);
            }
        }

        return $properties;
    }

    /**
     * @param Throwable $exception
     *
     * @return array
     */
    protected function extractExceptionProperties($exception)
    {
        $previous = $exception->getPrevious();

        return [
            "\$message"  => $exception->getMessage(),
            "\$file"     => $exception->getFile(),
            "\$code"     => $exception->getCode(),
            "\$line"     => $exception->getLine(),
            "\$previous" => $previous ? $this->extractExceptionProperties($previous) : null,
        ];
    }
}
