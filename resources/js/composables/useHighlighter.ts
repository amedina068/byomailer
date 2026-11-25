import type { Highlighter } from 'shiki';
import { ref, onMounted } from 'vue';

let highlighterPromise: Promise<Highlighter> | null = null;

function getHighlighter(): Promise<Highlighter> {
    if (!highlighterPromise) {
        highlighterPromise = import('shiki').then((shiki) =>
            shiki.createHighlighter({
                themes: ['github-dark'],
                langs: ['bash', 'json', 'php', 'javascript', 'python', 'ruby'],
            }),
        );
    }
    return highlighterPromise;
}

export function useHighlighter() {
    const ready = ref(false);
    let instance: Highlighter | null = null;

    onMounted(async () => {
        instance = await getHighlighter();
        ready.value = true;
    });

    const highlight = (code: string, lang: string): string => {
        if (!instance) {
            return code;
        }
        return instance.codeToHtml(code, {
            lang,
            theme: 'github-dark',
        });
    };

    return { ready, highlight };
}
