<?php

namespace App\Services\Push;

use RuntimeException;

/**
 * Expo's push service could not be reached or refused the whole request.
 *
 * A single message Expo rejects is not this: that comes back as a failed
 * ticket, so the rest of the batch still goes out.
 */
class ExpoPushException extends RuntimeException {}
