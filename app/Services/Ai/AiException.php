<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Raised when the Ask Settlo responder cannot produce a reply. Messages are
 * kept safe for surfacing/logging: they carry the HTTP status at most, never
 * the request body, provider response body, or the API key.
 */
class AiException extends RuntimeException {}
