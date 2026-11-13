<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Thin Paystack client. Docs: https://paystack.com/docs/api/ */
class Paystack
{
    public function isConfigured(): bool
    {
        return filled(config('services.paystack.secret_key'));
    }

    public function initialize(array $payload): array
    {
        return $this->call(fn () => $this->http()->post('/transaction/initialize', $payload));
    }

    public function verify(string $reference): array
    {
        return $this->call(fn () => $this->http()->get('/transaction/verify/'.rawurlencode($reference)));
    }

    /** Paystack signs the raw webhook body with HMAC-SHA512 using the secret key. */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.paystack.secret_key');

        return $secret !== '' && is_string($signature) && hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower($signature));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('services.paystack.base_url'), '/'))
            ->withToken((string) config('services.paystack.secret_key'))->acceptJson()->asJson()->timeout(20);
    }

    private function call(callable $fn): array
    {
        try {
            $r = $fn();
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach Paystack.', 0, $e);
        }
        if (! $r->successful() || ! $r->json('status')) {
            throw new RuntimeException('Paystack: '.($r->json('message') ?? 'HTTP '.$r->status()));
        }

        return (array) $r->json('data');
    }
}
