<?php

namespace Keepsuit\Liquid\Exceptions;

class ResourceLimitException extends LiquidException
{
    public function __construct(string $message = 'Memory limit exceeded')
    {
        parent::__construct($message);
    }
}
