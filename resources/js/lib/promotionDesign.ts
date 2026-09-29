import {
    Bell,
    CalendarDays,
    Gift,
    Heart,
    House,
    KeyRound,
    Megaphone,
    PartyPopper,
    Percent,
    Sparkles,
    Star,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * The looks staff can give a promotion popup. The server stores only the
 * names (Promotion::THEMES and ::ICONS); these map them onto the theme's own
 * colour tokens, so every choice works in light and dark mode.
 */
export type PromotionTheme =
    | 'blue'
    | 'green'
    | 'violet'
    | 'ocean'
    | 'red'
    | 'charcoal';

export type PromotionIcon =
    | 'gift'
    | 'megaphone'
    | 'sparkles'
    | 'party-popper'
    | 'house'
    | 'key-round'
    | 'calendar'
    | 'percent'
    | 'heart'
    | 'star'
    | 'bell'
    | 'wrench';

export const promotionThemes: Record<
    PromotionTheme,
    { label: string; header: string; text: string; button: string }
> = {
    blue: {
        label: 'Brand blue',
        header: 'from-primary to-ring',
        text: 'text-primary-foreground',
        button: 'bg-primary text-primary-foreground hover:bg-primary/90',
    },
    green: {
        label: 'Green',
        header: 'from-category-leasing-deep to-category-leasing',
        text: 'text-category-foreground',
        button: 'bg-category-leasing-deep text-category-foreground hover:bg-category-leasing-deep/90',
    },
    violet: {
        label: 'Violet',
        header: 'from-category-accounting-deep to-category-accounting',
        text: 'text-category-foreground',
        button: 'bg-category-accounting-deep text-category-foreground hover:bg-category-accounting-deep/90',
    },
    ocean: {
        label: 'Ocean',
        header: 'from-category-maintenance-deep to-category-maintenance',
        text: 'text-category-foreground',
        button: 'bg-category-maintenance-deep text-category-foreground hover:bg-category-maintenance-deep/90',
    },
    red: {
        label: 'Red',
        header: 'from-destructive to-destructive/90',
        text: 'text-category-foreground',
        button: 'bg-destructive text-category-foreground hover:bg-destructive/90',
    },
    charcoal: {
        label: 'Charcoal',
        header: 'from-scrim to-scrim/90',
        text: 'text-scrim-foreground',
        button: 'bg-scrim text-scrim-foreground hover:bg-scrim/90',
    },
};

export const promotionIcons: Record<
    PromotionIcon,
    { label: string; component: Component }
> = {
    gift: { label: 'Gift', component: Gift },
    megaphone: { label: 'Announcement', component: Megaphone },
    sparkles: { label: 'Something new', component: Sparkles },
    'party-popper': { label: 'Celebration', component: PartyPopper },
    house: { label: 'Home', component: House },
    'key-round': { label: 'Keys', component: KeyRound },
    calendar: { label: 'Date', component: CalendarDays },
    percent: { label: 'Discount', component: Percent },
    heart: { label: 'Thank you', component: Heart },
    star: { label: 'Star', component: Star },
    bell: { label: 'Reminder', component: Bell },
    wrench: { label: 'Maintenance', component: Wrench },
};

/** The theme to draw, falling back to the brand blue for anything unknown. */
export function themeFor(name: string | null | undefined) {
    return promotionThemes[name as PromotionTheme] ?? promotionThemes.blue;
}

/** The icon to draw, falling back to the gift for anything unknown. */
export function iconFor(name: string | null | undefined): Component {
    return (promotionIcons[name as PromotionIcon] ?? promotionIcons.gift).component;
}
