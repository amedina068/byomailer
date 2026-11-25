import type { Ref } from 'vue';

/**
 * Immutable write helpers for filter editor components.
 *
 * Every editor receives its filter through `defineModel` and must write
 * changes back as a new object (the parent owns the reactive array element).
 * These helpers centralise that immutable-replace idiom so each editor does
 * not re-implement array copying / nested spreads.
 *
 * The internal `as TFilter` casts widen the spread result back to the model
 * type. They are sound — we only ever replace the `values` property with a
 * value of its declared type — but TypeScript cannot prove that for a generic
 * (or union) `TFilter`, so the cast is contained here instead of leaking into
 * every call site.
 */

/** Replace a filter's object-shaped `values` wholesale. */
export function setValues<TFilter extends { values: object }>(
    model: Ref<TFilter>,
    values: TFilter['values'],
): void {
    model.value = { ...model.value, values } as TFilter;
}

/** Merge a partial into a filter's object-shaped `values`. */
export function patchValues<TFilter extends { values: object }>(
    model: Ref<TFilter>,
    partial: Partial<TFilter['values']>,
): void {
    model.value = {
        ...model.value,
        values: { ...model.value.values, ...partial },
    } as TFilter;
}

/**
 * Immutable CRUD over a filter's array-shaped `values`.
 *
 * @param model     The editor's `defineModel` ref.
 * @param makeEmpty Factory for a fresh blank entry (used by `add`, and by
 *                  `remove` when `keepOne` is set).
 * @param options   `keepOne` re-seeds a blank entry when the last row is
 *                  removed, so the editor always renders at least one row.
 */
export function useFilterValues<TValue, TFilter extends { values: TValue[] }>(
    model: Ref<TFilter>,
    makeEmpty: () => TValue,
    options: { keepOne?: boolean } = {},
) {
    const write = (values: TValue[]): void => {
        model.value = { ...model.value, values } as TFilter;
    };

    const update = (index: number, value: TValue): void => {
        const next = [...model.value.values];
        next[index] = value;
        write(next);
    };

    const add = (): void => {
        write([...model.value.values, makeEmpty()]);
    };

    const remove = (index: number): void => {
        const next = [...model.value.values];
        next.splice(index, 1);

        if (options.keepOne && next.length === 0) {
            next.push(makeEmpty());
        }

        write(next);
    };

    return { write, update, add, remove };
}
