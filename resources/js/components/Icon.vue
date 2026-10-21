<script setup>
/**
 * The shell's icon set, as inline SVG paths.
 *
 * Written out rather than pulled from an icon package. Twenty icons is a
 * rounding error on a bundle, an icon package is a dependency that outlives the
 * design decision that needed it, and stroke-based paths inherit `currentColor`,
 * so an icon recolours itself from the colour of whatever it sits in — which is
 * how the same glyph works on a dark rail and a white tile.
 *
 * Every path is drawn on a 24x24 grid with a 1.75 stroke, so icons line up with
 * each other without per-icon nudging.
 */
const props = defineProps({
    name: { type: String, required: true },
});

const PATHS = {
    dashboard: 'M3.5 3.5h7v7h-7zM13.5 3.5h7v7h-7zM3.5 13.5h7v7h-7zM13.5 13.5h7v7h-7z',
    receipt: 'M6 2.5h12v19l-2.4-1.6-2.4 1.6-2.4-1.6-2.4 1.6L6 21.5zM9 7h6M9 11h6M9 15h4',
    box: 'M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5zM3.5 7.5 12 12m0 0 8.5-4.5M12 12v9',
    users: 'M15.5 20.5v-1.5a4 4 0 00-4-4h-5a4 4 0 00-4 4v1.5M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM22 20.5v-1.5a4 4 0 00-3-3.87M16 4.13a4 4 0 010 7.75',
    truck: 'M2.5 6.5h11v10h-11zM13.5 10h4l3 3v3.5h-7zM7 20.5a2 2 0 100-4 2 2 0 000 4zM17.5 20.5a2 2 0 100-4 2 2 0 000 4z',
    user: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4.5 20.5a7.5 7.5 0 0115 0',
    'user-plus': 'M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 10a2 2 0 112-2h2a2 2 0 112 2M12 20a2 2 0 100-4 2 2 0 000 4z',
    'sign-out': 'M15 8V5.5H5.5v13H15V16M10 12h11M17.5 8.5 21 12l-3.5 3.5',
    menu: 'M4 7h16M4 12h16M4 17h16',
    search: 'M11 18a7 7 0 100-14 7 7 0 000 14zM20 20l-4-4',
    bell: 'M18 16.5v-5a6 6 0 10-12 0v5l-2 3h16zM10 22a2.5 2.5 0 004 0',
    chat: 'M4 4.5h16v11H9l-5 4.5z',
    'chevron-down': 'M6 9.5l6 6 6-6',
    'chevron-right': 'M9.5 6l6 6-6 6',
    'chevron-left': 'M14.5 6l-6 6 6 6',
    'chevron-up': 'M6 14.5l6-6 6 6',
    calendar: 'M4 6.5h16v13H4zM4 10.5h16M8.5 3.5v4M15.5 3.5v4',
    plus: 'M12 5v14M5 12h14',
    wallet: 'M3.5 7.5h17v12h-17zM3.5 7.5 15 3.5v4M16 13.5h1.5',
    'trending-up': 'M3 17l6-6 4 4 8-8M15 7h6v6',
    clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3.5 2',
    'check-circle': 'M12 21a9 9 0 100-18 9 9 0 000 18zM8 12.5l2.5 2.5L16 9.5',
    // A star rather than a filled badge, to match the rest of the set: this one
    // has to read at 16px next to three arrow buttons and say "make this the main
    // picture", which is a preference being offered rather than a state already
    // held.
    star: 'M12 3.5l2.7 5.5 6 .9-4.35 4.24 1.03 6-5.38-2.83-5.38 2.83 1.03-6L3.3 9.9l6-.9z',
    alert: 'M12 3 2.5 20.5h19zM12 9.5v5M12 17.5v.5',
    chart: 'M4 4v16h16M8 15l3.5-4 3 2.5L20 7',
    globe: 'M12 21a9 9 0 100-18 9 9 0 000 18zM3.5 9.5h17M3.5 14.5h17M12 3a14 14 0 010 18 14 14 0 010-18',
    tag: 'M3.5 11.5v-7h7l9 9-7 7zM7.5 8h.01',
    close: 'M6.5 6.5l11 11M17.5 6.5l-11 11',
    refresh: 'M20 11.5A8 8 0 006.3 6.3L4 8.5M4 4v4.5h4.5M4 12.5A8 8 0 0017.7 17.7L20 15.5M20 20v-4.5h-4.5',
};

const path = PATHS[props.name] ?? PATHS.dashboard;
</script>

<template>
    <!--
        aria-hidden because every icon here sits beside or inside a label. An
        icon that is not decorative has to be given an accessible name by hand,
        and a screen reader announcing "dashboard" twice is worse than one that
        stays quiet.
    -->
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path :d="path" />
    </svg>
</template>