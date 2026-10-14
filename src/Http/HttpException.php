<?php
declare(strict_types=1);

namespace Pims\Http;

/** Thrown anywhere in the app to produce a clean JSON error response. */
class HttpException extends \RuntimeException
{
    public function __construct(
        public int $status,
        public string $errorCode,
        string $message,
        public ?array $fields = null
    ) {
        parent::__construct($message);
    }
}
