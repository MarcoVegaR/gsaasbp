type I18nBootstrapFailFastProps = {
    appName: string;
};

export function I18nBootstrapFailFast({
    appName,
}: I18nBootstrapFailFastProps) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-background px-6 py-16 text-foreground">
            <div className="w-full max-w-xl rounded-xl border border-destructive/30 bg-card p-8 shadow-sm">
                <p className="text-xs font-semibold tracking-[0.12em] text-destructive uppercase">
                    I18N BOOTSTRAP ERROR
                </p>
                <h1 className="mt-3 text-2xl font-semibold">
                    {appName} could not load translations
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    The application stopped before mounting to prevent a broken
                    or untranslated UI. Verify that the backend is sharing a
                    non-empty <code>coreDictionary</code> in Inertia props.
                </p>
            </div>
        </main>
    );
}
