<?php

namespace App\Exceptions;

use RuntimeException;

// Raised when a code is requested during the resend cooldown or a lockout window.
class OtpThrottledException extends RuntimeException {}
