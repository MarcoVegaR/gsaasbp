import { Head } from '@inertiajs/react';
import { type FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import AlertError from '@/components/alert-error';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type RequestFailure = {
    status: number;
    payload: unknown;
};

type RbacMember = {
    user_id: number;
    name: string;
    email: string;
    membership_status: string;
    roles: string[];
};

type RbacPayload = {
    acl_version: number;
    roles: string[];
    members: RbacMember[];
    assignable_permissions: string[];
};

type AuditRow = {
    id: number;
    event: string;
    request_id: string | null;
    actor_id: number | null;
    hmac_kid: string | null;
    properties: Record<string, unknown> | null;
    created_at: string | null;
};

type AuditMeta = {
    from: string;
    to: string;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type BillingPayload = {
    provider: string;
    subscription: {
        status: string;
        provider_object_version: number;
        subscription_revision: number;
        provider_customer_id: string | null;
        provider_subscription_id: string | null;
        current_period_ends_at: string | null;
    } | null;
    entitlements: Array<{
        feature: string;
        granted: boolean;
        source: string;
        updated_by_event_id: string | null;
        expires_at: string | null;
    }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tenant settings',
        href: '/tenant/settings',
    },
];

function getCookie(name: string): string | null {
    const cookies = document.cookie.split(';').map((entry) => entry.trim());
    const match = cookies.find((entry) => entry.startsWith(`${name}=`));

    if (match === undefined) {
        return null;
    }

    return decodeURIComponent(match.slice(name.length + 1));
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function extractErrorMessages(error: unknown, fallback: string): string[] {
    if (!isRecord(error)) {
        return [fallback];
    }

    const payload = error.payload;

    if (isRecord(payload)) {
        const nestedErrors = payload.errors;

        if (isRecord(nestedErrors)) {
            const flatErrors = Object.values(nestedErrors)
                .flatMap((entry) =>
                    Array.isArray(entry)
                        ? entry
                              .filter((item): item is string => typeof item === 'string')
                              .map((item) => item.trim())
                        : [],
                )
                .filter((item) => item !== '');

            if (flatErrors.length > 0) {
                return flatErrors;
            }
        }

        const message = payload.message;

        if (typeof message === 'string' && message.trim() !== '') {
            return [message.trim()];
        }
    }

    return [fallback];
}

function isoForInput(date: Date): string {
    return date.toISOString().slice(0, 16);
}

async function apiJson<T>(url: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const xsrfToken = getCookie('XSRF-TOKEN');

    if (xsrfToken !== null) {
        headers.set('X-XSRF-TOKEN', xsrfToken);
    }

    if (init.body !== undefined && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers,
    });

    if (!response.ok) {
        const payload = await response
            .json()
            .catch(() => ({ message: 'Request failed.' }));

        throw {
            status: response.status,
            payload,
        } satisfies RequestFailure;
    }

    return (await response.json()) as T;
}

export default function TenantSettings() {
    const [inviteEmail, setInviteEmail] = useState('');
    const [inviteSubmitting, setInviteSubmitting] = useState(false);
    const [inviteNotice, setInviteNotice] = useState<string | null>(null);
    const [inviteErrors, setInviteErrors] = useState<string[]>([]);

    const [rbac, setRbac] = useState<RbacPayload | null>(null);
    const [rbacLoading, setRbacLoading] = useState(true);
    const [rbacSaving, setRbacSaving] = useState(false);
    const [rbacErrors, setRbacErrors] = useState<string[]>([]);
    const [rbacNotice, setRbacNotice] = useState<string | null>(null);
    const [selectedMemberId, setSelectedMemberId] = useState<number | null>(null);
    const [selectedRoles, setSelectedRoles] = useState<string[]>([]);

    const [billing, setBilling] = useState<BillingPayload | null>(null);
    const [billingLoading, setBillingLoading] = useState(true);
    const [billingReconciling, setBillingReconciling] = useState(false);
    const [billingErrors, setBillingErrors] = useState<string[]>([]);
    const [billingNotice, setBillingNotice] = useState<string | null>(null);

    const [auditRows, setAuditRows] = useState<AuditRow[]>([]);
    const [auditMeta, setAuditMeta] = useState<AuditMeta | null>(null);
    const [auditLoading, setAuditLoading] = useState(true);
    const [auditExporting, setAuditExporting] = useState(false);
    const [auditErrors, setAuditErrors] = useState<string[]>([]);
    const [auditNotice, setAuditNotice] = useState<string | null>(null);
    const [auditEvent, setAuditEvent] = useState('');
    const [auditFromInput, setAuditFromInput] = useState(() =>
        isoForInput(new Date(Date.now() - 24 * 60 * 60 * 1000)),
    );
    const [auditToInput, setAuditToInput] = useState(() =>
        isoForInput(new Date()),
    );

    const selectedMember = useMemo(() => {
        if (rbac === null || selectedMemberId === null) {
            return null;
        }

        return (
            rbac.members.find((member) => member.user_id === selectedMemberId) ?? null
        );
    }, [rbac, selectedMemberId]);

    const loadRbac = useCallback(async (): Promise<void> => {
        setRbacLoading(true);
        setRbacErrors([]);

        try {
            const payload = await apiJson<RbacPayload>('/tenant/rbac/members');
            setRbac(payload);

            setSelectedMemberId((current) => {
                if (payload.members.length === 0) {
                    return null;
                }

                if (
                    current !== null &&
                    payload.members.some((member) => member.user_id === current)
                ) {
                    return current;
                }

                return payload.members[0]?.user_id ?? null;
            });
        } catch (error: unknown) {
            setRbacErrors(
                extractErrorMessages(
                    error,
                    'Unable to load RBAC data for this workspace.',
                ),
            );
        } finally {
            setRbacLoading(false);
        }
    }, []);

    const loadBilling = useCallback(async (): Promise<void> => {
        setBillingLoading(true);
        setBillingErrors([]);

        try {
            const payload = await apiJson<BillingPayload>('/tenant/billing');
            setBilling(payload);
        } catch (error: unknown) {
            setBillingErrors(
                extractErrorMessages(
                    error,
                    'Unable to load billing status for this workspace.',
                ),
            );
        } finally {
            setBillingLoading(false);
        }
    }, []);

    const loadAudit = useCallback(async (): Promise<void> => {
        setAuditLoading(true);
        setAuditErrors([]);

        try {
            const params = new URLSearchParams();

            if (auditEvent.trim() !== '') {
                params.set('event', auditEvent.trim());
            }

            if (auditFromInput.trim() !== '') {
                params.set('from', new Date(auditFromInput).toISOString());
            }

            if (auditToInput.trim() !== '') {
                params.set('to', new Date(auditToInput).toISOString());
            }

            const endpoint = `/tenant/audit-logs?${params.toString()}`;
            const payload = await apiJson<{ data: AuditRow[]; meta: AuditMeta }>(
                endpoint,
            );

            setAuditRows(payload.data);
            setAuditMeta(payload.meta);
        } catch (error: unknown) {
            setAuditErrors(
                extractErrorMessages(
                    error,
                    'Unable to load forensic audit events for this workspace.',
                ),
            );
        } finally {
            setAuditLoading(false);
        }
    }, [auditEvent, auditFromInput, auditToInput]);

    useEffect(() => {
        void Promise.all([loadRbac(), loadBilling(), loadAudit()]);
    }, [loadAudit, loadBilling, loadRbac]);

    useEffect(() => {
        if (selectedMember === null) {
            setSelectedRoles([]);

            return;
        }

        setSelectedRoles(selectedMember.roles);
    }, [selectedMember]);

    const submitInvite = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (inviteEmail.trim() === '') {
            setInviteErrors(['Invite email is required.']);

            return;
        }

        setInviteSubmitting(true);
        setInviteErrors([]);
        setInviteNotice(null);

        try {
            await apiJson<{ status: string }>('/tenant/invites', {
                method: 'POST',
                body: JSON.stringify({
                    email: inviteEmail.trim(),
                }),
            });

            setInviteNotice(
                'Invitation request accepted (202). Delivery may be soft-throttled by policy.',
            );
            setInviteEmail('');
        } catch (error: unknown) {
            setInviteErrors(
                extractErrorMessages(
                    error,
                    'Unable to create invite for this workspace.',
                ),
            );
        } finally {
            setInviteSubmitting(false);
        }
    };

    const saveRoles = async (): Promise<void> => {
        if (selectedMemberId === null || rbac === null) {
            setRbacErrors(['Select a workspace member before saving roles.']);

            return;
        }

        setRbacSaving(true);
        setRbacErrors([]);
        setRbacNotice(null);

        try {
            await apiJson<{ status: string }>('/tenant/rbac/members/' + selectedMemberId + '/roles', {
                method: 'POST',
                body: JSON.stringify({
                    roles: selectedRoles,
                    expected_acl_version: rbac.acl_version,
                }),
            });

            setRbacNotice('RBAC roles updated successfully.');
            await loadRbac();
        } catch (error: unknown) {
            if (isRecord(error) && error.status === 423) {
                setRbacErrors([
                    'Step-up required. Confirm password or complete MFA before mutating RBAC.',
                ]);
            } else {
                setRbacErrors(
                    extractErrorMessages(
                        error,
                        'Unable to update member roles.',
                    ),
                );
            }
        } finally {
            setRbacSaving(false);
        }
    };

    const exportAudit = async (): Promise<void> => {
        setAuditExporting(true);
        setAuditErrors([]);
        setAuditNotice(null);

        try {
            await apiJson<{ status: string }>('/tenant/audit-logs/export', {
                method: 'POST',
                body: JSON.stringify({
                    event: auditEvent.trim() !== '' ? auditEvent.trim() : null,
                    from:
                        auditFromInput.trim() !== ''
                            ? new Date(auditFromInput).toISOString()
                            : null,
                    to:
                        auditToInput.trim() !== ''
                            ? new Date(auditToInput).toISOString()
                            : null,
                }),
            });

            setAuditNotice('Audit export queued (202).');
        } catch (error: unknown) {
            setAuditErrors(
                extractErrorMessages(error, 'Unable to queue audit export.'),
            );
        } finally {
            setAuditExporting(false);
        }
    };

    const reconcileBilling = async (): Promise<void> => {
        setBillingReconciling(true);
        setBillingErrors([]);
        setBillingNotice(null);

        try {
            await apiJson<{ status: string }>('/tenant/billing/reconcile', {
                method: 'POST',
                body: JSON.stringify({}),
            });

            setBillingNotice('Billing reconciliation queued (202).');
        } catch (error: unknown) {
            setBillingErrors(
                extractErrorMessages(
                    error,
                    'Unable to queue billing reconciliation.',
                ),
            );
        } finally {
            setBillingReconciling(false);
            await loadBilling();
        }
    };

    const toggleRole = (roleName: string, enabled: boolean): void => {
        setSelectedRoles((current) => {
            if (enabled) {
                if (current.includes(roleName)) {
                    return current;
                }

                return [...current, roleName];
            }

            return current.filter((role) => role !== roleName);
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tenant settings" />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Workspace invitations</CardTitle>
                        <CardDescription>
                            Send async invitations (always 202) with soft-throttling
                            controls.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {inviteErrors.length > 0 && <AlertError errors={inviteErrors} />}

                        {inviteNotice !== null && (
                            <Alert>
                                <AlertTitle>Invite accepted by API</AlertTitle>
                                <AlertDescription>{inviteNotice}</AlertDescription>
                            </Alert>
                        )}

                        <form
                            className="grid gap-4 md:grid-cols-[1fr_auto]"
                            onSubmit={submitInvite}
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="invite-email">Email</Label>
                                <Input
                                    id="invite-email"
                                    type="email"
                                    placeholder="member@company.com"
                                    value={inviteEmail}
                                    onChange={(event) =>
                                        setInviteEmail(event.target.value)
                                    }
                                />
                            </div>
                            <div className="flex items-end">
                                <Button type="submit" disabled={inviteSubmitting}>
                                    {inviteSubmitting ? (
                                        <>
                                            <Spinner className="mr-2" />
                                            Sending
                                        </>
                                    ) : (
                                        'Send invite'
                                    )}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>RBAC management</CardTitle>
                        <CardDescription>
                            Set-based role mutation with ACL version locking and
                            step-up auth requirements.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {rbacErrors.length > 0 && <AlertError errors={rbacErrors} />}

                        {rbacNotice !== null && (
                            <Alert>
                                <AlertTitle>RBAC updated</AlertTitle>
                                <AlertDescription>{rbacNotice}</AlertDescription>
                            </Alert>
                        )}

                        {rbacLoading ? (
                            <div className="flex items-center text-sm text-muted-foreground">
                                <Spinner className="mr-2" />
                                Loading RBAC members...
                            </div>
                        ) : rbac === null || rbac.members.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No members available in this tenant workspace.
                            </p>
                        ) : (
                            <>
                                <div className="grid gap-2 md:max-w-md">
                                    <Label htmlFor="rbac-member">Member</Label>
                                    <select
                                        id="rbac-member"
                                        value={selectedMemberId ?? ''}
                                        onChange={(event) =>
                                            setSelectedMemberId(
                                                Number(event.target.value),
                                            )
                                        }
                                        className="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-10 rounded-md border px-3 text-sm outline-none focus-visible:ring-[3px]"
                                    >
                                        {rbac.members.map((member) => (
                                            <option
                                                key={member.user_id}
                                                value={member.user_id}
                                            >
                                                {member.name} ({member.email})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                {selectedMember !== null && (
                                    <div className="space-y-3 rounded-lg border p-4">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-medium">
                                                Membership status:
                                            </span>
                                            <Badge variant="secondary">
                                                {selectedMember.membership_status}
                                            </Badge>
                                        </div>

                                        <div className="grid gap-3 md:grid-cols-2">
                                            {rbac.roles.map((roleName) => (
                                                <label
                                                    key={roleName}
                                                    className="flex items-center gap-3 rounded-md border px-3 py-2 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={selectedRoles.includes(
                                                            roleName,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggleRole(
                                                                roleName,
                                                                checked === true,
                                                            )
                                                        }
                                                    />
                                                    <span>{roleName}</span>
                                                </label>
                                            ))}
                                        </div>

                                        <Button
                                            type="button"
                                            onClick={() => void saveRoles()}
                                            disabled={rbacSaving}
                                        >
                                            {rbacSaving ? (
                                                <>
                                                    <Spinner className="mr-2" />
                                                    Saving
                                                </>
                                            ) : (
                                                'Save role set'
                                            )}
                                        </Button>
                                    </div>
                                )}

                                <div className="rounded-lg border p-4">
                                    <h3 className="text-sm font-medium">
                                        Your assignable permissions
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {rbac.assignable_permissions.length > 0
                                            ? rbac.assignable_permissions.join(
                                                  ', ',
                                              )
                                            : 'No delegable permissions available for your account.'}
                                    </p>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Forensic audit logs</CardTitle>
                        <CardDescription>
                            Query with sargable time windows and queue async export.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {auditErrors.length > 0 && <AlertError errors={auditErrors} />}

                        {auditNotice !== null && (
                            <Alert>
                                <AlertTitle>Audit export</AlertTitle>
                                <AlertDescription>{auditNotice}</AlertDescription>
                            </Alert>
                        )}

                        <div className="grid gap-3 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="audit-from">From</Label>
                                <Input
                                    id="audit-from"
                                    type="datetime-local"
                                    value={auditFromInput}
                                    onChange={(event) =>
                                        setAuditFromInput(event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="audit-to">To</Label>
                                <Input
                                    id="audit-to"
                                    type="datetime-local"
                                    value={auditToInput}
                                    onChange={(event) =>
                                        setAuditToInput(event.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="audit-event">Event</Label>
                                <Input
                                    id="audit-event"
                                    placeholder="rbac.member.roles_updated"
                                    value={auditEvent}
                                    onChange={(event) =>
                                        setAuditEvent(event.target.value)
                                    }
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => void loadAudit()}
                                    disabled={auditLoading}
                                >
                                    {auditLoading ? (
                                        <>
                                            <Spinner className="mr-2" />
                                            Refreshing
                                        </>
                                    ) : (
                                        'Refresh'
                                    )}
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => void exportAudit()}
                                    disabled={auditExporting}
                                >
                                    {auditExporting ? (
                                        <>
                                            <Spinner className="mr-2" />
                                            Queuing
                                        </>
                                    ) : (
                                        'Export'
                                    )}
                                </Button>
                            </div>
                        </div>

                        {auditLoading ? (
                            <div className="flex items-center text-sm text-muted-foreground">
                                <Spinner className="mr-2" />
                                Loading forensic logs...
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="min-w-full divide-y text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">When</th>
                                            <th className="px-3 py-2 font-medium">Event</th>
                                            <th className="px-3 py-2 font-medium">Actor</th>
                                            <th className="px-3 py-2 font-medium">Request ID</th>
                                            <th className="px-3 py-2 font-medium">HMAC kid</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {auditRows.map((row) => (
                                            <tr key={row.id} className="border-t">
                                                <td className="px-3 py-2">
                                                    {row.created_at !== null
                                                        ? new Date(
                                                              row.created_at,
                                                          ).toLocaleString()
                                                        : '-'}
                                                </td>
                                                <td className="px-3 py-2 font-medium">
                                                    {row.event}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {row.actor_id ?? '-'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {row.request_id ?? '-'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {row.hmac_kid ?? '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>

                                {auditRows.length === 0 && (
                                    <p className="px-3 py-4 text-sm text-muted-foreground">
                                        No audit events matched this forensic window.
                                    </p>
                                )}
                            </div>
                        )}

                        {auditMeta !== null && (
                            <p className="text-xs text-muted-foreground">
                                Window {new Date(auditMeta.from).toLocaleString()} -{' '}
                                {new Date(auditMeta.to).toLocaleString()} · Total{' '}
                                {auditMeta.total}
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Billing and entitlements</CardTitle>
                        <CardDescription>
                            Review subscription snapshot and queue drift
                            reconciliation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {billingErrors.length > 0 && (
                            <AlertError errors={billingErrors} />
                        )}

                        {billingNotice !== null && (
                            <Alert>
                                <AlertTitle>Billing</AlertTitle>
                                <AlertDescription>{billingNotice}</AlertDescription>
                            </Alert>
                        )}

                        {billingLoading ? (
                            <div className="flex items-center text-sm text-muted-foreground">
                                <Spinner className="mr-2" />
                                Loading billing state...
                            </div>
                        ) : (
                            <>
                                <div className="rounded-lg border p-4">
                                    <h3 className="text-sm font-medium">
                                        Provider
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {billing?.provider ?? 'unknown'}
                                    </p>

                                    <h3 className="mt-4 text-sm font-medium">
                                        Subscription
                                    </h3>

                                    {billing?.subscription === null ? (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            No active subscription snapshot yet.
                                        </p>
                                    ) : (
                                        <div className="mt-1 grid gap-1 text-sm text-muted-foreground">
                                            <span>
                                                Status:{' '}
                                                <strong className="text-foreground">
                                                    {billing?.subscription?.status ?? 'unknown'}
                                                </strong>
                                            </span>
                                            <span>
                                                Provider object version:{' '}
                                                {billing?.subscription?.provider_object_version ?? 0}
                                            </span>
                                            <span>
                                                Revision:{' '}
                                                {billing?.subscription?.subscription_revision ?? 0}
                                            </span>
                                            <span>
                                                Period end:{' '}
                                                {(billing?.subscription
                                                    ?.current_period_ends_at ??
                                                    '') !== ''
                                                    ? new Date(
                                                          billing?.subscription
                                                              ?.current_period_ends_at ?? '',
                                                      ).toLocaleString()
                                                    : '-'}
                                            </span>
                                        </div>
                                    )}
                                </div>

                                <div className="rounded-lg border p-4">
                                    <h3 className="text-sm font-medium">
                                        Entitlements
                                    </h3>

                                    {billing?.entitlements.length ? (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {billing.entitlements.map((entry) => (
                                                <Badge
                                                    key={entry.feature}
                                                    variant={
                                                        entry.granted
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {entry.feature}:{' '}
                                                    {entry.granted
                                                        ? 'granted'
                                                        : 'blocked'}
                                                </Badge>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            No entitlement records found.
                                        </p>
                                    )}
                                </div>

                                <Button
                                    type="button"
                                    onClick={() => void reconcileBilling()}
                                    disabled={billingReconciling}
                                >
                                    {billingReconciling ? (
                                        <>
                                            <Spinner className="mr-2" />
                                            Queuing reconcile
                                        </>
                                    ) : (
                                        'Reconcile billing state'
                                    )}
                                </Button>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
