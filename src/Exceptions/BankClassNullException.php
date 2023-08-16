<?php

namespace EceoPos\Exceptions;

use Exception;
use Throwable;

/**
 * Class BankClassNullException
 */
class BankClassNullException extends Exception
{
    /**
     * BankClassNullException yapıcı metodu:
     *
     * @param  string  $message
     * @param  int  $code
     * @param  Throwable|null  $previous
     */
    public function __construct($message = 'Sınıf belirtilmelidir!', $code = 331, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
