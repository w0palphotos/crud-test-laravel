<script setup>
/**
 * The revenue chart.
 *
 * Two series on two axes, because revenue in rupiah and a count of transactions
 * differ by seven orders of magnitude and would flatten each other on one scale.
 * Revenue reads on the left, the count on the right, and each axis is labelled so
 * the reader is not left inferring which is which.
 *
 * Chart.js rather than hand-rolled SVG. Smoothing a curve through twelve points
 * and building the scales, gridlines and tooltips to match is a real geometry
 * problem, and the sales-report app already depends on this exact stack.
 */
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import {
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

import { formatCompact, formatRupiah } from '../shared/rupiah.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip, Legend);

const props = defineProps({
    /** @type {{period: string, label: string, revenue: string, salesCount: number}[]} */
    monthly: { type: Array, required: true },
});

const data = computed(() => ({
    labels: props.monthly.map((row) => row.label),
    datasets: [
        {
            label: 'Pendapatan',
            data: props.monthly.map((row) => Number(row.revenue)),
            borderColor: '#dd5600',
            backgroundColor: 'rgba(221, 86, 0, 0.08)',
            yAxisID: 'y',
            // The template's treatment: a smoothed curve with a marker on every
            // point, so a reader can put a finger on the month they care about
            // without hovering.
            tension: 0.4,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: '#dd5600',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 1.5,
            borderWidth: 2,
            fill: true,
        },
        {
            label: 'Transaksi',
            data: props.monthly.map((row) => row.salesCount),
            borderColor: '#3c8dbc',
            backgroundColor: 'rgba(60, 140, 188, 0.08)',
            yAxisID: 'y1',
            tension: 0.4,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: '#3c8dbc',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 1.5,
            borderWidth: 2,
            fill: true,
        },
    ],
}));

const options = {
    responsive: true,
    // Without this the canvas sizes itself from an aspect ratio and the panel
    // ends up taller or shorter than the KPI grid beside it.
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: {
            position: 'top',
            align: 'end',
            labels: {
                boxWidth: 10,
                boxHeight: 10,
                usePointStyle: true,
                pointStyle: 'circle',
                color: '#6b7280',
                font: { size: 11 },
            },
        },
        tooltip: {
            callbacks: {
                label: (item) => item.dataset.label === 'Pendapatan'
                    ? ` Pendapatan: Rp ${formatRupiah(item.raw)}`
                    : ` Transaksi: ${item.raw}`,
            },
        },
    },
    scales: {
        y: {
            position: 'left',
            beginAtZero: true,
            border: { display: false },
            grid: { color: 'rgba(0, 0, 0, 0.06)' },
            ticks: {
                color: '#9ca3af',
                font: { size: 11 },
                callback: (value) => `Rp ${formatCompact(value)}`,
            },
        },
        y1: {
            position: 'right',
            beginAtZero: true,
            border: { display: false },
            grid: { display: false },
            ticks: {
                color: '#9ca3af',
                font: { size: 11 },
                precision: 0,
            },
        },
        x: {
            border: { display: false },
            grid: { display: false },
            ticks: { color: '#9ca3af', font: { size: 11 } },
        },
    },
};
</script>

<template>
    <Line :data="data" :options="options" />
</template>