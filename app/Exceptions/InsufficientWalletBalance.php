<?php

namespace App\Exceptions;

/**
 * The wallet does not hold the money this request wanted to spend.
 *
 * It is a RuntimeException because every purchase flow already answers that class
 * with a polite 402; being its own type is what lets a test, and the ledger itself,
 * tell "this customer is short" apart from "the database refused the write".
 */
class InsufficientWalletBalance extends \RuntimeException
{
}
