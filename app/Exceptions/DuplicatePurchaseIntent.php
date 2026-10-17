<?php

namespace App\Exceptions;

/**
 * Raised where a purchase is taken inside a transaction that this class did not open -
 * the manual queue, which is a helper called from eight different pages. Those callers
 * catch a failure and turn it into their own answer, so a repeat has to arrive as
 * something they can tell apart from "the wallet was too thin" before they speak.
 */
class DuplicatePurchaseIntent extends \RuntimeException
{
}
