<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhCheck } from '@phosphor-icons/vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    gettingStarted,
    requestProductionAccess,
} from '@/routes/documentation/guides';
import {
    create as identityCreate,
    show as identityShow,
} from '@/routes/identities';
import { aws as onboardingAws } from '@/routes/onboarding';

const props = defineProps<{
    hasCredentials: boolean;
    hasVerifiedIdentity: boolean;
    pendingIdentityId: number | null;
    /** True while SES is in the sandbox. Null before the AWS connection. */
    sandbox: boolean | null;
    /** The SQS queue of the instance has its URL and its ARN. */
    eventsConfigured: boolean;
    /** Only the owner can set the SQS settings of the instance. */
    canConfigureEvents: boolean;
}>();

const SES_EVENTS_DOCS = 'https://byomailer.com/docs/self-hosting/ses-events';

type StepState = 'done' | 'current' | 'todo';

interface Step {
    id: string;
    title: string;
    description: string;
    done: boolean;
    action: { label: string; href: string; external: boolean } | null;
}

/**
 * The AWS connection subscribes the SNS topic of the account to the SQS
 * queue of the instance. It fails without the queue, so the queue is first.
 */
const eventQueueStep = (): Step | null => {
    if (!props.canConfigureEvents) {
        return props.eventsConfigured
            ? null
            : {
                  id: 'events',
                  title: 'Create the SES event queue',
                  description:
                      'The AWS connection needs the SQS queue of this instance. Ask the owner of this instance to set the SQS settings.',
                  done: false,
                  action: null,
              };
    }

    return {
        id: 'events',
        title: 'Create the SES event queue',
        description:
            'SES sends the bounces and the complaints to one SQS queue. Create the queue. Then set its settings on the server and restart the container.',
        done: props.eventsConfigured,
        action: {
            label: 'Read the SES events guide',
            href: SES_EVENTS_DOCS,
            external: true,
        },
    };
};

const steps = computed<Step[]>(() => {
    const queue = eventQueueStep();
    const list: Step[] = [
        {
            id: 'aws',
            title: 'Connect your AWS account',
            description:
                'Create an IAM user with the BYOMailer policy. Then enter its access key and the SES region.',
            done: props.hasCredentials,
            action: {
                label: 'Connect AWS',
                href: onboardingAws().url,
                external: false,
            },
        },
        {
            id: 'identity',
            title: 'Verify a sending domain',
            description:
                'Add the domain that you send from. Then publish the DNS records that BYOMailer shows.',
            done: props.hasVerifiedIdentity,
            action: props.pendingIdentityId
                ? {
                      label: 'Finish the DNS setup',
                      href: identityShow({
                          emailIdentity: props.pendingIdentityId,
                      }).url,
                      external: false,
                  }
                : {
                      label: 'Add a domain',
                      href: identityCreate().url,
                      external: false,
                  },
        },
        {
            id: 'sandbox',
            title: 'Leave the SES sandbox',
            description:
                'A new SES account sends only to verified addresses. Request production access in the SES console.',
            done: props.sandbox === false,
            action: {
                label: 'Read the guide',
                href: requestProductionAccess().url,
                external: false,
            },
        },
    ];

    return queue ? [queue, ...list] : list;
});

const currentId = computed(() => steps.value.find((step) => !step.done)?.id);

const stateOf = (step: Step): StepState => {
    if (step.done) {
        return 'done';
    }

    return currentId.value === step.id ? 'current' : 'todo';
};

const visible = computed(() => steps.value.some((step) => !step.done));
const doneCount = computed(
    () => steps.value.filter((step) => step.done).length,
);
</script>

<template>
    <div
        v-if="visible"
        class="p-5"
        style="
            background-color: var(--bm-surface);
            border: 1px solid var(--bm-border);
            border-radius: var(--bm-radius-md);
            box-shadow: var(--bm-shadow-xs);
        "
        data-test="ses-setup-steps"
    >
        <div
            class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <h2
                    class="text-base font-semibold"
                    style="color: var(--bm-text-1)"
                >
                    Connect Amazon SES
                </h2>
                <p class="text-sm" style="color: var(--bm-text-2)">
                    BYOMailer sends email through your own Amazon SES account.
                    Do these steps before you send the first email.
                </p>
            </div>
            <p
                class="shrink-0 text-xs"
                style="
                    font-family: var(--bm-font-mono);
                    color: var(--bm-text-3);
                "
            >
                {{ doneCount }} of {{ steps.length }} done
            </p>
        </div>

        <ol class="space-y-3">
            <li
                v-for="(step, index) in steps"
                :key="step.id"
                :data-step="step.id"
                :data-state="stateOf(step)"
                class="flex flex-col items-start gap-3 p-4 sm:flex-row sm:items-center"
                :style="{
                    border: '1px solid var(--bm-border)',
                    borderRadius: 'var(--bm-radius)',
                    backgroundColor:
                        stateOf(step) === 'current'
                            ? 'var(--bm-info-dim)'
                            : 'transparent',
                }"
            >
                <div
                    class="flex h-8 w-8 shrink-0 items-center justify-center text-sm font-semibold"
                    :style="{
                        borderRadius: '9999px',
                        backgroundColor:
                            stateOf(step) === 'done'
                                ? 'var(--bm-success-dim)'
                                : 'var(--bm-surface-2)',
                        color:
                            stateOf(step) === 'done'
                                ? 'var(--bm-success)'
                                : 'var(--bm-text-2)',
                    }"
                >
                    <PhCheck v-if="stateOf(step) === 'done'" :size="16" />
                    <span v-else>{{ index + 1 }}</span>
                </div>
                <div class="min-w-0 grow">
                    <h3
                        class="text-sm font-semibold"
                        :style="{
                            color:
                                stateOf(step) === 'done'
                                    ? 'var(--bm-text-3)'
                                    : 'var(--bm-text-1)',
                        }"
                    >
                        {{ step.title }}
                    </h3>
                    <p
                        v-if="stateOf(step) !== 'done'"
                        class="text-sm"
                        style="color: var(--bm-text-2)"
                    >
                        {{ step.description }}
                    </p>
                </div>
                <template v-if="stateOf(step) === 'current' && step.action">
                    <Button
                        v-if="step.action.external"
                        as-child
                        size="sm"
                        variant="outline"
                        class="shrink-0"
                    >
                        <a
                            :href="step.action.href"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ step.action.label }}
                            <PhArrowSquareOut :size="14" />
                        </a>
                    </Button>
                    <Button v-else as-child size="sm" class="shrink-0">
                        <Link :href="step.action.href">{{
                            step.action.label
                        }}</Link>
                    </Button>
                </template>
            </li>
        </ol>

        <p class="mt-4 text-xs" style="color: var(--bm-text-3)">
            The
            <Link
                :href="gettingStarted().url"
                class="underline underline-offset-2"
                >getting started guide</Link
            >
            shows each step with screenshots.
        </p>
    </div>
</template>
