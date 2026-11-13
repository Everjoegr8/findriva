<?php

namespace App\Services\Integrations;

use RuntimeException;

class IntegrationException extends RuntimeException
{
    /** True when the customer must reconnect (revoked or expired grant). */
    public bool $needsReconnect = false;

    public static function reconnect(string $message): self
    {
        $e = new self($message);
        $e->needsReconnect = true;

        return $e;
    }
}
