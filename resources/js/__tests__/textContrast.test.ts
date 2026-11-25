/// <reference types="node" />
import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

// Vitest runs from the repository root.
const css = readFileSync('resources/css/app.css', 'utf8');

/**
 * The text tokens must reach the WCAG AA contrast of 4.5:1 on each light
 * background token, because the app uses them for small labels and hints.
 */

function token(name: string): string {
    const match = css.match(new RegExp(`--${name}:\\s*(#[0-9A-Fa-f]{6})`));
    if (!match) {
        throw new Error(`Token --${name} not found`);
    }

    return match[1];
}

function luminance(hex: string): number {
    const [r, g, b] = [1, 3, 5].map((start) => {
        const channel = parseInt(hex.slice(start, start + 2), 16) / 255;

        return channel <= 0.03928
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(foreground: string, background: string): number {
    const [light, dark] = [luminance(foreground), luminance(background)].sort(
        (a, b) => b - a,
    );

    return (light + 0.05) / (dark + 0.05);
}

describe('text color tokens', () => {
    const backgrounds = ['bm-surface', 'bm-surface-2', 'bm-base'];

    for (const text of ['bm-text-1', 'bm-text-2', 'bm-text-3', 'bm-text-4']) {
        for (const background of backgrounds) {
            it(`--${text} on --${background} reaches 4.5:1`, () => {
                expect(
                    contrast(token(text), token(background)),
                ).toBeGreaterThanOrEqual(4.5);
            });
        }
    }
});
