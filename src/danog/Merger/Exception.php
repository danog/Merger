<?php

namespace danog\Merger;
class Exception extends \Exception
{
    public function __construct($message = null, $code = 0, self $previous = null, $file = null, $line = null)
    {
        if ($file !== null) {
            $this->file = $file;
        }
        if ($line !== null) {
            $this->line = $line;
        }
        parent::__construct($message, $code, $previous);
    }
    /**
     * ExceptionErrorHandler.
     *
     * Error handler
     */
    public static function ExceptionErrorHandler($errno = 0, $errstr = null, $errfile = null, $errline = null)
    {
        // If error is suppressed with @, don't throw an exception
        if (error_reporting() === 0 || strpos($errstr, 'headers already sent') || ($errfile && strpos($errfile, 'vendor/amphp') !== false)) {
            return false;
        }
        throw new self($errstr, $errno, null, $errfile, $errline);
    }
}