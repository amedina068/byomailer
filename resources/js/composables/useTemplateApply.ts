import { ref, type Ref } from 'vue';

export interface EmailTemplateSummary {
    id: number;
    name: string;
    html_content: string;
    is_default: boolean;
}

/**
 * Minimum form shape: the bound form must expose `email_template_id`. The
 * editor's content is composed (template + slot) only at submit time by the
 * caller, so the composable does not touch any content field.
 */
export interface TemplateBindableForm {
    email_template_id: number | null;
}

interface UseTemplateApplyReturn {
    appliedTemplate: Ref<EmailTemplateSummary | null>;
    applyTemplate: (template: EmailTemplateSummary) => void;
    removeTemplate: () => void;
    handleTemplateSelect: (template: EmailTemplateSummary) => void;
}

/**
 * Light-weight template selection state for the Pattern A editor surface
 * (editor edits slot HTML; template wrapper rendered as static chrome).
 */
export function useTemplateApply(form: TemplateBindableForm): UseTemplateApplyReturn {
    const appliedTemplate = ref<EmailTemplateSummary | null>(null);

    const applyTemplate = (template: EmailTemplateSummary): void => {
        form.email_template_id = template.id;
        appliedTemplate.value = template;
    };

    const removeTemplate = (): void => {
        form.email_template_id = null;
        appliedTemplate.value = null;
    };

    const handleTemplateSelect = (template: EmailTemplateSummary): void => {
        applyTemplate(template);
    };

    return {
        appliedTemplate,
        applyTemplate,
        removeTemplate,
        handleTemplateSelect,
    };
}
