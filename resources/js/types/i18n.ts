export type TranslationDictionary = {
    [key: string]: string | TranslationDictionary;
};

export type I18nSharedProps = {
    locale: string;
    supportedLocales: string[];
    coreDictionary: TranslationDictionary;
    pageDictionary?: TranslationDictionary;
};

export type I18nBootstrapPayload = {
    locale: string;
    supportedLocales: string[];
    coreDictionary: TranslationDictionary;
};
