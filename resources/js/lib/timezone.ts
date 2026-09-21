/**
 * Everything the portal shows is in TexasRenters.com's own time, not the
 * viewer's: a client reading their statements from another state sees the same
 * dates and times the office does.
 */
export const APP_TIME_ZONE = 'America/Chicago';

/** A date with no time of day, e.g. "2026-09-21", is a calendar date already. */
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * A formatter in the company's timezone, keeping the viewer's own language.
 */
export function appDateFormatter(
    options: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormat {
    return new Intl.DateTimeFormat(undefined, {
        ...options,
        timeZone: APP_TIME_ZONE,
    });
}

/**
 * Format a value the server sent. A timestamp is moved into the company's
 * timezone; a plain date is left as written, since shifting it would land it
 * on the day before.
 */
export function formatInAppZone(
    value: string | null | undefined,
    options: Intl.DateTimeFormatOptions,
): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        ...options,
        timeZone: DATE_ONLY.test(value) ? 'UTC' : APP_TIME_ZONE,
    }).format(date);
}

const isoDay = new Intl.DateTimeFormat('en-CA', {
    timeZone: APP_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

/** The calendar day a moment falls on here, as YYYY-MM-DD. */
export function appDateString(date: Date = new Date()): string {
    return isoDay.format(date);
}

/** Whether two moments fall on the same day here. */
export function isSameAppDay(a: Date, b: Date): boolean {
    return appDateString(a) === appDateString(b);
}

/** The company's calendar day, shifted by whole days. */
export function appDayOffset(days: number): string {
    const [year, month, day] = appDateString().split('-').map(Number);
    const shifted = new Date(Date.UTC(year, month - 1, day));

    shifted.setUTCDate(shifted.getUTCDate() + days);

    return shifted.toISOString().slice(0, 10);
}

/** The hour of day here, for greetings and business-hours copy. */
export function appHour(date: Date = new Date()): number {
    return Number(
        new Intl.DateTimeFormat('en-US', {
            timeZone: APP_TIME_ZONE,
            hour: 'numeric',
            hourCycle: 'h23',
        }).format(date),
    );
}
