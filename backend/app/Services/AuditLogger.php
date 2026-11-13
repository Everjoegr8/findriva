<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;

/** Records every meaningful change. Never pass secrets or tokens here. */
class AuditLogger
{
    private const REDACT = ['password', 'token', 'secret', 'access_token', 'refresh_token', 'remember_token', 'verification_token'];

    public function __construct(private readonly CurrentWorkspace $tenant) {}

    public function log(string $action, ?Model $target = null, ?array $before = null, ?array $after = null, ?int $workspaceId = null): AuditLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::create([
            'workspace_id' => $workspaceId ?? $target?->getAttribute('workspace_id') ?? $this->tenant->id(),
            'actor_id' => auth()->id(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'before' => $before ? self::redact($before) : null,
            'after' => $after ? self::redact($after) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), self::REDACT, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }

        return $data;
    }
}
