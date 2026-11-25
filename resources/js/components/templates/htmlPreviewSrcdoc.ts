import { defaultsToCss, inlineElementDefaults } from '@/components/tiptap/elementDefaults';

const RESET_CSS = `
html, body { margin: 0; background: #fff; }
body { padding: 16px; font-family: Arial, Helvetica, sans-serif; font-size: 16px; color: #1f2937; line-height: 1.5; }
img { max-width: 100%; height: auto; }
* { box-sizing: border-box; }
`;

export const buildPreviewSrcdoc = (html: string | null | undefined): string => {
    const body = inlineElementDefaults(html ?? '');
    const defaultsCss = defaultsToCss('body');

    return `<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>${RESET_CSS}${defaultsCss}</style></head><body>${body}</body></html>`;
};
