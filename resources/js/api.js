/**
 * The only place that talks to the server.
 *
 * Session-cookie authenticated, CSRF-token protected — the API lives in Laravel's
 * web middleware group, so this is a browser talking to its own origin, not a
 * client holding a bearer token.
 */

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/**
 * A non-2xx response. Carries the parsed body so callers can pick out Laravel's
 * `errors` bag for field-level messages instead of showing one generic failure.
 */
export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message || body?.error || `Request failed with status ${status}`);

        this.name = 'ApiError';
        this.status = status;
        this.body = body ?? {};
        this.errors = body?.errors ?? {};
    }

    /** First message for a field, for inline form errors. */
    for(field) {
        const messages = this.errors[field];

        return Array.isArray(messages) ? messages[0] : messages ?? null;
    }

    /** Every validation message, flattened, for a summary box. */
    all() {
        return Object.values(this.errors).flat();
    }

    get isValidation() {
        return this.status === 422;
    }

    get isForbidden() {
        return this.status === 403;
    }

    /**
     * A 403 the two-factor middleware raised, rather than a plain permission
     * failure. The UI sends these to the enrolment page instead of saying "no".
     */
    get needsTwoFactor() {
        return this.status === 403 && this.body.two_factor_required === true;
    }
}

async function request(method, url, { body, params, signal } = {}) {
    const target = new URL(url, window.location.origin);

    for (const [key, value] of Object.entries(params ?? {})) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((entry) => target.searchParams.append(`${key}[]`, entry));
        } else {
            target.searchParams.set(key, value);
        }
    }

    const isFormData = body instanceof FormData;

    const response = await fetch(target, {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...(body === undefined || isFormData ? {} : { 'Content-Type': 'application/json' }),
        },
        body: body === undefined ? undefined : isFormData ? body : JSON.stringify(body),
    });

    // A session that expired mid-session lands here. Reloading hands the request
    // to Laravel, which redirects to the sign-in page — the one flow that is
    // deliberately still server-rendered.
    if (response.status === 401 || response.redirected) {
        window.location.reload();

        throw new ApiError(401, { message: 'Your session expired. Reloading…' });
    }

    if (response.status === 204) {
        return null;
    }

    const text = await response.text();
    let payload = null;

    try {
        payload = text === '' ? null : JSON.parse(text);
    } catch {
        // A non-JSON body from an API route means something upstream failed —
        // a proxy error page, or PHP dying before Laravel's handler ran.
        throw new ApiError(response.status, {
            message: `The server returned ${response.status} and something that was not JSON.`,
        });
    }

    if (!response.ok) {
        throw new ApiError(response.status, payload);
    }

    return payload;
}

export const api = {
    get: (url, options) => request('GET', url, options),
    post: (url, body, options) => request('POST', url, { ...options, body }),
    put: (url, body, options) => request('PUT', url, { ...options, body }),
    delete: (url, options) => request('DELETE', url, options),
};

/** Base path for every endpoint, so a subdirectory install stays one edit away. */
export const endpoint = (path) => `/api/${path.replace(/^\/+/, '')}`;
