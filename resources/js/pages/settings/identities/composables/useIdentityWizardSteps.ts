import { ref } from 'vue';

export const STEPS = ['Select domain', 'Tracking', 'Sender', 'DNS records'] as const;
export const FINAL_STEP = STEPS.length;
export const SENDER_STEP = 3;

export function useIdentityWizardSteps() {
    const step = ref(1);

    const next = (): void => {
        if (step.value < FINAL_STEP) {
            step.value += 1;
        }
    };

    const prev = (): void => {
        if (step.value > 1 && step.value < FINAL_STEP) {
            step.value -= 1;
        }
    };

    const goTo = (n: number): void => {
        step.value = n;
    };

    return { step, next, prev, goTo };
}
