<?php

namespace App\Exceptions;

use RuntimeException;

final class NotificationAnnouncementException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
