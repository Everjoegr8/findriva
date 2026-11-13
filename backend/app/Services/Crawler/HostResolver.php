<?php

namespace App\Services\Crawler;

interface HostResolver
{
    /** @return list<string> every A/AAAA address for the host */
    public function resolve(string $host): array;
}
