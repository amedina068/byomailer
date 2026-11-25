/**
 * Whether an input's current value equals the (non-empty) global attribute
 * default for a variable. Pure value comparison — provenance is deliberately
 * not tracked, so a manually typed identical value also matches.
 */
export function matchesGlobalDefault(
    globalDefaults: Record<string, string> | undefined,
    variable: string,
    currentValue: string | undefined,
): boolean {
    const globalValue = globalDefaults?.[variable];

    return (
        globalValue !== undefined &&
        globalValue !== '' &&
        currentValue === globalValue
    );
}
