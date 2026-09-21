<?php

namespace App\Services\ChatHub;

use RuntimeException;

/**
 * The messaging hub (tx-chatbot) could not be reached or refused a request.
 */
class ChatHubException extends RuntimeException {}
