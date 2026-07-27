/**
 * Display helpers shared across the screens.
 *
 * Everything here is presentation only — durations, relative times, ref
 * shortening. Anything that decides what a value *means* belongs on the server,
 * where the deploy logic already lives.
 */

/** "4m 12s", the shape you want when watching a deploy run. */
export function duration(seconds) {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const total = Math.max(0, Math.round(seconds));

    if (total < 60) {
        return `${total}s`;
    }

    const minutes = Math.floor(total / 60);
    const rest = total % 60;

    if (minutes < 60) {
        return rest === 0 ? `${minutes}m` : `${minutes}m ${rest}s`;
    }

    const hours = Math.floor(minutes / 60);

    return `${hours}h ${minutes % 60}m`;
}

export function dateTime(iso) {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** "3 minutes ago", falling back to the absolute date past a week. */
export function relative(iso) {
    if (!iso) {
        return '—';
    }

    const then = new Date(iso).getTime();
    const seconds = Math.round((Date.now() - then) / 1000);

    if (seconds < 45) {
        return 'just now';
    }

    const units = [
        ['minute', 60],
        ['hour', 3600],
        ['day', 86400],
    ];

    for (const [unit, size] of units) {
        const next = size * (unit === 'minute' ? 60 : unit === 'hour' ? 24 : 7);

        if (seconds < next) {
            const value = Math.round(seconds / size);

            return `${value} ${unit}${value === 1 ? '' : 's'} ago`;
        }
    }

    return dateTime(iso);
}

/** A 40-character SHA is noise in a table; ten characters is enough to match on. */
export function shortRef(value) {
    if (!value) {
        return '—';
    }

    return /^[0-9a-f]{20,40}$/i.test(value) ? value.slice(0, 10) : value;
}

export function pluralise(count, singular, plural = null) {
    return `${count} ${count === 1 ? singular : (plural ?? `${singular}s`)}`;
}
