import { Deferred, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { mergeDictionary, resolveTranslationDictionary } from './index';

type PagePropsWithI18n = {
    locale?: unknown;
    coreDictionary?: unknown;
    pageDictionary?: unknown;
};

function PageDictionaryHydrator() {
    const { props } = usePage<PagePropsWithI18n>();
    const { i18n } = useTranslation();
    const locale =
        typeof props.locale === 'string' && props.locale.trim() !== ''
            ? props.locale
            : i18n.language;

    useEffect(() => {
        const coreDictionary = resolveTranslationDictionary(props.coreDictionary);

        if (coreDictionary === null) {
            return;
        }

        mergeDictionary(i18n, locale, coreDictionary);

        if (i18n.language !== locale) {
            void i18n.changeLanguage(locale);
        }
    }, [i18n, locale, props.coreDictionary]);

    useEffect(() => {
        const dictionary = resolveTranslationDictionary(props.pageDictionary);

        if (dictionary === null) {
            return;
        }

        mergeDictionary(i18n, locale, dictionary);
    }, [i18n, locale, props.pageDictionary]);

    return null;
}

export function I18nPageDictionaryBridge() {
    return (
        <Deferred data="pageDictionary" fallback={null}>
            <PageDictionaryHydrator />
        </Deferred>
    );
}
