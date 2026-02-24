<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase4;

use App\Http\Controllers\Controller;
use App\Models\InviteToken;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Phase4\Audit\AuditLogger;
use App\Support\Phase4\Invites\InviteService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InviteController extends Controller
{
    public function __construct(
        private readonly InviteService $invites,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_if($tenant === null, 403, 'Forbidden.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('manageInvites', $tenant);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $tenantId = (string) $tenant->getTenantKey();
        $result = $this->invites->issue($tenantId, $actor, (string) $validated['email']);

        /** @var InviteToken $token */
        $token = $result['token'];
        $throttled = (bool) $result['throttled'];

        $this->auditLogger->log(
            event: $throttled ? 'invite.soft_throttled' : 'invite.issued',
            tenantId: $tenantId,
            actorId: (int) $actor->getAuthIdentifier(),
            properties: [
                'invite_jti' => (string) $token->getKey(),
                'email' => (string) $validated['email'],
                'throttled' => $throttled,
            ],
        );

        return response()->json([
            'status' => 'accepted',
        ], 202);
    }

    public function accept(Request $request, InviteToken $inviteToken): JsonResponse
    {
        $tenant = tenant();

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $tenantId = $tenant !== null
            ? (string) $tenant->getTenantKey()
            : (string) $inviteToken->tenant_id;

        abort_if($tenantId === '', 403, 'Forbidden.');

        abort_unless(hash_equals((string) $inviteToken->tenant_id, $tenantId), 403, 'Forbidden.');

        $actorEmail = mb_strtolower(trim((string) $actor->email));
        $subject = mb_strtolower(trim((string) $inviteToken->sub));

        abort_unless($actorEmail !== '' && hash_equals($subject, $actorEmail), 403, 'Forbidden.');

        try {
            $accepted = DB::transaction(function () use ($inviteToken, $tenantId, $actor): bool {
                /** @var InviteToken|null $lockedToken */
                $lockedToken = InviteToken::query()
                    ->whereKey($inviteToken->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedToken instanceof InviteToken) {
                    return false;
                }

                if ($lockedToken->consumed_at !== null) {
                    return false;
                }

                if ($lockedToken->expires_at !== null && CarbonImmutable::instance($lockedToken->expires_at)->isPast()) {
                    return false;
                }

                $alreadyConsumedByUser = InviteToken::query()
                    ->where('tenant_id', $tenantId)
                    ->where('central_user_id', (int) $actor->getAuthIdentifier())
                    ->where('jti', '!=', (string) $lockedToken->getKey())
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyConsumedByUser) {
                    return false;
                }

                $lockedToken->forceFill([
                    'central_user_id' => (int) $actor->getAuthIdentifier(),
                    'consumed_at' => now(),
                ])->save();

                TenantUser::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'user_id' => (int) $actor->getAuthIdentifier(),
                    ],
                    [
                        'is_active' => true,
                        'is_banned' => false,
                        'membership_status' => 'active',
                        'membership_revoked_at' => null,
                    ],
                );

                return true;
            });
        } catch (QueryException) {
            $accepted = false;
        }

        if (! $accepted) {
            return response()->json([
                'code' => 'INVITE_TOKEN_INVALID',
                'message' => 'Invite token is invalid or already consumed.',
            ], 409);
        }

        $this->auditLogger->log(
            event: 'invite.accepted',
            tenantId: $tenantId,
            actorId: (int) $actor->getAuthIdentifier(),
            properties: [
                'invite_jti' => (string) $inviteToken->getKey(),
                'email' => (string) $actor->email,
            ],
        );

        return response()->json([
            'status' => 'accepted',
        ]);
    }
}
