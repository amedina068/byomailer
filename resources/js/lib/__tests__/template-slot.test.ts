import { describe, expect, it } from 'vitest';

import {
    extractSlot,
    hydrateTemplate,
    splitTemplateAtSlot,
} from '../template-slot';

describe('splitTemplateAtSlot', () => {
    it('splits at the {{ content }} placeholder', () => {
        const out = splitTemplateAtSlot(
            '<div>top</div>{{ content }}<div>bot</div>',
        );
        expect(out.before).toBe('<div>top</div>');
        expect(out.after).toBe('<div>bot</div>');
        expect(out.hasSlot).toBe(true);
    });

    it('tolerates whitespace around the placeholder', () => {
        const out = splitTemplateAtSlot(
            '<p>top</p>  {{   content   }}  <p>bot</p>',
        );
        expect(out.before).toBe('<p>top</p>  ');
        expect(out.after).toBe('  <p>bot</p>');
        expect(out.hasSlot).toBe(true);
    });

    it('returns the full template before / empty after when no placeholder', () => {
        const out = splitTemplateAtSlot('<p>plain template</p>');
        expect(out.before).toBe('<p>plain template</p>');
        expect(out.after).toBe('');
        expect(out.hasSlot).toBe(false);
    });

    it('only splits on the first placeholder', () => {
        const out = splitTemplateAtSlot('a{{ content }}b{{ content }}c');
        expect(out.before).toBe('a');
        expect(out.after).toBe('b{{ content }}c');
    });

    it('handles empty input', () => {
        const out = splitTemplateAtSlot('');
        expect(out.before).toBe('');
        expect(out.after).toBe('');
        expect(out.hasSlot).toBe(false);
    });
});

describe('replacement patterns in slot content', () => {
    const slots = [
        '<p>A figure, $70 saved.</p>',
        '<p>Unlimited is <strong>$37/year</strong>.</p>',
        '<p>Literal \\1 and ${1} text.</p>',
        "<p>Literal $& and $$ and $` and $' text.</p>",
    ];

    it.each(slots)('hydrateTemplate keeps %s literal', (slot) => {
        expect(hydrateTemplate('<p>{{ content }}</p>', slot)).toBe(
            `<p><div data-slot="content">${slot}</div></p>`,
        );
    });

    it.each(slots)('extractSlot keeps %s literal', (slot) => {
        const out = extractSlot(
            `<header>H</header><div data-slot="content">${slot}</div>`,
        );
        expect(out.slotContent).toBe(slot);
        expect(out.composedHtml).toBe(`<header>H</header>${slot}`);
    });
});
