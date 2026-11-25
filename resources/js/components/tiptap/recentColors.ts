import { isValidHexColor, normalizeHexColor } from '@/components/tiptap/typography';

export const RECENT_COLORS_KEY = 'bm.tiptap.recentColors';
export const RECENT_COLORS_LIMIT = 5;

export const loadRecentColors = (): string[] => {
    try {
        const raw = localStorage.getItem(RECENT_COLORS_KEY);
        if (!raw) {
            return [];
        }

        const parsed: unknown = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.filter((item): item is string => typeof item === 'string' && isValidHexColor(item));
    } catch {
        return [];
    }
};

export const pushRecentColor = (color: string): string[] => {
    const normalized = normalizeHexColor(color);
    if (!normalized) {
        return loadRecentColors();
    }

    const current = loadRecentColors().filter((c) => c !== normalized);
    const next = [normalized, ...current].slice(0, RECENT_COLORS_LIMIT);

    try {
        localStorage.setItem(RECENT_COLORS_KEY, JSON.stringify(next));
    } catch {
        // Ignore quota errors silently — feature is non-critical.
    }

    return next;
};
