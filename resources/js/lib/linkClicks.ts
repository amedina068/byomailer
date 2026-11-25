export interface LinkClickItem {
    key: string;
    name: string | null;
    url: string | null;
    unique_contacts: number;
    total_clicks: number;
    click_rate: number | null;
}

export interface LinkClickSummary {
    delivered: number;
    links: LinkClickItem[];
}

export const LINK_NAME_MAX_LENGTH = 100;

const KEY_PREFIXES = ['name:', 'url:'];

/**
 * The label of a link key from the report (`name:<name>` or `url:<url>`).
 */
export const linkKeyLabel = (key: string): string => {
    const prefix = KEY_PREFIXES.find((candidate) => key.startsWith(candidate));

    return prefix ? key.slice(prefix.length) : key;
};

/**
 * A copy of `App\Support\NamedLink::normalize()` (Laravel `Str::slug`). The
 * server normalizes the name again, so this copy only shows the result for
 * usual input. Returns null when no letter or digit is left.
 */
export const normalizeLinkName = (name: string): string | null => {
    const slug = name
        .normalize('NFKD')
        .replace(/\p{M}+/gu, '')
        .replace(/_+/g, '-')
        .replace(/@/g, '-at-')
        .toLowerCase()
        .replace(/[^-\p{L}\p{N}\s]+/gu, '')
        .replace(/[-\s]+/gu, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, LINK_NAME_MAX_LENGTH)
        .replace(/-+$/g, '');

    return slug === '' ? null : slug;
};
