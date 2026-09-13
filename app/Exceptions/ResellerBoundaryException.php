<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The platform tried to contact a customer who belongs to a reseller.
 *
 * Message is written for the admin who pressed the button: it names the
 * reseller and says who does contact this customer.
 */
class ResellerBoundaryException extends RuntimeException {}
