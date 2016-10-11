<?php

namespace Kodus\Logging;

/**
 * Interal model representing a single, unprocessed (PSR-3) log-entry.
 *
 * @internal
 */
class LogEntry
{
    /**
     * @var mixed
     */
    public $level;

    /**
     * @var string
     */
    public $message;

    /**
     * @var array
     */
    public $context;

    /**
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     */
    public function __construct($level, $message, array $context = [])
    {
        $this->level = $level;
        $this->message = $message;
        $this->context = $context;
    }
}
