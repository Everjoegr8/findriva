<?php

namespace App\Enums;

enum Permission: string
{
    case View = 'view';
    case WorkspaceUpdate = 'workspace.update';
    case WorkspaceDelete = 'workspace.delete';
    case BillingManage = 'billing.manage';
    case MembersManage = 'members.manage';
    case BusinessCreate = 'business.create';
    case BusinessUpdate = 'business.update';
    case BusinessDelete = 'business.delete';
    case AuditRun = 'audit.run';
    case ActionUpdate = 'action.update';
    // Approving a change that will be made on the customer's behalf is a deliberate, owner-level decision.
    case ActionApprove = 'action.approve';
}
