<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => ! in_array($p, [Permission::WorkspaceDelete, Permission::BillingManage], true),
            )),
            self::Editor => [
                Permission::View,
                Permission::BusinessCreate,
                Permission::BusinessUpdate,
                Permission::AuditRun,
                Permission::ActionUpdate,
            ],
            self::Viewer => [Permission::View],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
