import type { Directive } from 'vue';

/**
 * `v-reveal`: an element fades and moves into place the first time it scrolls
 * into view, for the landing page. Once shown it stays shown.
 *
 *   v-reveal                 rises from below
 *   v-reveal.left / .right   slides in from that side
 *   v-reveal.zoom            grows from slightly smaller
 *   v-reveal="150"           waits 150ms first, to stagger a list
 *
 * Visitors who ask for reduced motion, and browsers without
 * IntersectionObserver, get the content straight away, never hidden.
 */
type Modifiers = Partial<Record<'left' | 'right' | 'zoom', boolean>>;

const offset = (modifiers: Modifiers): string => {
    if (modifiers.left) {
        return 'translate3d(-2.5rem, 0, 0)';
    }

    if (modifiers.right) {
        return 'translate3d(2.5rem, 0, 0)';
    }

    if (modifiers.zoom) {
        return 'scale(0.95)';
    }

    return 'translate3d(0, 1.75rem, 0)';
};

const observers = new WeakMap<HTMLElement, IntersectionObserver>();

export const vReveal: Directive<HTMLElement, number | undefined> = {
    mounted(element, binding) {
        if (
            typeof IntersectionObserver === 'undefined' ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const show = (): void => {
            element.style.opacity = '';
            element.style.transform = '';
            // Only needed while it animates; afterwards it costs a layer each.
            element.addEventListener(
                'transitionend',
                () => {
                    element.style.willChange = '';
                },
                { once: true },
            );
        };

        // Already on screen when the page opens: leave it alone rather than
        // hiding it for a frame.
        const box = element.getBoundingClientRect();

        if (
            box.top < window.innerHeight &&
            box.bottom > 0 &&
            binding.value === undefined
        ) {
            return;
        }

        element.style.opacity = '0';
        element.style.transform = offset(binding.modifiers as Modifiers);
        element.style.transition = [
            'opacity 0.7s ease-out',
            'transform 0.8s cubic-bezier(0.16, 1, 0.3, 1)',
        ].join(', ');
        element.style.transitionDelay = `${binding.value ?? 0}ms`;
        element.style.willChange = 'opacity, transform';

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    show();
                    observer.disconnect();
                    observers.delete(element);
                }
            },
            { threshold: 0 },
        );

        observer.observe(element);
        observers.set(element, observer);
    },

    unmounted(element) {
        observers.get(element)?.disconnect();
        observers.delete(element);
    },
};
