<script setup>
/**
 * One control for a date range: a button that opens a calendar.
 *
 * Two date inputs side by side were the first shape of this filter, and they read
 * as two filters rather than one — nothing on screen said the two belonged
 * together, and there was no way to see the range you had chosen without reading
 * both boxes and doing the comparison yourself. Here the choice is made where it
 * is visible: click the first day, click the last, and the calendar draws the span
 * while the button names it.
 *
 * reka-ui supplies the calendar itself, the same way it supplies MultiSelect's
 * pills: month grid, range highlighting, keyboard navigation and the calendar
 * semantics a screen reader needs. It ships no CSS, so this wears the same Tailwind
 * classes as the inputs around it.
 *
 * The value crosses this boundary as Y-m-d strings, never as CalendarDate objects.
 * Reka works in CalendarDate and the server speaks `from`/`to`, so the conversion
 * happens in one place — here — and the page is left holding plain values it can
 * put in a query string, in a form, or in JSON without a serialisation step that
 * would surprise whoever reads it next.
 */
import { computed, ref } from 'vue';
import {
    DateRangePickerCalendar,
    DateRangePickerCell,
    DateRangePickerCellTrigger,
    DateRangePickerContent,
    DateRangePickerGrid,
    DateRangePickerGridBody,
    DateRangePickerGridHead,
    DateRangePickerGridRow,
    DateRangePickerHeadCell,
    DateRangePickerHeading,
    DateRangePickerRoot,
    DateRangePickerTrigger,
} from 'reka-ui';
import { DateFormatter, getLocalTimeZone, parseDate, today } from '@internationalized/date';

import Icon from './Icon.vue';

const props = defineProps({
    /** `{ start, end }` as Y-m-d strings; either end, or both, may be null. */
    modelValue: { type: Object, required: true },
    /** Shown while neither end is chosen. */
    placeholder: { type: String, default: 'Semua tanggal' },
    /** Forwarded to the button so a <label for> can point at this control. */
    triggerId: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const open = ref(false);

const hasValue = computed(() => Boolean(props.modelValue?.start || props.modelValue?.end));

/**
 * The month the grid is showing, as its first day.
 *
 * Separate from the range on purpose: moving through the calendar must not change
 * what is selected, and reka models that as `placeholder`. The arrows, the month
 * and year selects and a day click all move this; none of them is the value.
 *
 * reka keeps its own copy and hands changes over through `update:placeholder`, so
 * this ref is the source of truth only at the start — it opens on the month the
 * range in force begins in, so reopening the picker with a range already chosen
 * shows that range rather than the current month.
 */
const viewing = ref(
    props.modelValue?.start
        ? parseDate(props.modelValue.start).set({ day: 1 })
        : today(getLocalTimeZone()).set({ day: 1 }),
);

/**
 * Which grid the panel is showing.
 *
 * The caption in the middle walks down through them and a choice walks back up,
 * which is how a month two years away is reached without stepping the arrows
 * twenty-four times. The arrows change what they step by to match the level, so
 * one gesture means the same thing at every depth.
 */
const level = ref('day');

/**
 * Move the shown date by one step of whatever the current level steps by.
 *
 * Day one is set first, and that is not decoration. Reka's dates clamp rather than
 * overflow, so stepping a month from the 31st of January lands on the 28th of
 * February — from there the calendar would open on a month whose end had been
 * quietly pulled in, and every later step would drift further from the month the
 * person asked for.
 */
function step(direction) {
    const by = { day: { months: 1 }, month: { years: 1 }, year: { years: 10 }, decade: { years: 100 } }[level.value];

    viewing.value = viewing.value.set({ day: 1 }).add(direction === 'next' ? by : negate(by));
}

function negate(duration) {
    return Object.fromEntries(Object.entries(duration).map(([unit, amount]) => [unit, -amount]));
}

/**
 * The caption, which is also the control that walks down a level.
 */
function drill() {
    level.value = { day: 'month', month: 'year', year: 'decade', decade: 'day' }[level.value];
}

const monthYearNames = new DateFormatter('id-ID', { month: 'long', year: 'numeric' });
const monthNames = new DateFormatter('id-ID', { month: 'long' });

/**
 * The twelve months, named.
 *
 * Built through the same formatter as everything else rather than written out as
 * an array of Indonesian words: one source for how a month is spelled, which is
 * also why the day labels on the button come from here and not from a map.
 */
const months = computed(() => Array.from({ length: 12 }, (_, index) => {
    const first = viewing.value.set({ day: 1, month: index + 1 });

    return {
        value: index + 1,
        label: monthNames.format(first.toDate(getLocalTimeZone())),
    };
}));

/** The decade the shown year falls in, as its first year. */
const decadeStart = computed(() => Math.floor(viewing.value.year / 10) * 10);

/** The century the shown year falls in, as its first year. */
const centuryStart = computed(() => Math.floor(viewing.value.year / 100) * 100);

/**
 * The ten years of the shown decade, and the ten decades of the shown century.
 *
 * Ten rather than the twelve a three-column grid would fill, because the caption
 * says which ten it is and a cell that is not in it would make that a lie.
 */
const years = computed(() => Array.from({ length: 10 }, (_, index) => decadeStart.value + index));
const decades = computed(() => Array.from({ length: 10 }, (_, index) => centuryStart.value + index * 10));

const caption = computed(() => {
    if (level.value === 'day') {
        return monthYearNames.format(viewing.value.toDate(getLocalTimeZone()));
    }

    if (level.value === 'month') {
        return String(viewing.value.year);
    }

    if (level.value === 'year') {
        return `${decadeStart.value} – ${decadeStart.value + 9}`;
    }

    return `${centuryStart.value} – ${centuryStart.value + 99}`;
});

/**
 * What one step of the arrows is, named for the arrows' labels.
 *
 * The visible header says nothing about it, so the only place the unit is stated
 * is the assistive text — which means it has to change with the level, or a screen
 * reader would hear "next month" on a grid of decades.
 */
const unitName = computed(() => ({
    day: 'Bulan',
    month: 'Tahun',
    year: 'Dekade',
    decade: 'Abad',
})[level.value]);

/**
 * A choice, which walks back up one level so the next thing shown is the one the
 * choice was making: a year lands on its months, a month on its days.
 */
function chooseMonth(month) {
    viewing.value = viewing.value.set({ day: 1, month });
    level.value = 'day';
}

function chooseYear(year) {
    viewing.value = viewing.value.set({ day: 1, year });
    level.value = 'month';
}

function chooseDecade(decade) {
    viewing.value = viewing.value.set({ day: 1, year: decade });
    level.value = 'year';
}

/**
 * The range in reka's own type.
 *
 * Read-only, and deliberately not the thing reka writes back into. A computed with
 * a setter bound as `v-model` puts the value and its writer in the same place, and
 * the failure that hides there is silent: the calendar keeps its own copy, a click
 * shows the range on screen, and nothing at all reaches the page. One direction at
 * a time is the shape that cannot fail that way.
 *
 * A single day goes down as a start with no end, even though the page holds it with
 * both ends alike. Reka treats a range whose ends are set as finished — its next
 * click starts a new range rather than extending the one on screen — so handing it
 * a day with an end would leave that day the only thing anyone could ever select.
 */
const calendarValue = computed(() => {
    const start = props.modelValue?.start ?? null;
    const end = props.modelValue?.end ?? null;

    if (start !== null && start === end) {
        return { start: parseDate(start), end: undefined };
    }

    return {
        start: start ? parseDate(start) : undefined,
        end: end ? parseDate(end) : undefined,
    };
});

/**
 * Reka's answer, on its way up, as Y-m-d strings.
 *
 * One click means that day, not "since that day": both ends arrive set to it, so
 * the filter is a single day and the button names one date rather than a range with
 * an open side. The calendar is still holding it as an unfinished range, which is
 * what lets a second click extend it into one — the difference between the two is
 * this line and nowhere else.
 */
function onCalendarChange(next) {
    const start = next?.start ? next.start.toString() : null;
    const end = next?.end ? next.end.toString() : null;

    emit('update:modelValue', { start, end: end ?? start });
}

/**
 * Both ends cleared, straight up.
 *
 * Not routed through the calendar: clearing is a statement about the filter, not a
 * selection in it, and sending it through reka would mean asking the calendar to
 * come back with an empty range and hoping it agrees.
 */
function clear() {
    emit('update:modelValue', { start: null, end: null });
}

/**
 * The range named in words, because "1 Mar 2026 – 31 Mar 2026" is the answer to
 * the question the filter is asking and two dates in boxes only implies it.
 *
 * All four states are spelled out rather than left to the calendar to convey: a
 * range open at one side is a real thing to ask for, and showing it as a range with
 * a blank half would leave the person guessing whether the blank meant "unbounded"
 * or "not chosen yet".
 */
const formatter = new DateFormatter('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });

/**
 * A day, named. The `toDate()` is the whole reason this is a function.
 *
 * DateFormatter delegates to Intl.DateTimeFormat, which takes a Date or a
 * timestamp and nothing else. Handed a CalendarDate — which is what the component
 * holds — it throws `RangeError: Invalid time value`, from inside the render. The
 * failure that produces is misleading: reka renders its own cells, so the calendar
 * goes on highlighting the range you click while the button stays frozen on
 * whatever it said before and the popover stays open, because the update that
 * would have closed it never completed.
 *
 * The timezone goes to `toDate()` rather than to the formatter on purpose: local
 * midnight is the day the person clicked, and formatting that same instant in a
 * different zone could name the day before it.
 */
const format = (value) => formatter.format(parseDate(value).toDate(getLocalTimeZone()));

const label = computed(() => {
    const start = props.modelValue?.start ? format(props.modelValue.start) : null;
    const end = props.modelValue?.end ? format(props.modelValue.end) : null;

    // One day, said as one day. Reachable two ways: a single click, which the
    // calendar reports as an unfinished range and this component closes into a day,
    // or a URL that happens to carry the same date twice.
    if (start && end && start === end) {
        return start;
    }

    if (start && end) {
        return `${start} – ${end}`;
    }

    if (start) {
        return `Sejak ${start}`;
    }

    if (end) {
        return `Sampai ${end}`;
    }

    return props.placeholder;
});
</script>

<template>
    <DateRangePickerRoot
        :model-value="calendarValue"
        v-model:open="open"
        v-model:placeholder="viewing"
        locale="id-ID"
        :week-starts-on="1"
        weekday-format="short"
        :number-of-months="1"
        :close-on-select="true"
        @update:modelValue="onCalendarChange"
    >
        <!--
            as-child so the trigger is this button, whose classes the rest of the
            toolbar already uses — rather than a wrapper whose styling would have to
            be kept in step with them.

            The width is set here rather than by the caller: reka's root is
            renderless, so a class on the component itself has no element to land
            on, and a fixed width is what the label needs to stay on one line —
            "1 Mar 2026 – 31 Mar 2026" is the longest it gets before the calendar
            takes over the job of showing the range.
        -->
        <DateRangePickerTrigger as-child>
            <button
                :id="triggerId || undefined"
                type="button"
                class="flex w-56 items-center gap-2 rounded border bg-admin-surface px-3 py-2 text-sm focus:border-admin-primary focus:outline-none"
                :class="open ? 'border-admin-primary' : 'border-neutral-300'"
            >
                <Icon name="calendar" class="h-4 w-4 shrink-0 text-neutral-400" />

                <span class="flex-1 text-left" :class="hasValue ? 'text-neutral-800' : 'text-neutral-400'">
                    {{ label }}
                </span>

                <Icon name="chevron-down" class="h-4 w-4 shrink-0 text-neutral-400" />
            </button>
        </DateRangePickerTrigger>

        <!--
            Pinned below, with no collision flipping, unlike the tag pillbox
            beside it.

            That is a decision about where this control lives rather than a
            preference: the picker sits in the filter bar near the top of the page,
            so there is always room beneath it, and scrolling only ever moves the
            bar closer to the top. The flip was therefore never doing the job it
            exists for — it opened the panel upwards over the page heading, which
            is both worse to read and detached from the control it belongs to.
        -->
        <DateRangePickerContent
            side="bottom"
            align="start"
            :side-offset="4"
            class="z-20 rounded border border-neutral-200 bg-white p-3 shadow-lg"
        >
            <!--
                One caption in the middle that is also a control, rather than a pair
                of selects beside a label. Pressing it walks down through months,
                years and decades; choosing walks back up to the level the choice was
                making. That is how a month two years away is reached without stepping
                the arrows twenty-four times, and it keeps the header to one row in a
                panel barely wider than the grid it holds.

                The arrows step whatever the current level steps by — a month, a
                year, a decade, a century — so the gesture means the same thing at
                every depth.
            -->
            <div class="flex items-center gap-1.5">
                <button
                    type="button"
                    class="shrink-0 rounded p-1 text-neutral-500 hover:bg-neutral-100"
                    @click="step('prev')"
                >
                    <Icon name="chevron-left" class="h-4 w-4" />
                    <span class="sr-only">{{ unitName }} sebelumnya</span>
                </button>

                <button
                    type="button"
                    class="min-w-0 flex-1 rounded px-2 py-1 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                    title="Pilih bulan dan tahun"
                    @click="drill"
                >
                    {{ caption }}
                </button>

                <button
                    type="button"
                    class="shrink-0 rounded p-1 text-neutral-500 hover:bg-neutral-100"
                    @click="step('next')"
                >
                    <Icon name="chevron-right" class="h-4 w-4" />
                    <span class="sr-only">{{ unitName }} berikutnya</span>
                </button>
            </div>

            <!--
                Kept for the grid's accessible name, and hidden from sight: the
                caption above says the same thing in the place where it can also be
                changed.
            -->
            <DateRangePickerHeading class="sr-only" />

            <DateRangePickerCalendar v-if="level === 'day'" v-slot="{ weekDays, grid }" class="mt-2">
                <DateRangePickerGrid v-for="month in grid" :key="month.value.toString()">
                    <DateRangePickerGridHead>
                        <DateRangePickerGridRow>
                            <DateRangePickerHeadCell
                                v-for="day in weekDays"
                                :key="day"
                                class="h-8 w-8 text-xs font-normal text-neutral-400"
                            >
                                {{ day }}
                            </DateRangePickerHeadCell>
                        </DateRangePickerGridRow>
                    </DateRangePickerGridHead>

                    <DateRangePickerGridBody>
                        <DateRangePickerGridRow v-for="(week, index) in month.rows" :key="index">
                            <DateRangePickerCell v-for="day in week" :key="day.toString()" :date="day">
                                <!--
                                    The whole range is styled from reka's own data
                                    attributes: `data-selected` for the days inside
                                    it, `data-selection-start`/`-end` for the two
                                    ends, `data-highlighted` for the span the cursor
                                    is previewing before the second click. Reading
                                    those rather than tracking a hover state here is
                                    what keeps the preview and the result identical.
                                -->
                                <DateRangePickerCellTrigger
                                    :day="day"
                                    :month="month.value"
                                    class="flex h-8 w-8 items-center justify-center rounded text-sm text-neutral-700 hover:bg-neutral-100 data-[outside-view]:text-neutral-300 data-[disabled]:text-neutral-300 data-[unavailable]:line-through data-[today]:font-semibold data-[highlighted]:bg-admin-primary/10 data-[selected]:bg-admin-primary/10 data-[selection-start]:bg-admin-primary data-[selection-start]:text-white data-[selection-end]:bg-admin-primary data-[selection-end]:text-white"
                                />
                            </DateRangePickerCell>
                        </DateRangePickerGridRow>
                    </DateRangePickerGridBody>
                </DateRangePickerGrid>
            </DateRangePickerCalendar>

            <!--
                The three levels behind the day grid. Each highlights the month, year
                or decade being shown rather than anything selected: nothing here
                changes the filter, and marking a selection would suggest otherwise.
            -->
            <div v-else-if="level === 'month'" class="mt-2 grid grid-cols-3 gap-1">
                <button
                    v-for="month in months"
                    :key="month.value"
                    type="button"
                    class="rounded px-2 py-2 text-sm"
                    :class="month.value === viewing.month
                        ? 'bg-admin-primary text-white'
                        : 'text-neutral-700 hover:bg-neutral-100'"
                    @click="chooseMonth(month.value)"
                >
                    {{ month.label }}
                </button>
            </div>

            <div v-else-if="level === 'year'" class="mt-2 grid grid-cols-2 gap-1">
                <button
                    v-for="year in years"
                    :key="year"
                    type="button"
                    class="rounded px-2 py-2 text-sm"
                    :class="year === viewing.year
                        ? 'bg-admin-primary text-white'
                        : 'text-neutral-700 hover:bg-neutral-100'"
                    @click="chooseYear(year)"
                >
                    {{ year }}
                </button>
            </div>

            <div v-else class="mt-2 grid grid-cols-2 gap-1">
                <button
                    v-for="decade in decades"
                    :key="decade"
                    type="button"
                    class="rounded px-2 py-2 text-sm"
                    :class="decade === decadeStart
                        ? 'bg-admin-primary text-white'
                        : 'text-neutral-700 hover:bg-neutral-100'"
                    @click="chooseDecade(decade)"
                >
                    {{ decade }}–{{ decade + 9 }}
                </button>
            </div>

            <div class="mt-2 flex justify-end border-t border-neutral-100 pt-2">
                <button
                    type="button"
                    class="rounded px-2 py-1 text-sm text-neutral-500 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!hasValue"
                    @click="clear"
                >
                    Bersihkan
                </button>
            </div>
        </DateRangePickerContent>
    </DateRangePickerRoot>
</template>
