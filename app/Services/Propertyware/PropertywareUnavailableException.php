<?php

namespace App\Services\Propertyware;

/**
 * Propertyware could not be reached or answered that it is unavailable
 * (a 5xx or 429), as opposed to rejecting the request itself.
 *
 * The same request is expected to work again once Propertyware is back.
 */
class PropertywareUnavailableException extends PropertywareException {}
