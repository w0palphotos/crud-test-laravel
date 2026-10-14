/**
 * Rupiah formatting, kept identical to the reporting app so the same figure
 * reads the same way in both projects.
 *
 * Amounts here are Indonesian rupiah and reach into the hundreds of millions,
 * so the dashboard shows a compacted form next to the exact figure: "Rp 87,9 jt"
 * tells a reader more at a glance than "Rp 87.857.142,86", and neither replaces
 * the other.
 */

/**
 * The exact amount, grouped for the Indonesian locale. Whole rupiah drops the
 * decimals; anything with a fractional part keeps two, because rounding a
 * sen-level amount to a bare integer would hide the very precision that made it
 * worth showing.
 */
export function formatRupiah(value) {
    const number = Number(value) || 0;

    const options = Number.isInteger(number)
        ? { maximumFractionDigits: 0 }
        : { minimumFractionDigits: 2, maximumFractionDigits: 2 };

    return number.toLocaleString('id-ID', options);
}

/**
 * The amount compacted to one decimal and the Indonesian magnitude suffix:
 * rb (ribu, thousands), jt (juta, millions), M (miliar, billions).
 */
export function formatCompact(value) {
    const number = Number(value) || 0;

    if (number >= 1_000_000_000) {
        return `${trimZero((number / 1_000_000_000).toFixed(1))} M`;
    }

    if (number >= 1_000_000) {
        return `${trimZero((number / 1_000_000).toFixed(1))} jt`;
    }

    if (number >= 1_000) {
        return `${trimZero((number / 1_000).toFixed(1))} rb`;
    }

    return String(Math.round(number));
}

function trimZero(text) {
    return text.replace(/\.0$/, '');
}