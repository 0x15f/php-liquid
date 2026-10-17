<?php

namespace Keepsuit\Liquid\Exceptions;

/** Provider loading budgets abort rendering even with a permissive error handler. */
final class DataLoadingLimitException extends ResourceLimitException
{
    public function __construct(string $message)
    {
        parent::__construct();
        $this->message = $message;
    }
}
