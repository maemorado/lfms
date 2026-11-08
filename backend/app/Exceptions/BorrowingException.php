<?php
namespace App\Exceptions;
use RuntimeException;
final class BorrowingException extends RuntimeException {
 public function __construct(string $message, public readonly int $httpStatus=422, ?\Throwable $previous=null){parent::__construct($message,0,$previous);}
}