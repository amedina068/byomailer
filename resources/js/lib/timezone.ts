type DateTimeParts = {
    year: string;
    month: string;
    day: string;
    hour: string;
    minute: string;
    second: string;
};

const getDateTimeParts = (
    date: Date,
    timeZone: string,
): DateTimeParts | null => {
    try {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        });
        const parts: Partial<DateTimeParts> = {};

        for (const part of formatter.formatToParts(date)) {
            if (part.type === 'literal') {
                continue;
            }

            parts[part.type as keyof DateTimeParts] = part.value;
        }

        if (
            !parts.year ||
            !parts.month ||
            !parts.day ||
            !parts.hour ||
            !parts.minute ||
            !parts.second
        ) {
            return null;
        }

        return parts as DateTimeParts;
    } catch (error) {
        return null;
    }
};

const pad = (value: number): string => String(value).padStart(2, '0');

const getOffsetMilliseconds = (date: Date, timeZone: string): number => {
    const parts = getDateTimeParts(date, timeZone);

    if (!parts) {
        return 0;
    }

    const target = Date.UTC(
        Number(parts.year),
        Number(parts.month) - 1,
        Number(parts.day),
        Number(parts.hour),
        Number(parts.minute),
        Number(parts.second),
    );

    return target - date.getTime();
};

export const formatUtcDateTimeForInput = (
    value: string,
    timeZone: string,
): string => {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const parts = getDateTimeParts(date, timeZone);

    if (!parts) {
        return '';
    }

    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
};

export const formatTimezoneLabel = (timeZone: string): string => {
    const now = new Date();
    const offsetMinutes = getOffsetMilliseconds(now, timeZone) / 60000;
    const absoluteMinutes = Math.abs(Math.round(offsetMinutes));
    const hours = Math.floor(absoluteMinutes / 60);
    const minutes = absoluteMinutes % 60;
    const sign = offsetMinutes >= 0 ? '+' : '-';
    const formattedOffset = `${sign}${pad(hours)}:${pad(minutes)}`;

    return `UTC${formattedOffset} ${timeZone}`;
};
