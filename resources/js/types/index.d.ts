import { InertiaLinkProps } from '@inertiajs/vue3';
import type { Component } from 'vue';

export interface Auth {
    user: User;
    timezone: string;
}

export interface BreadcrumbItem {
    title: string;
    href?: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: Component;
    isActive?: boolean;
    group?: string;
}

export interface NavigationItem {
    key: string;
    title: string;
    href: string;
    group?: string;
}

export interface Navigation {
    main: NavigationItem[];
}

export interface OnboardingChecklistStep {
    key: string;
    label: string;
    completed: boolean;
    href: string;
}

export interface OnboardingChecklist {
    steps: OnboardingChecklistStep[];
    completed: number;
    total: number;
}

export interface SesAccountStatus {
    enforcement_status: string | null;
    sending_enabled: boolean;
}

/** Session flash data that `HandleInertiaRequests::share()` sends on every page. */
export interface FlashProps {
    success: string | null;
    error: string | null;
    newToken: string | null;
    createdTag: { id: number; name: string } | null;
    createdList: { id: number; name: string } | null;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    appUrl: string;
    ogImage: string | null;
    auth: Auth;
    sesAccountStatus: SesAccountStatus | null;
    hasPostalAddress: boolean;
    sidebarOpen: boolean;
    navigation: Navigation;
    onboardingChecklist: OnboardingChecklist | null;
    flash: FlashProps;
    [key: string]: unknown;
};

export interface User {
    id: number;
    name: string;
    email: string;
    uuid: string;
    role: 'admin' | 'editor' | 'viewer' | 'invited';
    avatar?: string;
    email_verified_at: string | null;
    invite_expires_at: string | null;
    archived_at: string | null;
    is_owner?: boolean;
    deactivated_at?: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export type BreadcrumbItemType = BreadcrumbItem;
