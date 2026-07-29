/**
 * The small amount of behaviour the server-rendered auth pages need.
 *
 * Kept out of the SPA bundle entirely. Sign-in, the TOTP challenge and enrolment
 * are the flows you need on the day the application bundle is what broke, so they
 * are plain HTML with a few kilobytes of vanilla JavaScript — and every one of them
 * still works with this file absent.
 */
import { wireThemeSwitch } from './theme';

/**
 * Two-panel toggles, used by the TOTP challenge to swap an authenticator code for
 * a recovery code.
 *
 *   <button data-toggle="recovery">…</button>
 *   <div data-panel="recovery" hidden>…</div>
 *   <div data-panel-default="recovery">…</div>
 */
function wireToggles() {
    document.querySelectorAll('[data-toggle]').forEach((button) => {
        const name = button.dataset.toggle;

        button.addEventListener('click', () => {
            const shown = document.querySelectorAll(`[data-panel="${name}"]`);
            const hidden = document.querySelectorAll(`[data-panel-default="${name}"]`);
            const showing = [...shown].some((element) => !element.hidden);

            shown.forEach((element) => {
                element.hidden = showing;
                // Required-ness moves with visibility, or the hidden field blocks
                // submission of the one being filled in.
                element.querySelectorAll('input').forEach((input) => {
                    input.required = !showing;
                });
            });

            hidden.forEach((element) => {
                element.hidden = !showing;
                element.querySelectorAll('input').forEach((input) => {
                    input.required = showing;
                });
            });

            const focus = showing ? hidden[0] : shown[0];

            focus?.querySelector('input')?.focus();
        });
    });
}

/**
 * The enrolment QR code. Fortify serves the SVG and the secret from their own
 * endpoints rather than rendering them into the page, so they are fetched here.
 */
async function loadTwoFactorQrCode() {
    const container = document.querySelector('[data-qr-code]');

    if (container === null) {
        return;
    }

    const fetchJson = async (url) => {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        return response.ok ? response.json() : null;
    };

    const [qr, secret] = await Promise.all([
        fetchJson(container.dataset.qrCode),
        fetchJson(container.dataset.secretKey),
    ]);

    if (qr?.svg) {
        container.querySelector('[data-qr-target]').innerHTML = qr.svg;
    }

    const secretTarget = container.querySelector('[data-secret-target]');

    if (secretTarget) {
        secretTarget.textContent = secret?.secretKey ?? 'unavailable — reload the page';
    }
}

wireToggles();
wireThemeSwitch();
loadTwoFactorQrCode();
