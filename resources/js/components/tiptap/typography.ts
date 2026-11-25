import { Color, FontFamily, FontSize, TextStyle } from '@tiptap/extension-text-style';

export const FONT_SIZES = {
    xs: 12,
    s: 14,
    m: 16,
    l: 18,
    xl: 24,
    '2xl': 32,
} as const;

export type FontSizeKey = keyof typeof FONT_SIZES;

export const FONT_FAMILIES: Array<{ label: string; value: string | null }> = [
    { label: 'Default', value: null },
    { label: 'System UI', value: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' },
    { label: 'Arial', value: 'Arial, Helvetica, sans-serif' },
    { label: 'Helvetica', value: 'Helvetica, Arial, sans-serif' },
    { label: 'Georgia', value: 'Georgia, "Times New Roman", serif' },
    { label: 'Times New Roman', value: '"Times New Roman", Times, serif' },
    { label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
    { label: 'Tahoma', value: 'Tahoma, Geneva, sans-serif' },
    { label: 'Courier New', value: '"Courier New", Courier, monospace' },
    { label: 'JetBrains Mono', value: '"JetBrains Mono", ui-monospace, monospace' },
];

export const HEX_COLOR_PATTERN = /^#[0-9A-Fa-f]{6}$/;

export const isValidHexColor = (value: string): boolean => HEX_COLOR_PATTERN.test(value.trim());

export const normalizeHexColor = (value: string): string | null => {
    const trimmed = value.trim();
    const withHash = trimmed.startsWith('#') ? trimmed : `#${trimmed}`;
    return isValidHexColor(withHash) ? withHash.toLowerCase() : null;
};

export const TYPOGRAPHY_EXTENSIONS = [TextStyle, FontFamily, FontSize, Color];
