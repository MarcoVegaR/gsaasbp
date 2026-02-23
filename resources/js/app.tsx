import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import '../css/app.css';
import { initializeTheme } from './hooks/use-appearance';
import {
    clientI18n,
    initializeI18nRuntime,
    resolveI18nBootstrapPayload,
} from './i18n';
import { I18nBootstrapFailFast } from './i18n/bootstrap-fail-fast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    async setup({ el, App, props }) {
        const root = createRoot(el);

        const i18nPayload = resolveI18nBootstrapPayload(props.initialPage.props);

        if (i18nPayload === null) {
            root.render(
                <StrictMode>
                    <I18nBootstrapFailFast appName={appName} />
                </StrictMode>,
            );

            return;
        }

        await initializeI18nRuntime(clientI18n, i18nPayload);

        root.render(
            <StrictMode>
                <I18nextProvider i18n={clientI18n}>
                    <App {...props} />
                </I18nextProvider>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
