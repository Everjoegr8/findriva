<?php

namespace App\Enums;

enum ActionStatus: string
{
    case Open = 'open';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Done = 'done';           // implemented, not yet re-checked
    case Verified = 'verified';   // a later check confirmed the fix is live
    case Dismissed = 'dismissed';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::AwaitingApproval, self::InProgress, self::Blocked, self::Dismissed],
            self::AwaitingApproval => [self::Approved, self::Open, self::Dismissed],
            self::Approved => [self::InProgress, self::Blocked, self::Done],
            self::InProgress => [self::Done, self::Blocked, self::Open],
            self::Blocked => [self::Open, self::InProgress, self::Dismissed],
            self::Done => [self::Verified, self::InProgress],
            self::Verified => [],
            self::Dismissed => [self::Open],
        };
    }
}
