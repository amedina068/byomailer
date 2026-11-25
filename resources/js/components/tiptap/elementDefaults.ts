export interface ElementDefault {
    fontFamily?: string;
    fontSize?: string;
    color?: string;
    fontWeight?: string;
    marginTop?: string;
    marginBottom?: string;
    textDecoration?: string;
}

const SERIF = 'Georgia, "Times New Roman", serif';
const SANS = 'Arial, Helvetica, sans-serif';
const TEXT = '#1f2937';
const HEADING = '#111827';

export const ELEMENT_DEFAULTS: Record<string, ElementDefault> = {
    p: {
        fontFamily: SANS,
        fontSize: '16px',
        color: TEXT,
        fontWeight: '400',
        marginTop: '0',
        marginBottom: '16px',
    },
    h1: {
        fontFamily: SERIF,
        fontSize: '32px',
        color: HEADING,
        fontWeight: '700',
        marginTop: '24px',
        marginBottom: '12px',
    },
    h2: {
        fontFamily: SERIF,
        fontSize: '24px',
        color: HEADING,
        fontWeight: '700',
        marginTop: '20px',
        marginBottom: '10px',
    },
    h3: {
        fontFamily: SERIF,
        fontSize: '20px',
        color: HEADING,
        fontWeight: '600',
        marginTop: '16px',
        marginBottom: '8px',
    },
    ul: {
        fontFamily: SANS,
        fontSize: '16px',
        color: TEXT,
        fontWeight: '400',
        marginTop: '0',
        marginBottom: '16px',
    },
    ol: {
        fontFamily: SANS,
        fontSize: '16px',
        color: TEXT,
        fontWeight: '400',
        marginTop: '0',
        marginBottom: '16px',
    },
    blockquote: {
        fontFamily: SERIF,
        fontSize: '16px',
        color: '#4b5563',
        fontWeight: '400',
        marginTop: '16px',
        marginBottom: '16px',
    },
    a: {
        color: '#2563eb',
        textDecoration: 'underline',
    },
};

const DEFAULTABLE_PROPS: Array<[keyof ElementDefault, string]> = [
    ['fontFamily', 'font-family'],
    ['fontSize', 'font-size'],
    ['color', 'color'],
    ['fontWeight', 'font-weight'],
    ['marginTop', 'margin-top'],
    ['marginBottom', 'margin-bottom'],
    ['textDecoration', 'text-decoration'],
];

export const defaultsToCss = (selectorPrefix: string = ''): string => {
    const prefix = selectorPrefix ? `${selectorPrefix} ` : '';
    return Object.entries(ELEMENT_DEFAULTS)
        .map(([tag, def]) => {
            const lines = DEFAULTABLE_PROPS.map(([key, css]) => {
                const value = def[key];
                return value ? `  ${css}: ${value};` : null;
            })
                .filter((line): line is string => line !== null)
                .join('\n');
            return `${prefix}${tag} {\n${lines}\n}`;
        })
        .join('\n');
};

const hasStyleProperty = (style: string, property: string): boolean => {
    const pattern = new RegExp(`(?:^|;)\\s*${property}\\s*:`, 'i');
    return pattern.test(style);
};

const composeStyle = (existing: string, additions: Array<[string, string]>): string => {
    if (additions.length === 0) {
        return existing;
    }

    const additionStr = additions.map(([prop, val]) => `${prop}: ${val}`).join('; ');
    const trimmed = existing.trim().replace(/;$/, '');

    if (!trimmed) {
        return additionStr;
    }

    return `${trimmed}; ${additionStr}`;
};

export const inlineElementDefaults = (html: string): string => {
    if (!html) {
        return html;
    }

    if (typeof DOMParser === 'undefined') {
        return html;
    }

    const doc = new DOMParser().parseFromString(`<root>${html}</root>`, 'text/html');
    const root = doc.querySelector('root');
    if (!root) {
        return html;
    }

    for (const tag of Object.keys(ELEMENT_DEFAULTS)) {
        const def = ELEMENT_DEFAULTS[tag];
        const elements = root.querySelectorAll(tag);
        for (const el of Array.from(elements)) {
            const existing = el.getAttribute('style') ?? '';
            const additions: Array<[string, string]> = [];

            for (const [key, css] of DEFAULTABLE_PROPS) {
                const value = def[key];
                if (!value) {
                    continue;
                }
                if (hasStyleProperty(existing, css)) {
                    continue;
                }
                additions.push([css, value]);
            }

            if (additions.length === 0) {
                continue;
            }

            el.setAttribute('style', composeStyle(existing, additions));
        }
    }

    return root.innerHTML;
};
