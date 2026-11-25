import { computed, toValue, type MaybeRefOrGetter } from 'vue';

type SesStatusInput =
    | {
          enforcement_status: string | null;
          sending_enabled: boolean | null;
      }
    | null
    | undefined;

/**
 * SES account state from the last quota fetch. Paused means that SES blocks sends.
 */
export function useSesAccountStatus(status: MaybeRefOrGetter<SesStatusInput>) {
    const isPaused = computed(() => {
        const value = toValue(status);

        return (
            value?.enforcement_status === 'SHUTDOWN' ||
            value?.sending_enabled === false
        );
    });
    const isUnderReview = computed(
        () =>
            !isPaused.value &&
            toValue(status)?.enforcement_status === 'PROBATION',
    );

    return { isPaused, isUnderReview };
}
