interface RelativeTimeOptions {
    emptyLabel?: string;
    withYear?: boolean;
}

export function useRelativeTime() {
    function formatRelative(value?: string | null, options: RelativeTimeOptions = {}): string {
        const { emptyLabel = 'never', withYear = false } = options;

        if (!value) {
            return emptyLabel;
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return emptyLabel;
        }

        const abs = Math.abs(Math.round((date.getTime() - Date.now()) / 60_000));
        if (abs < 1) {
            return 'just now';
        }
        if (abs < 60) {
            return `${abs}m ago`;
        }

        const hours = Math.round(abs / 60);
        if (hours < 24) {
            return `${hours}h ago`;
        }

        const days = Math.round(hours / 24);
        if (days < 30) {
            return `${days}d ago`;
        }

        return date.toLocaleDateString(undefined, withYear ? { month: 'short', day: 'numeric', year: 'numeric' } : { month: 'short', day: 'numeric' });
    }

    return { formatRelative };
}
