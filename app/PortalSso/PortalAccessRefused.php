<?php

namespace App\PortalSso;

use RuntimeException;

/**
 * The person has no staff access here; the portal never creates accounts or grants roles.
 */
class PortalAccessRefused extends RuntimeException {}
