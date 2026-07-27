/**
 * The live deployment dashboard — the direct analogue of the curses refresh
 * loop.
 *
 * Echo is the primary feed; the poll is a deliberate fallback, because a deploy
 * is exactly the wrong time to discover the websocket server is down. Once a
 * broadcast arrives the poll interval stretches out rather than stopping, so a
 * mid-deploy websocket drop still converges.
 */
export function deploymentMonitor({ deploymentId, stateUrl, terminal }) {
    return {
        deploymentId,
        stateUrl,
        status: null,
        awaitingDecision: false,
        pendingDecision: null,
        pendingContext: {},
        failureReason: null,
        steps: {},
        live: false,
        finished: Boolean(terminal),
        pollTimer: null,

        init() {
            this.refresh();

            if (this.finished) {
                return;
            }

            this.subscribe();
            this.schedulePoll();

            // Keep elapsed timers ticking between updates.
            setInterval(() => this.$nextTick(), 1000);
        },

        subscribe() {
            if (!window.Echo) {
                return;
            }

            window.Echo.private(`deployments.${this.deploymentId}`)
                .listen('.deployment.progressed', (event) => {
                    this.live = true;
                    this.applyDeployment(event);
                })
                .listen('.deployment.step.progressed', (event) => {
                    this.live = true;
                    this.applyStep(event);
                });
        },

        schedulePoll() {
            clearTimeout(this.pollTimer);

            if (this.finished) {
                return;
            }

            // 3s while we have no websocket, 15s once broadcasts are arriving.
            this.pollTimer = setTimeout(() => this.refresh(), this.live ? 15000 : 3000);
        },

        async refresh() {
            try {
                const response = await fetch(this.stateUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (response.ok) {
                    const state = await response.json();

                    this.applyDeployment({
                        status: state.status,
                        awaiting_decision: state.awaiting_decision,
                        pending_decision: state.pending_decision,
                        pending_decision_context: state.pending_decision_context,
                        failure_reason: state.failure_reason,
                    });

                    (state.steps ?? []).forEach((step) => this.applyStep(step));
                }
            } catch (error) {
                // Network blips are expected mid-deploy; the next tick retries.
            }

            this.schedulePoll();
        },

        applyDeployment(event) {
            this.status = event.status ?? this.status;
            this.awaitingDecision = Boolean(event.awaiting_decision);
            this.pendingDecision = event.pending_decision ?? null;
            this.pendingContext = event.pending_decision_context ?? {};
            this.failureReason = event.failure_reason ?? null;

            if (['done', 'failed', 'aborted'].includes(this.status)) {
                this.finished = true;
                clearTimeout(this.pollTimer);

                // A finished deployment's final state is worth a full reload so
                // the server-rendered summary (refs, snapshots, rollback link)
                // matches what the steps now say.
                setTimeout(() => window.location.reload(), 1500);
            }
        },

        applyStep(step) {
            this.steps[step.id] = { ...(this.steps[step.id] ?? {}), ...step };
        },

        stepsForHost(host) {
            return Object.values(this.steps)
                .filter((step) => step.host === host)
                .sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0) || a.id - b.id);
        },

        hostProgress(host) {
            const steps = this.stepsForHost(host);
            const done = steps.filter((step) => ['done', 'skipped', 'rolled_back'].includes(step.status)).length;

            return { done, total: steps.length, percent: steps.length ? Math.round((done / steps.length) * 100) : 0 };
        },

        hostStatus(host) {
            const steps = this.stepsForHost(host);

            if (steps.some((step) => step.status === 'failed')) return 'failed';
            if (steps.some((step) => step.status === 'running')) return 'running';
            if (steps.length && steps.every((step) => ['done', 'rolled_back'].includes(step.status))) return 'done';
            if (steps.length && steps.every((step) => step.status === 'skipped')) return 'skipped';

            return 'pending';
        },
    };
}
