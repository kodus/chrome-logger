<?php

namespace Kodus\Logging;

use DateTimeInterface;
use Error;
use Exception;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * PSR-3 and PSR-7 compliant alternative to the original ChromeLogger by Craig Campbell.
 *
 * @link https://craig.is/writing/chrome-logger
 */
class ChromeLogger extends AbstractLogger implements LoggerInterface
{
    const VERSION = '4.1.0';

    const COLUMN_LOG       = "log";
    const COLUMN_BACKTRACE = "backtrace";
    const COLUMN_TYPE      = "type";

    const CLASS_NAME  = "___class_name";
    const HEADER_NAME = 'X-ChromeLogger-Data';

    const LOG   = 'log';
    const WARN  = 'warn';
    const ERROR = 'error';
    const INFO  = 'info';

    const GROUP           = 'group';
    const GROUP_END       = 'groupEnd';
    const GROUP_COLLAPSED = 'groupCollapsed';

    const TABLE = 'table';

    const DATETIME_FORMAT = "Y-m-d\\TH:i:s\\Z"; // ISO-8601 UTC date/time format

    /**
     * @var int header size limit (in bytes, defaults to 240KB)
     */
    private $limit = 245760;

    /**
     * @var LogEntry[]
     */
    private $entries = [];

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
     * @param int $limit header size limit (in KB)
     */
    public function setLimit($limit)
    {
        $this->limit = $limit * 1024;
    }

    /**
     * Adds headers for recorded log-entries in the ChromeLogger format.
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
        $json = json_encode(
            $this->encodeEntries($this->entries),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $this->entries = [];

        return $response->withHeader(self::HEADER_NAME, base64_encode($json));
    }

    /**
     * Internally encodes recorded log-entries in the ChromeLogger-compatible data-format.
     *
     * @param LogEntry[] $entries
     *
     * @return array
     */
    protected function encodeEntries($entries)
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

        $rows = [];

        foreach ($entries as $entry) {
            $row = [];

            $data = [
                str_replace("%", "%%", $entry->message),
            ];

            if (count($entry->context)) {
                $context = $this->sanitize($entry->context);

                $data = array_merge($data, $context);
            }

            $row[] = $data;

            $row[] = isset($LEVELS[$entry->level])
                ? $LEVELS[$entry->level]
                : self::LOG;

            if (isset($entry->context["exception"])) {
                // NOTE: per PSR-3, this reserved key could be anything, but if it is an Exception, we
                //       can use that Exception to obtain a stack-trace for output in ChromeLogger.

                $exception = $entry->context["exception"];

                if ($exception instanceof Exception || $exception instanceof Error) {
                    $row[] = $exception->getTraceAsString();
                }
            }

            // Optimization: ChromeLogger defaults to "log" if no entry-type is specified.

            if ($row[1] === self::LOG) {
                if (count($row) === 2) {
                    unset($row[1]);
                } else {
                    $row[1] = "";
                }
            }

            $rows[] = $row;
        }

        return [
            "version" => self::VERSION,
            "columns" => [self::COLUMN_LOG, self::COLUMN_TYPE, self::COLUMN_BACKTRACE],
            "rows"    => $rows,
        ];
    }

    /**
     * Internally sanitize context values, producing a JSON-compatible data-structure.
     *
     * @param mixed  $data      any PHP object, array or value
     * @param true[] $processed map where SPL object-hash => TRUE (eliminates duplicate objects from data-structures)
     *
     * @return array sanitized context
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

            $data[self::CLASS_NAME] = $class_name;

            return $data;
        }

        if (is_scalar($data)) {
            return $data; // bool, int, float
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
