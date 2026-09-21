import { formatInAppZone } from '@/lib/timezone';

export type UseFormattedDateReturn = {
    formatDate: (value: string | null) => string;
};

/**
 * Formats API timestamps in the viewer's own language, on TexasRenters.com's clock.
 */
export function useFormattedDate(): UseFormattedDateReturn {
    const formatDate = (value: string | null): string =>
        formatInAppZone(value, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

    return { formatDate };
}
