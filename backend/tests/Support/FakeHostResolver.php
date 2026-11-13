<?php

namespace Tests\Support;

use App\Services\Crawler\HostResolver;

class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    public array $map = [];

    public string $default = '93.184.216.34';

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return $this->map[$host] ?? [$this->default];
    }
}
