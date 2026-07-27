/**
 * Wizard state for the deploy and undeploy forms.
 *
 * The unit of selection is a *checkout* (one repository in one core version), not
 * a repository. "All versions" is therefore a bulk toggle over a repository's
 * checkouts rather than a special mode, and each checkout keeps its own ref — so
 * 1.45 can deploy REL1_45 while 1.46 deploys REL1_46 in one submission.
 *
 * Unselected checkouts keep their inputs disabled rather than removing them from
 * the DOM, so nothing about a deselected one reaches the server.
 */
export function deploymentWizard({
    refsUrlTemplate,
    resolvedRefs,
    checkoutsByRepository,
    canTargetProduction,
    undeploy = false,
}) {
    return {
        undeploy,
        selected: {},
        commits: {},
        loading: {},
        stagingOnly: !canTargetProduction,
        rollout: false,
        allServers: true,

        /** Bulk "apply this ref to everything selected" field. */
        bulkRef: '',

        isSelected(checkoutId) {
            return Boolean(this.selected[checkoutId]);
        },

        toggle(checkoutId) {
            if (this.selected[checkoutId]) {
                delete this.selected[checkoutId];

                return;
            }

            this.selected[checkoutId] = {
                refType: 'branch',
                // Each checkout's own pin, so a bulk selection does the right
                // thing per version without the operator retyping anything.
                refValue: resolvedRefs[checkoutId] ?? '',
            };
        },

        /** Select or clear every checkout of one repository — "all versions". */
        toggleRepository(repositoryId) {
            const ids = checkoutsByRepository[repositoryId] ?? [];
            const anyMissing = ids.some((id) => !this.selected[id]);

            ids.forEach((id) => {
                if (anyMissing && !this.selected[id]) {
                    this.toggle(id);
                } else if (!anyMissing) {
                    delete this.selected[id];
                }
            });
        },

        repositoryState(repositoryId) {
            const ids = checkoutsByRepository[repositoryId] ?? [];
            const chosen = ids.filter((id) => this.selected[id]).length;

            if (chosen === 0) return 'none';

            return chosen === ids.length ? 'all' : 'some';
        },

        selectedCountFor(repositoryId) {
            return (checkoutsByRepository[repositoryId] ?? []).filter((id) => this.selected[id]).length;
        },

        refType(checkoutId) {
            return this.selected[checkoutId]?.refType ?? 'branch';
        },

        setRefType(checkoutId, type) {
            if (!this.selected[checkoutId]) {
                return;
            }

            this.selected[checkoutId].refType = type;

            if (type === 'commit') {
                this.selected[checkoutId].refValue = '';
                this.loadCommits(checkoutId);
            } else {
                this.selected[checkoutId].refValue = resolvedRefs[checkoutId] ?? '';
            }
        },

        /** Apply one ref to every currently selected checkout. */
        applyRefToSelection(ref) {
            const value = (ref ?? '').trim();

            if (value === '') {
                return;
            }

            Object.keys(this.selected).forEach((id) => {
                this.selected[id].refValue = value;
            });
        },

        /** Put every selected checkout back on its own pinned ref. */
        resetToPins() {
            Object.keys(this.selected).forEach((id) => {
                this.selected[id].refValue = resolvedRefs[id] ?? '';
                this.selected[id].refType = 'branch';
            });
        },

        async loadCommits(checkoutId, branch = null) {
            if (this.commits[checkoutId] && !branch) {
                return;
            }

            this.loading[checkoutId] = true;

            try {
                const url = new URL(refsUrlTemplate.replace('__ID__', checkoutId), window.location.origin);

                if (branch) {
                    url.searchParams.set('branch', branch);
                }

                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                this.commits[checkoutId] = response.ok ? (await response.json()).commits ?? [] : [];
            } catch (error) {
                this.commits[checkoutId] = [];
            } finally {
                this.loading[checkoutId] = false;
            }
        },

        selectedCount() {
            return Object.keys(this.selected).length;
        },

        canSubmit() {
            if (this.selectedCount() === 0) {
                return false;
            }

            // An undeploy collects no refs, so there is nothing else to check.
            return (
                this.undeploy ||
                Object.values(this.selected).every((entry) => (entry.refValue ?? '').trim().length > 0)
            );
        },
    };
}
