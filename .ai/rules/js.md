---
paths:
    - 'resources/js/**/*.vue'
---

# Js

## Build all pages with shadcn-vue components and the shared preset theme

Every page must be built from shadcn-vue components in `@/components/ui` (preset `a326wSQq`: style `reka-nova`, base color `neutral`, font Inter, `lucide` icons). Before writing custom markup, check whether a shadcn-vue component exists; if it isn't installed yet, add it with `npx shadcn-vue@latest add <component>` instead of hand-rolling one.
Use only the theme tokens defined in `resources/css/app.css` (`bg-background`, `text-foreground`, `bg-primary`, `text-muted-foreground`, `border-border`, `bg-card`, etc.). Do not hardcode colors (`bg-gray-*`, hex, rgb) or add per-page theme overrides, so every page looks the same in light and dark mode.
The theme comes from shadcn-vue preset `a326wSQq` with emerald `primary`/`ring`/`sidebar-primary`/`sidebar-ring` in `app.css`; change the theme only there, never in individual pages.
