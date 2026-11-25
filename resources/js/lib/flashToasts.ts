import type { FlashProps } from '@/types';

/** The fields of a finished Inertia visit that decide if its flash is fresh. */
export interface FinishedVisit {
    cancelled: boolean;
    interrupted: boolean;
    only: string[];
    except: string[];
}

export interface FlashToast {
    variant: 'success' | 'error';
    message: string;
}

/**
 * Did the server send a new `flash` prop for this visit? A cancelled or
 * interrupted visit and a partial reload without `flash` keep the old value,
 * and a toast from that old value repeats a message the user already saw.
 */
export function visitRefreshedFlash(visit: FinishedVisit): boolean {
    if (visit.cancelled || visit.interrupted) {
        return false;
    }

    if (visit.only.length > 0 && !visit.only.includes('flash')) {
        return false;
    }

    return !visit.except.includes('flash');
}

/** The toasts to show for the shared `flash` prop after a visit. */
export function flashToasts(
    visit: FinishedVisit,
    flash: Partial<FlashProps> | null | undefined,
): FlashToast[] {
    if (!flash || !visitRefreshedFlash(visit)) {
        return [];
    }

    const toasts: FlashToast[] = [];

    if (flash.success) {
        toasts.push({ variant: 'success', message: flash.success });
    }

    if (flash.error) {
        toasts.push({ variant: 'error', message: flash.error });
    }

    return toasts;
}
