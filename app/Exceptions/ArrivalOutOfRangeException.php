<?php

namespace App\Exceptions;

use RuntimeException;

/** The technician's GPS is outside the allowed radius of the order location. */
class ArrivalOutOfRangeException extends RuntimeException {}
