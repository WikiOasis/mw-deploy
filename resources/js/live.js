import { onUnmounted, ref } from 'vue';

import { api, endpoint } from './api';

/**
 * Live deployment state — the direct analogue of the curses refresh loop.
 *
 * Echo is the primary feed; the poll is a deliberate fallback, because a deploy is
 * exactly the wrong time to discover the websocket server is down. Once broadcasts
 * start arriving the poll interval stretches rather than stopping, so a mid-deploy
 * websocket drop still converges instead of leaving a stale screen that looks
 * fine.
 */
const FAST_POLL_MS = 3000;
const SLOW_POLL_MS = 15000;

export function useDeploymentState(deploymentId) {
    const state = ref(null);
    const live = ref(false);
    const finished = ref(false);

    let timer = null;
    let channel = null;
    let stopped = false;

    const apply = (payload) => {
        state.value = payload;
        finished.value = payload?.terminal === true;

        if (finished.value) {
            stop();
        }
    };

    const refresh = async () => {
        try {
            apply(await api.get(endpoint(`deployments/${deploymentId}/state`)));
        } catch {
            // Keep the last good picture on screen and try again on the next tick:
            // a blank step list mid-rollout would read as "nothing is happening".
        }
    };

    const schedule = () => {
        window.clearTimeout(timer);

        if (stopped || finished.value) {
            return;
        }

        timer = window.setTimeout(async () => {
            await refresh();
            schedule();
        }, live.value ? SLOW_POLL_MS : FAST_POLL_MS);
    };

    const subscribe = () => {
        if (!window.Echo) {
            return;
        }

        channel = window.Echo.private(`deployments.${deploymentId}`);

        channel
            .listen('.deployment.progressed', () => {
                live.value = true;
                // Refresh rather than patching from the event: the broadcast says
                // *that* something changed, and one authoritative read is simpler
                // to reason about than merging partial payloads.
                refresh();
            })
            .listen('.deployment.step.progressed', () => {
                live.value = true;
                refresh();
            });
    };

    function stop() {
        stopped = true;
        window.clearTimeout(timer);

        if (channel && window.Echo) {
            window.Echo.leave(`deployments.${deploymentId}`);
            channel = null;
        }
    }

    const start = async () => {
        stopped = false;
        await refresh();

        if (finished.value) {
            return;
        }

        subscribe();
        schedule();
    };

    onUnmounted(stop);

    return { state, live, finished, start, stop, refresh };
}

/**
 * Poll a list endpoint while anything on it is still in flight. Used by the
 * dashboard, which wants "is the fleet quiet?" rather than per-step detail.
 */
export function usePolling(loader, { interval = 5000 } = {}) {
    let timer = null;
    let stopped = false;

    const tick = async () => {
        const keepGoing = await loader();

        if (stopped || keepGoing === false) {
            return;
        }

        timer = window.setTimeout(tick, interval);
    };

    const start = () => {
        stopped = false;
        tick();
    };

    const stop = () => {
        stopped = true;
        window.clearTimeout(timer);
    };

    onUnmounted(stop);

    return { start, stop };
}
