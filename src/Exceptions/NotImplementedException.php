<?php

declare(strict_types=1);

namespace Finpay\Exceptions;

use Rublex\CoreGateway\Exceptions\UnsupportedCapabilityException;

class NotImplementedException extends UnsupportedCapabilityException
{
    public function __construct(string $capability = 'unknown')
    {
        parent::__construct(sprintf('Capability "%s" is not implemented by Finpay gateway.', $capability));
    }
}
