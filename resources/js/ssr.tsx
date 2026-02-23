import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ReactDOMServer from 'react-dom/server';
import { I18nextProvider } from 'react-i18next';
import {
    createI18nRuntime,
    initializeI18nRuntimeSync,
    resolveI18nBootstrapPayload,
} from './i18n';
import { I18nBootstrapFailFast } from './i18n/bootstrap-fail-fast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) =>
            resolvePageComponent(
                `./pages/${name}.tsx`,
                import.meta.glob('./pages/**/*.tsx'),
            ),
        setup: ({ App, props }) => {
            const i18nPayload = resolveI18nBootstrapPayload(props.initialPage.props);

            if (i18nPayload === null) {
                return <I18nBootstrapFailFast appName={appName} />;
            }

            const ssrI18n = createI18nRuntime();
            initializeI18nRuntimeSync(ssrI18n, i18nPayload);

            return (
                <I18nextProvider i18n={ssrI18n}>
                    <App {...props} />
                </I18nextProvider>
            );
        },
    }),
);
