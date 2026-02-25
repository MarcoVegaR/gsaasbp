import { Head, usePage } from '@inertiajs/react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import admin from '@/routes/admin';
import type { BreadcrumbItem } from '@/types';

type RequestFailure = {
    status: number;
    payload: unknown;
};

type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type TenantRow = {
    tenant_id: string;
    domain: string | null;
    status: string;
    status_changed_at: string | null;
    created_at: string | null;
    billing_provider: string | null;
    billing_status: string | null;
    entitlements_granted: number;
};

type TenantPayload = {
    data: TenantRow[];
    meta: PaginationMeta;
};

type TelemetryPoint = {
    bucket_start: string;
    value: number | null;
    tenant_count: number;
    suppressed: boolean;
    suppression_key: string | null;
};

type TelemetryPayload = {
    from: string;
    to: string;
    series: TelemetryPoint[];
};

type ForensicRow = {
    id: number;
    tenant_id: string;
    event: string;
    request_id: string | null;
    actor_id: number | null;
    hmac_kid: string | null;
    created_at: string | null;
    redacted: boolean;
};

type ForensicPayload = {
    data: ForensicRow[];
    meta: PaginationMeta;
};

type BillingRow = {
    event_id: string;
    tenant_id: string;
    provider: string;
    outcome_hash: string;
    provider_object_version: number;
    processed_at: string | null;
    divergence: boolean;
};

type BillingPayload = {
    data: BillingRow[];
    meta: PaginationMeta;
};

type ImpersonationIssuePayload = {
    status: string;
    jti: string;
    assertion: string;
    state: string;
    consume_url: string;
    expires_at: string | null;
};

type AdminPanelProps = {
    guard: string;
    session_timeout_seconds: number;
    privacy_budget_window_seconds: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Central admin panel',
        href: admin.panel().url,
    },
];

const stepUpScopes = [
    'platform.tenants.hard-delete',
    'platform.audit.export',
    'platform.billing.reconcile',
    'platform.impersonation.issue',
] as const;

function getCookie(name: string): string | null {
    const cookieEntry = document.cookie
        .split(';')
        .map((entry) => entry.trim())
        .find((entry) => entry.startsWith(`${name}=`));

    if (cookieEntry === undefined) {
        return null;
    }

    return decodeURIComponent(cookieEntry.slice(name.length + 1));
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
            const messages = Object.values(nestedErrors)
                .flatMap((entry) =>
                    Array.isArray(entry)
                        ? entry
                              .filter((item): item is string => typeof item === 'string')
                              .map((item) => item.trim())
                        : [],
                )
                .filter((item) => item !== '');

            if (messages.length > 0) {
                return messages;
            }
        }

        const message = payload.message;

        if (typeof message === 'string' && message.trim() !== '') {
            return [message.trim()];
        }
    }

    return [fallback];
}

function datetimeLocalValue(date: Date): string {
    const shifted = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return shifted.toISOString().slice(0, 16);
}

function toIso(value: string): string | null {
    if (value.trim() === '') {
        return null;
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return parsed.toISOString();
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

export default function AdminPanel({
    guard,
    session_timeout_seconds,
    privacy_budget_window_seconds,
}: AdminPanelProps) {
    const { auth } = usePage().props;
    const [stepUpByScope, setStepUpByScope] = useState<Record<string, string>>({});
    const [stepUpBusy, setStepUpBusy] = useState<string | null>(null);

    const [tenantRows, setTenantRows] = useState<TenantRow[]>([]);
    const [tenantMeta, setTenantMeta] = useState<PaginationMeta | null>(null);
    const [tenantLoading, setTenantLoading] = useState(true);
    const [tenantErrors, setTenantErrors] = useState<string[]>([]);
    const [tenantNotice, setTenantNotice] = useState<string | null>(null);

    const [statusTenantId, setStatusTenantId] = useState('');
    const [statusValue, setStatusValue] = useState('suspended');

    const [approvalTenantId, setApprovalTenantId] = useState('');
    const [approvalRequestedBy, setApprovalRequestedBy] = useState('');
    const [approvalExecutor, setApprovalExecutor] = useState('');
    const [approvalReasonCode, setApprovalReasonCode] = useState('retention_expired');
    const [hardDeleteApprovalId, setHardDeleteApprovalId] = useState('');

    const [telemetryFromInput, setTelemetryFromInput] = useState(
        datetimeLocalValue(new Date(Date.now() - 24 * 60 * 60 * 1_000)),
    );
    const [telemetryToInput, setTelemetryToInput] = useState(datetimeLocalValue(new Date()));
    const [telemetryEvent, setTelemetryEvent] = useState('');
    const [telemetryLoading, setTelemetryLoading] = useState(true);
    const [telemetryErrors, setTelemetryErrors] = useState<string[]>([]);
    const [telemetryPayload, setTelemetryPayload] = useState<TelemetryPayload | null>(null);

    const [forensicsFromInput, setForensicsFromInput] = useState(
        datetimeLocalValue(new Date(Date.now() - 24 * 60 * 60 * 1_000)),
    );
    const [forensicsToInput, setForensicsToInput] = useState(datetimeLocalValue(new Date()));
    const [forensicsTenantId, setForensicsTenantId] = useState('');
    const [forensicsEvent, setForensicsEvent] = useState('');
    const [forensicsRows, setForensicsRows] = useState<ForensicRow[]>([]);
    const [forensicsMeta, setForensicsMeta] = useState<PaginationMeta | null>(null);
    const [forensicsLoading, setForensicsLoading] = useState(true);
    const [forensicsErrors, setForensicsErrors] = useState<string[]>([]);
    const [forensicsNotice, setForensicsNotice] = useState<string | null>(null);
    const [forensicExportId, setForensicExportId] = useState('');
    const [forensicDownloadToken, setForensicDownloadToken] = useState('');

    const [billingFromInput, setBillingFromInput] = useState(
        datetimeLocalValue(new Date(Date.now() - 24 * 60 * 60 * 1_000)),
    );
    const [billingToInput, setBillingToInput] = useState(datetimeLocalValue(new Date()));
    const [billingTenantId, setBillingTenantId] = useState('');
    const [billingEventId, setBillingEventId] = useState('');
    const [billingRows, setBillingRows] = useState<BillingRow[]>([]);
    const [billingMeta, setBillingMeta] = useState<PaginationMeta | null>(null);
    const [billingLoading, setBillingLoading] = useState(true);
    const [billingErrors, setBillingErrors] = useState<string[]>([]);
    const [billingNotice, setBillingNotice] = useState<string | null>(null);

    const [impTenantId, setImpTenantId] = useState('');
    const [impUserId, setImpUserId] = useState('');
    const [impTenantDomain, setImpTenantDomain] = useState('');
    const [impReasonCode, setImpReasonCode] = useState('support_case');
    const [impRedirectPath, setImpRedirectPath] = useState('/tenant/dashboard');
    const [terminateJti, setTerminateJti] = useState('');
    const [impErrors, setImpErrors] = useState<string[]>([]);
    const [impNotice, setImpNotice] = useState<string | null>(null);
    const [issuedImpersonation, setIssuedImpersonation] = useState<ImpersonationIssuePayload | null>(null);

    const hardDeleteCapability = stepUpByScope['platform.tenants.hard-delete'] ?? '';
    const auditCapability = stepUpByScope['platform.audit.export'] ?? '';
    const billingCapability = stepUpByScope['platform.billing.reconcile'] ?? '';
    const impersonationCapability = stepUpByScope['platform.impersonation.issue'] ?? '';

    const issueStepUp = async (scope: (typeof stepUpScopes)[number]): Promise<void> => {
        setStepUpBusy(scope);
        setTenantNotice(null);
        setForensicsNotice(null);
        setBillingNotice(null);
        setImpNotice(null);

        try {
            const payload = await apiJson<{ capability_id: string }>(
                '/admin/step-up/capabilities',
                {
                    method: 'POST',
                    body: JSON.stringify({ scope, ttl_seconds: 600 }),
                },
            );

            setStepUpByScope((current) => ({
                ...current,
                [scope]: payload.capability_id,
            }));
        } catch (error: unknown) {
            const errors = extractErrorMessages(error, 'Unable to issue step-up capability.');
            setTenantErrors(errors);
        } finally {
            setStepUpBusy(null);
        }
    };

    const loadTenants = async (): Promise<void> => {
        setTenantLoading(true);
        setTenantErrors([]);

        try {
            const payload = await apiJson<TenantPayload>('/admin/tenants?per_page=25');
            setTenantRows(payload.data);
            setTenantMeta(payload.meta);
        } catch (error: unknown) {
            setTenantErrors(extractErrorMessages(error, 'Unable to load tenant directory.'));
        } finally {
            setTenantLoading(false);
        }
    };

    const updateTenantStatus = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        if (statusTenantId.trim() === '') {
            setTenantErrors(['Tenant id is required to update status.']);
            return;
        }

        setTenantErrors([]);
        setTenantNotice(null);

        try {
            await apiJson('/admin/tenants/status', {
                method: 'POST',
                body: JSON.stringify({
                    tenant_id: statusTenantId.trim(),
                    status: statusValue.trim(),
                }),
            });

            setTenantNotice('Tenant status updated successfully.');
            await loadTenants();
        } catch (error: unknown) {
            setTenantErrors(extractErrorMessages(error, 'Unable to update tenant status.'));
        }
    };

    const issueHardDeleteApproval = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        const requestedBy = Number(approvalRequestedBy);
        const executor = Number(approvalExecutor);

        if (
            approvalTenantId.trim() === '' ||
            !Number.isInteger(requestedBy) ||
            requestedBy <= 0 ||
            !Number.isInteger(executor) ||
            executor <= 0
        ) {
            setTenantErrors(['Approval requires tenant id, requested by id and executor id.']);
            return;
        }

        setTenantErrors([]);
        setTenantNotice(null);

        try {
            const payload = await apiJson<{ approval_id: string }>(
                `/admin/tenants/${encodeURIComponent(approvalTenantId.trim())}/hard-delete-approvals`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        requested_by_platform_user_id: requestedBy,
                        executor_platform_user_id: executor,
                        reason_code: approvalReasonCode.trim(),
                    }),
                },
            );

            setHardDeleteApprovalId(payload.approval_id);
            setTenantNotice(`Hard-delete approval issued (${payload.approval_id}).`);
        } catch (error: unknown) {
            setTenantErrors(extractErrorMessages(error, 'Unable to issue hard-delete approval.'));
        }
    };

    const executeHardDelete = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        if (
            approvalTenantId.trim() === '' ||
            hardDeleteApprovalId.trim() === '' ||
            hardDeleteCapability.trim() === ''
        ) {
            setTenantErrors([
                'Hard delete requires tenant id, approval id, and a step-up capability for platform.tenants.hard-delete.',
            ]);
            return;
        }

        setTenantErrors([]);
        setTenantNotice(null);

        try {
            await apiJson(`/admin/tenants/${encodeURIComponent(approvalTenantId.trim())}`, {
                method: 'DELETE',
                body: JSON.stringify({
                    approval_id: hardDeleteApprovalId.trim(),
                    reason_code: approvalReasonCode.trim(),
                    capability_id: hardDeleteCapability.trim(),
                }),
            });

            setTenantNotice('Tenant hard delete accepted by lifecycle service.');
            await loadTenants();
        } catch (error: unknown) {
            setTenantErrors(extractErrorMessages(error, 'Unable to execute hard delete.'));
        }
    };

    const loadTelemetry = async (): Promise<void> => {
        setTelemetryLoading(true);
        setTelemetryErrors([]);

        const fromIso = toIso(telemetryFromInput);
        const toIsoValue = toIso(telemetryToInput);

        if (fromIso === null || toIsoValue === null) {
            setTelemetryErrors(['Telemetry requires valid from/to timestamps.']);
            setTelemetryLoading(false);
            return;
        }

        const params = new URLSearchParams({ from: fromIso, to: toIsoValue });

        if (telemetryEvent.trim() !== '') {
            params.set('event', telemetryEvent.trim());
        }

        try {
            const payload = await apiJson<TelemetryPayload>(`/admin/telemetry/analytics?${params.toString()}`);
            setTelemetryPayload(payload);
        } catch (error: unknown) {
            setTelemetryErrors(extractErrorMessages(error, 'Unable to load telemetry analytics.'));
        } finally {
            setTelemetryLoading(false);
        }
    };

    const loadForensics = async (): Promise<void> => {
        setForensicsLoading(true);
        setForensicsErrors([]);

        const fromIso = toIso(forensicsFromInput);
        const toIsoValue = toIso(forensicsToInput);

        if (fromIso === null || toIsoValue === null) {
            setForensicsErrors(['Forensics requires valid from/to timestamps.']);
            setForensicsLoading(false);
            return;
        }

        const params = new URLSearchParams({ from: fromIso, to: toIsoValue });

        if (forensicsTenantId.trim() !== '') {
            params.set('tenant_id', forensicsTenantId.trim());
        }

        if (forensicsEvent.trim() !== '') {
            params.set('event', forensicsEvent.trim());
        }

        try {
            const payload = await apiJson<ForensicPayload>(`/admin/forensics/audit?${params.toString()}`);
            setForensicsRows(payload.data);
            setForensicsMeta(payload.meta);
        } catch (error: unknown) {
            setForensicsErrors(extractErrorMessages(error, 'Unable to load forensic audit rows.'));
        } finally {
            setForensicsLoading(false);
        }
    };

    const requestForensicExport = async (): Promise<void> => {
        const fromIso = toIso(forensicsFromInput);
        const toIsoValue = toIso(forensicsToInput);

        if (fromIso === null || toIsoValue === null || auditCapability.trim() === '') {
            setForensicsErrors(['Export requires valid from/to and a platform.audit.export capability.']);
            return;
        }

        setForensicsErrors([]);
        setForensicsNotice(null);

        try {
            const payload = await apiJson<{ export_id: string }>('/admin/forensics/exports', {
                method: 'POST',
                body: JSON.stringify({
                    tenant_id: forensicsTenantId.trim() !== '' ? forensicsTenantId.trim() : null,
                    event: forensicsEvent.trim() !== '' ? forensicsEvent.trim() : null,
                    from: fromIso,
                    to: toIsoValue,
                    reason_code: 'panel_export',
                    capability_id: auditCapability,
                }),
            });

            setForensicExportId(payload.export_id);
            setForensicsNotice(`Forensic export queued (${payload.export_id}).`);
        } catch (error: unknown) {
            setForensicsErrors(extractErrorMessages(error, 'Unable to request forensic export.'));
        }
    };

    const issueForensicToken = async (): Promise<void> => {
        if (forensicExportId.trim() === '' || auditCapability.trim() === '') {
            setForensicsErrors(['Issue a forensic token requires export id and platform.audit.export capability.']);
            return;
        }

        setForensicsErrors([]);

        try {
            const payload = await apiJson<{ token: string }>(
                `/admin/forensics/exports/${encodeURIComponent(forensicExportId.trim())}/token`,
                {
                    method: 'POST',
                    body: JSON.stringify({ capability_id: auditCapability }),
                },
            );

            setForensicDownloadToken(payload.token);
            setForensicsNotice('One-time forensic download token issued.');
        } catch (error: unknown) {
            setForensicsErrors(extractErrorMessages(error, 'Unable to issue forensic download token.'));
        }
    };

    const downloadForensicExport = async (): Promise<void> => {
        if (forensicDownloadToken.trim() === '') {
            setForensicsErrors(['Forensic download token is required.']);
            return;
        }

        setForensicsErrors([]);

        try {
            const response = await fetch('/admin/forensics/exports/download', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(getCookie('XSRF-TOKEN') !== null
                        ? { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') ?? '' }
                        : {}),
                },
                body: JSON.stringify({ token: forensicDownloadToken.trim() }),
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => ({ message: 'Request failed.' }));
                throw { status: response.status, payload } satisfies RequestFailure;
            }

            const content = await response.text();
            const parsed = JSON.parse(content) as unknown[];
            setForensicsNotice(`Forensic export downloaded (${parsed.length} rows).`);
        } catch (error: unknown) {
            setForensicsErrors(extractErrorMessages(error, 'Unable to download forensic export payload.'));
        }
    };

    const loadBilling = async (): Promise<void> => {
        setBillingLoading(true);
        setBillingErrors([]);

        const fromIso = toIso(billingFromInput);
        const toIsoValue = toIso(billingToInput);

        if (fromIso === null || toIsoValue === null) {
            setBillingErrors(['Billing events require valid from/to timestamps.']);
            setBillingLoading(false);
            return;
        }

        const params = new URLSearchParams({ from: fromIso, to: toIsoValue });

        if (billingTenantId.trim() !== '') {
            params.set('tenant_id', billingTenantId.trim());
        }

        if (billingEventId.trim() !== '') {
            params.set('event_id', billingEventId.trim());
        }

        try {
            const payload = await apiJson<BillingPayload>(`/admin/billing/events?${params.toString()}`);
            setBillingRows(payload.data);
            setBillingMeta(payload.meta);
        } catch (error: unknown) {
            setBillingErrors(extractErrorMessages(error, 'Unable to load billing event index.'));
        } finally {
            setBillingLoading(false);
        }
    };

    const queueBillingReconcile = async (): Promise<void> => {
        if (billingTenantId.trim() === '' || billingCapability.trim() === '') {
            setBillingErrors(['Billing reconcile requires tenant id and platform.billing.reconcile capability.']);
            return;
        }

        setBillingErrors([]);
        setBillingNotice(null);

        try {
            await apiJson('/admin/billing/reconcile', {
                method: 'POST',
                body: JSON.stringify({
                    tenant_id: billingTenantId.trim(),
                    capability_id: billingCapability,
                }),
            });

            setBillingNotice('Billing reconciliation was queued successfully.');
        } catch (error: unknown) {
            setBillingErrors(extractErrorMessages(error, 'Unable to queue billing reconciliation.'));
        }
    };

    const issueImpersonation = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        const targetUserId = Number(impUserId);

        if (
            impTenantId.trim() === '' ||
            impTenantDomain.trim() === '' ||
            !Number.isInteger(targetUserId) ||
            targetUserId <= 0 ||
            impersonationCapability.trim() === ''
        ) {
            setImpErrors(['Impersonation requires tenant id, tenant domain, target user id, and step-up capability.']);
            return;
        }

        setImpErrors([]);
        setImpNotice(null);

        try {
            const payload = await apiJson<ImpersonationIssuePayload>('/admin/impersonation/issue', {
                method: 'POST',
                body: JSON.stringify({
                    target_tenant_id: impTenantId.trim(),
                    target_user_id: targetUserId,
                    tenant_domain: impTenantDomain.trim(),
                    reason_code: impReasonCode.trim(),
                    redirect_path: impRedirectPath.trim(),
                    capability_id: impersonationCapability,
                }),
            });

            setIssuedImpersonation(payload);
            setTerminateJti(payload.jti);
            setImpNotice('Impersonation assertion issued successfully.');
        } catch (error: unknown) {
            setImpErrors(extractErrorMessages(error, 'Unable to issue impersonation session.'));
        }
    };

    const terminateImpersonation = async (): Promise<void> => {
        if (terminateJti.trim() === '') {
            setImpErrors(['JTI is required to terminate impersonation.']);
            return;
        }

        setImpErrors([]);
        setImpNotice(null);

        try {
            await apiJson('/admin/impersonation/terminate', {
                method: 'POST',
                body: JSON.stringify({ jti: terminateJti.trim() }),
            });

            setImpNotice('Impersonation session terminated.');
        } catch (error: unknown) {
            setImpErrors(extractErrorMessages(error, 'Unable to terminate impersonation session.'));
        }
    };

    useEffect(() => {
        void loadTenants();
        void loadTelemetry();
        void loadForensics();
        void loadBilling();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const telemetryVisibleCount = useMemo(
        () => telemetryPayload?.series.filter((bucket) => bucket.value !== null).length ?? 0,
        [telemetryPayload],
    );

    const resolvedGuard = typeof auth?.guard === 'string' ? auth.guard : guard;

    if (resolvedGuard !== 'platform') {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Central admin panel" />
                <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                    <Alert variant="destructive">
                        <AlertTitle>Admin guard mismatch detected</AlertTitle>
                        <AlertDescription>
                            The central admin panel can only mount under the
                            platform guard. Detected guard: {resolvedGuard}.
                        </AlertDescription>
                    </Alert>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Central admin panel" />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Platform control plane</CardTitle>
                        <CardDescription>
                            Guard <strong>{guard}</strong> · Session timeout {session_timeout_seconds}s · Privacy budget window {privacy_budget_window_seconds}s.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-wrap gap-2">
                            {stepUpScopes.map((scope) => (
                                <Button
                                    key={scope}
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    disabled={stepUpBusy !== null}
                                    onClick={() => void issueStepUp(scope)}
                                >
                                    {stepUpBusy === scope ? <Spinner className="mr-2" /> : null}
                                    Issue {scope}
                                </Button>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">Capabilities are short-lived and consumed single-use by protected admin mutations.</p>
                    </CardContent>
                </Card>

                <Card id="tenants">
                    <CardHeader>
                        <CardTitle>Tenant lifecycle and hard-delete workflow</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {tenantErrors.length > 0 && <AlertError errors={tenantErrors} />}
                        {tenantNotice !== null && (
                            <Alert>
                                <AlertTitle>Tenant workflow</AlertTitle>
                                <AlertDescription>{tenantNotice}</AlertDescription>
                            </Alert>
                        )}

                        <form className="grid gap-3 md:grid-cols-4" onSubmit={updateTenantStatus}>
                            <Input placeholder="tenant_id" value={statusTenantId} onChange={(event) => setStatusTenantId(event.target.value)} />
                            <Input placeholder="status (active/suspended/hard_deleted)" value={statusValue} onChange={(event) => setStatusValue(event.target.value)} />
                            <div className="md:col-span-2">
                                <Button type="submit">Update status</Button>
                            </div>
                        </form>

                        <form className="grid gap-3 md:grid-cols-4" onSubmit={issueHardDeleteApproval}>
                            <Input placeholder="tenant_id" value={approvalTenantId} onChange={(event) => setApprovalTenantId(event.target.value)} />
                            <Input placeholder="requested_by_platform_user_id" value={approvalRequestedBy} onChange={(event) => setApprovalRequestedBy(event.target.value)} />
                            <Input placeholder="executor_platform_user_id" value={approvalExecutor} onChange={(event) => setApprovalExecutor(event.target.value)} />
                            <Input placeholder="reason_code" value={approvalReasonCode} onChange={(event) => setApprovalReasonCode(event.target.value)} />
                            <div className="md:col-span-4">
                                <Button type="submit" variant="secondary">Issue hard-delete approval</Button>
                            </div>
                        </form>

                        <form className="grid gap-3 md:grid-cols-4" onSubmit={executeHardDelete}>
                            <Input placeholder="approval_id" value={hardDeleteApprovalId} onChange={(event) => setHardDeleteApprovalId(event.target.value)} />
                            <Input readOnly value={hardDeleteCapability} placeholder="step-up capability platform.tenants.hard-delete" />
                            <Input readOnly value={approvalReasonCode} placeholder="reason_code" />
                            <div>
                                <Button type="submit" variant="destructive">Execute hard delete</Button>
                            </div>
                        </form>

                        {tenantLoading ? (
                            <div className="flex items-center text-sm text-muted-foreground"><Spinner className="mr-2" />Loading tenants...</div>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="px-3 py-2">Tenant</th>
                                            <th className="px-3 py-2">Domain</th>
                                            <th className="px-3 py-2">Status</th>
                                            <th className="px-3 py-2">Billing</th>
                                            <th className="px-3 py-2">Entitlements</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {tenantRows.map((tenant) => (
                                            <tr key={tenant.tenant_id} className="border-t">
                                                <td className="px-3 py-2 font-medium">{tenant.tenant_id}</td>
                                                <td className="px-3 py-2">{tenant.domain ?? '-'}</td>
                                                <td className="px-3 py-2"><Badge variant="secondary">{tenant.status}</Badge></td>
                                                <td className="px-3 py-2">{tenant.billing_provider ?? '-'} / {tenant.billing_status ?? '-'}</td>
                                                <td className="px-3 py-2">{tenant.entitlements_granted}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {tenantRows.length === 0 && <p className="px-3 py-4 text-sm text-muted-foreground">No tenants matched current projection.</p>}
                            </div>
                        )}
                        {tenantMeta !== null && <p className="text-xs text-muted-foreground">Tenant directory total: {tenantMeta.total}</p>}
                    </CardContent>
                </Card>

                <Card id="telemetry">
                    <CardHeader>
                        <CardTitle>Telemetry analytics</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {telemetryErrors.length > 0 && <AlertError errors={telemetryErrors} />}
                        <div className="grid gap-3 md:grid-cols-4">
                            <div className="grid gap-1"><Label>From</Label><Input type="datetime-local" value={telemetryFromInput} onChange={(event) => setTelemetryFromInput(event.target.value)} /></div>
                            <div className="grid gap-1"><Label>To</Label><Input type="datetime-local" value={telemetryToInput} onChange={(event) => setTelemetryToInput(event.target.value)} /></div>
                            <div className="grid gap-1"><Label>Event</Label><Input value={telemetryEvent} onChange={(event) => setTelemetryEvent(event.target.value)} placeholder="telemetry.signal" /></div>
                            <div className="flex items-end"><Button type="button" onClick={() => void loadTelemetry()}>{telemetryLoading ? <Spinner className="mr-2" /> : null}Refresh analytics</Button></div>
                        </div>
                        <p className="text-sm text-muted-foreground">Visible buckets: {telemetryVisibleCount}. Suppressed buckets remain null by k-anonymity.</p>
                    </CardContent>
                </Card>

                <Card id="forensics">
                    <CardHeader>
                        <CardTitle>Forensics explorer and export</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {forensicsErrors.length > 0 && <AlertError errors={forensicsErrors} />}
                        {forensicsNotice !== null && <Alert><AlertTitle>Forensics</AlertTitle><AlertDescription>{forensicsNotice}</AlertDescription></Alert>}

                        <div className="grid gap-3 md:grid-cols-4">
                            <Input type="datetime-local" value={forensicsFromInput} onChange={(event) => setForensicsFromInput(event.target.value)} />
                            <Input type="datetime-local" value={forensicsToInput} onChange={(event) => setForensicsToInput(event.target.value)} />
                            <Input placeholder="tenant_id" value={forensicsTenantId} onChange={(event) => setForensicsTenantId(event.target.value)} />
                            <Input placeholder="event" value={forensicsEvent} onChange={(event) => setForensicsEvent(event.target.value)} />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" onClick={() => void loadForensics()}>{forensicsLoading ? <Spinner className="mr-2" /> : null}Refresh logs</Button>
                            <Button type="button" variant="secondary" onClick={() => void requestForensicExport()}>Queue export</Button>
                            <Button type="button" variant="secondary" onClick={() => void issueForensicToken()}>Issue token</Button>
                            <Button type="button" variant="secondary" onClick={() => void downloadForensicExport()}>Download payload</Button>
                        </div>
                        <div className="grid gap-2 md:grid-cols-2">
                            <Input placeholder="export_id" value={forensicExportId} onChange={(event) => setForensicExportId(event.target.value)} />
                            <Input placeholder="download token" value={forensicDownloadToken} onChange={(event) => setForensicDownloadToken(event.target.value)} />
                        </div>
                        <Input readOnly value={auditCapability} placeholder="step-up capability platform.audit.export" />

                        {forensicsMeta !== null && <p className="text-xs text-muted-foreground">Forensics rows in page: {forensicsRows.length} / total {forensicsMeta.total}</p>}
                    </CardContent>
                </Card>

                <Card id="billing">
                    <CardHeader>
                        <CardTitle>Billing events and reconcile</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {billingErrors.length > 0 && <AlertError errors={billingErrors} />}
                        {billingNotice !== null && <Alert><AlertTitle>Billing</AlertTitle><AlertDescription>{billingNotice}</AlertDescription></Alert>}

                        <div className="grid gap-3 md:grid-cols-4">
                            <Input type="datetime-local" value={billingFromInput} onChange={(event) => setBillingFromInput(event.target.value)} />
                            <Input type="datetime-local" value={billingToInput} onChange={(event) => setBillingToInput(event.target.value)} />
                            <Input placeholder="tenant_id" value={billingTenantId} onChange={(event) => setBillingTenantId(event.target.value)} />
                            <Input placeholder="event_id" value={billingEventId} onChange={(event) => setBillingEventId(event.target.value)} />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" onClick={() => void loadBilling()}>{billingLoading ? <Spinner className="mr-2" /> : null}Refresh events</Button>
                            <Button type="button" variant="secondary" onClick={() => void queueBillingReconcile()}>Queue reconcile</Button>
                        </div>
                        <Input readOnly value={billingCapability} placeholder="step-up capability platform.billing.reconcile" />
                        {billingMeta !== null && <p className="text-xs text-muted-foreground">Billing events in page: {billingRows.length} / total {billingMeta.total}</p>}
                    </CardContent>
                </Card>

                <Card id="impersonation">
                    <CardHeader>
                        <CardTitle>Impersonation (break-glass)</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {impErrors.length > 0 && <AlertError errors={impErrors} />}
                        {impNotice !== null && <Alert><AlertTitle>Impersonation</AlertTitle><AlertDescription>{impNotice}</AlertDescription></Alert>}

                        <form className="grid gap-3 md:grid-cols-2" onSubmit={issueImpersonation}>
                            <Input placeholder="target_tenant_id" value={impTenantId} onChange={(event) => setImpTenantId(event.target.value)} />
                            <Input placeholder="target_user_id" value={impUserId} onChange={(event) => setImpUserId(event.target.value)} />
                            <Input placeholder="tenant_domain" value={impTenantDomain} onChange={(event) => setImpTenantDomain(event.target.value)} />
                            <Input placeholder="reason_code" value={impReasonCode} onChange={(event) => setImpReasonCode(event.target.value)} />
                            <Input className="md:col-span-2" placeholder="redirect_path" value={impRedirectPath} onChange={(event) => setImpRedirectPath(event.target.value)} />
                            <Input className="md:col-span-2" readOnly value={impersonationCapability} placeholder="step-up capability platform.impersonation.issue" />
                            <div className="md:col-span-2"><Button type="submit">Issue impersonation assertion</Button></div>
                        </form>

                        <div className="grid gap-2 md:grid-cols-2">
                            <Input placeholder="jti to terminate" value={terminateJti} onChange={(event) => setTerminateJti(event.target.value)} />
                            <Button type="button" variant="secondary" onClick={() => void terminateImpersonation()}>Terminate impersonation</Button>
                        </div>

                        {issuedImpersonation !== null && (
                            <div className="rounded-lg border p-3 text-xs text-muted-foreground">
                                <p><strong>Issued jti:</strong> {issuedImpersonation.jti}</p>
                                <p><strong>Consume URL:</strong> {issuedImpersonation.consume_url}</p>
                                <p><strong>Expires:</strong> {issuedImpersonation.expires_at ?? '-'}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
