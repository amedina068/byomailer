import type { Field } from '@/components/documentation/FieldList.vue';

/** The confirm_live argument of a tool that previews a delete first. */
export const previewArgument: Field = {
    name: 'confirm_live',
    type: 'boolean',
    description:
        'Leave it out to get a preview. Set it to true only after the user confirms the preview.',
};

/** The applied field of a tool that previews a delete first. */
export const appliedField: Field = {
    name: 'applied',
    type: 'boolean',
    description: 'false for a preview. true when the delete is saved.',
};
