/**
 * Date presets shared by list filters ("Last 30 days", "This quarter", a custom
 * range).
 *
 * Every boundary is a day on TexasRenters.com's calendar, so "last month" means
 * the same thing wherever the client reads it from.
 */
import { appDateString, appDayOffset } from '@/lib/timezone';

export { appDateString as toDateString };

/** A day as its parts, on the company's calendar. */
function parts(date: string = appDateString()): [number, number, number] {
    const [year, month, day] = date.split('-').map(Number);

    return [year, month, day];
}

function day(year: number, month: number, date: number): string {
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${year}-${pad(month)}-${pad(date)}`;
}

/** The first and last day of a month, however the month over- or underflows. */
function month(
    year: number,
    monthNumber: number,
): { from: string; to: string } {
    const shifted = new Date(Date.UTC(year, monthNumber - 1, 1));
    const start = new Date(
        Date.UTC(shifted.getUTCFullYear(), shifted.getUTCMonth(), 1),
    );
    const end = new Date(
        Date.UTC(shifted.getUTCFullYear(), shifted.getUTCMonth() + 1, 0),
    );

    return {
        from: start.toISOString().slice(0, 10),
        to: end.toISOString().slice(0, 10),
    };
}

/** A span of whole months, counted from the month `offset` away from this one. */
function months(offset: number, count = 1): { from: string; to: string } {
    const [year, monthNumber] = parts();

    return {
        from: month(year, monthNumber + offset).from,
        to: month(year, monthNumber + offset + count - 1).to,
    };
}

/** The week containing a day, Monday to Sunday. */
function week(offset: number): { from: string; to: string } {
    const [year, monthNumber, date] = parts();
    const today = new Date(Date.UTC(year, monthNumber - 1, date));
    // getUTCDay() is 0 on Sunday, which belongs to the week that started Monday.
    const weekday = (today.getUTCDay() + 6) % 7;
    const monday = new Date(today);

    monday.setUTCDate(monday.getUTCDate() - weekday + offset * 7);

    const sunday = new Date(monday);
    sunday.setUTCDate(sunday.getUTCDate() + 6);

    return {
        from: monday.toISOString().slice(0, 10),
        to: sunday.toISOString().slice(0, 10),
    };
}

/** A span of whole quarters, counted from the quarter `offset` away from this one. */
function quarters(offset: number, count = 1): { from: string; to: string } {
    const [year, monthNumber] = parts();
    const firstMonth = Math.floor((monthNumber - 1) / 3) * 3 + 1;

    return {
        from: month(year, firstMonth + offset * 3).from,
        to: month(year, firstMonth + (offset + count - 1) * 3 + 2).to,
    };
}

function year(offset: number): { from: string; to: string } {
    const [thisYear] = parts();

    return {
        from: day(thisYear + offset, 1, 1),
        to: day(thisYear + offset, 12, 31),
    };
}

/** What a filter offers, grouped as people think about time. */
export const PERIOD_PRESETS: {
    label: string;
    options: { value: string; label: string }[];
}[] = [
    {
        label: 'Days',
        options: [
            { value: 'today', label: 'Today' },
            { value: 'yesterday', label: 'Yesterday' },
            { value: '7', label: 'Last 7 days' },
            { value: '30', label: 'Last 30 days' },
            { value: '90', label: 'Last 90 days' },
        ],
    },
    {
        label: 'Weeks',
        options: [
            { value: 'this-week', label: 'This week' },
            { value: 'last-week', label: 'Last week' },
            { value: 'next-week', label: 'Next week' },
        ],
    },
    {
        label: 'Months',
        options: [
            { value: 'this-month', label: 'This month' },
            { value: 'last-month', label: 'Last month' },
            { value: 'next-month', label: 'Next month' },
            { value: 'last-3-months', label: 'Last 3 months' },
            { value: 'last-6-months', label: 'Last 6 months' },
            { value: '365', label: 'Last 12 months' },
        ],
    },
    {
        label: 'Quarters',
        options: [
            { value: 'this-quarter', label: 'This quarter' },
            { value: 'last-quarter', label: 'Last quarter' },
            { value: 'next-quarter', label: 'Next quarter' },
            { value: 'last-4-quarters', label: 'Last 4 quarters' },
        ],
    },
    {
        label: 'Years',
        options: [
            { value: 'year', label: 'This year' },
            { value: 'last-year', label: 'Last year' },
            { value: 'next-year', label: 'Next year' },
        ],
    },
    {
        label: 'Custom',
        options: [{ value: 'custom', label: 'Pick dates' }],
    },
];

/** Every preset a filter may be given, for validating what comes back. */
export const PERIOD_VALUES = PERIOD_PRESETS.flatMap((group) =>
    group.options.map((option) => option.value),
);

/**
 * Turn a date preset into the from/to dates the server filters by.
 */
export function presetDates(
    range: string,
    customFrom: string,
    customTo: string,
): { from?: string; to?: string } {
    switch (range) {
        case '7':
        case '30':
        case '90':
        case '365':
            return { from: appDayOffset(-Number(range)) };
        case 'today':
            return { from: appDateString(), to: appDateString() };
        case 'yesterday':
            return { from: appDayOffset(-1), to: appDayOffset(-1) };
        case 'this-week':
            return week(0);
        case 'last-week':
            return week(-1);
        case 'next-week':
            return week(1);
        case 'this-month':
            return months(0);
        case 'last-month':
            return months(-1);
        case 'next-month':
            return months(1);
        case 'last-3-months':
            return months(-2, 3);
        case 'last-6-months':
            return months(-5, 6);
        case 'this-quarter':
            return quarters(0);
        case 'last-quarter':
            return quarters(-1);
        case 'next-quarter':
            return quarters(1);
        case 'last-4-quarters':
            return quarters(-3, 4);
        case 'year':
            return { from: `${appDateString().slice(0, 4)}-01-01` };
        case 'last-year':
            return year(-1);
        case 'next-year':
            return year(1);
        case 'custom':
            return {
                from: customFrom || undefined,
                to: customTo || undefined,
            };
        default:
            return {};
    }
}
