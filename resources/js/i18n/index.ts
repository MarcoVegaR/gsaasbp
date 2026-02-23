import { createInstance, type i18n as I18nInstance } from 'i18next';
import { initReactI18next } from 'react-i18next';
import type { I18nBootstrapPayload, TranslationDictionary } from '@/types';

type UnknownRecord = Record<string, unknown>;

const DEFAULT_LOCALE = 'en';

function isObjectRecord(value: unknown): value is UnknownRecord {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function normalizeLocale(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

function normalizeSupportedLocales(value: unknown, fallbackLocale: string): string[] {
    if (!Array.isArray(value)) {
        return [fallbackLocale];
    }

    const locales = value
        .map((entry) => normalizeLocale(entry))
        .filter((entry): entry is string => entry !== null);

    if (locales.length === 0) {
        return [fallbackLocale];
    }

    return Array.from(new Set(locales));
}

export function resolveTranslationDictionary(
    value: unknown,
): TranslationDictionary | null {
    if (!isObjectRecord(value)) {
        return null;
    }

    for (const entry of Object.values(value)) {
        if (typeof entry === 'string') {
            continue;
        }

        if (resolveTranslationDictionary(entry) === null) {
            return null;
        }
    }

    return value as TranslationDictionary;
}

export function resolveI18nBootstrapPayload(
    pageProps: unknown,
): I18nBootstrapPayload | null {
    if (!isObjectRecord(pageProps)) {
        return null;
    }

    const locale = normalizeLocale(pageProps.locale);
    const coreDictionary = resolveTranslationDictionary(pageProps.coreDictionary);

    if (
        locale === null ||
        coreDictionary === null ||
        Object.keys(coreDictionary).length === 0
    ) {
        return null;
    }

    return {
        locale,
        supportedLocales: normalizeSupportedLocales(pageProps.supportedLocales, locale),
        coreDictionary,
    };
}

export function createI18nRuntime(): I18nInstance {
    return createInstance();
}

export const clientI18n = createI18nRuntime();

export async function initializeI18nRuntime(
    i18n: I18nInstance,
    payload: I18nBootstrapPayload,
): Promise<void> {
    const locale = payload.locale || DEFAULT_LOCALE;
    const supportedLocales =
        payload.supportedLocales.length > 0 ? payload.supportedLocales : [locale];

    if (!i18n.isInitialized) {
        await i18n.use(initReactI18next).init({
            lng: locale,
            fallbackLng: locale,
            supportedLngs: supportedLocales,
            defaultNS: 'translation',
            ns: ['translation'],
            resources: {
                [locale]: {
                    translation: payload.coreDictionary,
                },
            },
            interpolation: {
                escapeValue: false,
            },
            returnEmptyString: false,
            react: {
                useSuspense: false,
            },
        });

        return;
    }

    mergeDictionary(i18n, locale, payload.coreDictionary);

    if (i18n.language !== locale) {
        await i18n.changeLanguage(locale);
    }
}

export function initializeI18nRuntimeSync(
    i18n: I18nInstance,
    payload: I18nBootstrapPayload,
): void {
    const locale = payload.locale || DEFAULT_LOCALE;
    const supportedLocales =
        payload.supportedLocales.length > 0 ? payload.supportedLocales : [locale];

    if (!i18n.isInitialized) {
        i18n.use(initReactI18next);
        i18n.init({
            lng: locale,
            fallbackLng: locale,
            supportedLngs: supportedLocales,
            defaultNS: 'translation',
            ns: ['translation'],
            resources: {
                [locale]: {
                    translation: payload.coreDictionary,
                },
            },
            interpolation: {
                escapeValue: false,
            },
            returnEmptyString: false,
            initImmediate: false,
            react: {
                useSuspense: false,
            },
        });

        return;
    }

    mergeDictionary(i18n, locale, payload.coreDictionary);

    if (i18n.language !== locale) {
        void i18n.changeLanguage(locale);
    }
}

export function mergeDictionary(
    i18n: I18nInstance,
    locale: string,
    dictionary: TranslationDictionary,
): void {
    if (Object.keys(dictionary).length === 0) {
        return;
    }

    i18n.addResourceBundle(locale, 'translation', dictionary, true, true);
}
