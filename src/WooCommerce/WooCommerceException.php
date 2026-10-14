<?php
declare(strict_types=1);

namespace Pims\WooCommerce;

final class WooCommerceException extends \RuntimeException
{
    public int $httpStatus;
    public ?array $responseBody;

    public function __construct(string $message, int $httpStatus = 0, ?array $responseBody = null)
    {
        parent::__construct($message);
        $this->httpStatus   = $httpStatus;
        $this->responseBody = $responseBody;
    }
}
