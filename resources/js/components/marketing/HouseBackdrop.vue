<script setup lang="ts">
/**
 * A faint line drawing of a Houston street — two-storey homes, a ranch, a
 * townhouse row and live oaks, under a sun by day and a moon by night (it
 * follows the light and dark theme) — for behind the landing page hero.
 *
 * Drawn in currentColor so it follows the theme in light and dark mode, and
 * faded out towards the top so it reads as texture, never as a picture
 * competing with the words. Decorative only.
 */
</script>

<template>
    <svg
        viewBox="0 0 1440 320"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        preserveAspectRatio="xMidYMax slice"
        aria-hidden="true"
        focusable="false"
        class="pointer-events-none w-full"
        :style="{
            maskImage:
                'linear-gradient(to top, black 35%, transparent 100%)',
            WebkitMaskImage:
                'linear-gradient(to top, black 35%, transparent 100%)',
        }"
    >
        <!-- Sun, in light mode -->
        <g class="dark:hidden">
            <circle cx="575" cy="112" r="22" />
            <path
                d="M575 76 V66 M575 148 V158 M539 112 H529 M611 112 H621 M550 87 L543 80 M600 87 L607 80 M550 137 L543 144 M600 137 L607 144"
            />
        </g>

        <!-- Moon and a few stars, in dark mode -->
        <g class="hidden dark:inline">
            <path d="M590 92 A26 26 0 1 0 598 132 A20 20 0 1 1 590 92 Z" />
            <path d="M650 84 V94 M645 89 H655" />
            <path d="M505 70 V78 M501 74 H509" />
            <path d="M688 128 V134 M685 131 H691" />
            <path d="M470 110 V116 M467 113 H473" />
        </g>

        <!-- Street -->
        <path d="M0 300 H1440" />
        <path d="M0 312 H1440" stroke-dasharray="18 22" />

        <!-- Live oak -->
        <path d="M60 300 V250" />
        <path
            d="M60 252 C20 252 14 212 38 198 C28 170 60 152 80 168 C104 150 132 176 118 198 C140 214 124 252 88 250 Z"
        />

        <!-- Two-storey home with a gable and porch -->
        <path d="M150 300 V190 L240 130 L330 190 V300" />
        <path d="M140 197 L240 128 L340 197" />
        <path d="M150 240 H330" />
        <rect x="175" y="200" width="30" height="26" />
        <rect x="275" y="200" width="30" height="26" />
        <path d="M190 200 V226 M175 213 H205 M290 200 V226 M275 213 H305" />
        <rect x="222" y="255" width="36" height="45" />
        <path d="M200 255 H280 M200 255 V300 M280 255 V300" />
        <path d="M232 160 a8 8 0 1 0 16 0 a8 8 0 1 0 -16 0" />

        <!-- Palm -->
        <path d="M372 300 C374 260 370 230 378 200" />
        <path
            d="M378 200 C356 188 340 196 332 210 M378 200 C366 180 350 176 338 180 M378 200 C392 182 410 180 422 188 M378 200 C400 194 414 204 420 218 M378 200 C378 184 384 172 392 166"
        />

        <!-- Ranch home, long and low -->
        <path d="M440 300 V230 H700 V300" />
        <path d="M428 236 L500 190 H640 L712 236" />
        <rect x="470" y="248" width="40" height="28" />
        <rect x="630" y="248" width="40" height="28" />
        <path d="M490 248 V276 M650 248 V276" />
        <rect x="548" y="250" width="48" height="50" />
        <path d="M572 250 V300" />
        <path d="M612 190 V170 H628 V190" />

        <!-- Live oak -->
        <path d="M760 300 V246" />
        <path
            d="M760 248 C718 250 708 208 736 194 C726 164 762 146 784 164 C810 144 842 172 826 196 C850 212 832 250 792 248 Z"
        />

        <!-- Townhouse row -->
        <path d="M860 300 V170 L900 140 L940 170 L980 140 L1020 170 L1060 140 L1100 170 V300" />
        <path d="M940 170 V300 M1020 170 V300" />
        <rect x="880" y="186" width="24" height="24" />
        <rect x="960" y="186" width="24" height="24" />
        <rect x="1040" y="186" width="24" height="24" />
        <rect x="880" y="230" width="24" height="24" />
        <rect x="960" y="230" width="24" height="24" />
        <rect x="1040" y="230" width="24" height="24" />
        <rect x="910" y="262" width="20" height="38" />
        <rect x="990" y="262" width="20" height="38" />
        <rect x="1070" y="262" width="20" height="38" />

        <!-- Craftsman bungalow -->
        <path d="M1150 300 V220 H1340 V300" />
        <path d="M1136 226 L1245 160 L1354 226" />
        <path d="M1170 244 H1320" />
        <path d="M1170 244 V300 M1320 244 V300 M1220 244 V300 M1270 244 V300" />
        <rect x="1232" y="262" width="26" height="38" />
        <path d="M1225 196 H1265 V212 H1225 Z" />

        <!-- Small tree -->
        <path d="M1395 300 V262" />
        <path
            d="M1395 264 C1372 264 1366 240 1380 230 C1376 212 1398 204 1408 216 C1424 210 1434 232 1422 244 C1432 256 1418 268 1402 264"
        />
    </svg>
</template>
