<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BillingEventProcessed;
use App\Models\BillingIncident;
use App\Models\InviteToken;
use App\Models\TenantAclVersion;
use App\Models\TenantEntitlement;
use App\Models\TenantEventDeduplication;
use App\Models\TenantEventDlq;
use App\Models\TenantNote;
use App\Models\TenantNotification;
use App\Models\TenantNotificationOutbox;
use App\Models\TenantNotificationStreamSequence;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\TenantUserProfileProjection;
use App\Models\TenantUserRealtimeEpoch;

final class BusinessModelRegistry
{
    /**
     * @return array<int, class-string>
     */
    public static function models(): array
    {
        return [
            ActivityLog::class,
            BillingEventProcessed::class,
            BillingIncident::class,
            InviteToken::class,
            TenantAclVersion::class,
            TenantEntitlement::class,
            TenantEventDeduplication::class,
            TenantEventDlq::class,
            TenantNote::class,
            TenantNotification::class,
            TenantNotificationOutbox::class,
            TenantNotificationStreamSequence::class,
            TenantSubscription::class,
            TenantUser::class,
            TenantUserProfileProjection::class,
            TenantUserRealtimeEpoch::class,
        ];
    }
}
