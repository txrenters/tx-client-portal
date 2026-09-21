<?php

namespace App\Services\Propertyware;

use RuntimeException;

/**
 * Raised when Propertyware cannot be reached or rejects a request.
 *
 * Client-facing code catches this and shows a friendly error instead of
 * exposing Propertyware's response.
 */
class PropertywareException extends RuntimeException {}
