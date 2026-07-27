/**
 * Wizard state for the new-deployment form (section 4.2).
 *
 * Unselected repositories keep their inputs disabled rather than removing them
 * from the DOM, so nothing about a deselected repo reaches the server.
 */
export function deploymentWizard({ refsUrlTemplate, defaultBranches, canTargetProduction }) {
    return {
        selected: {},
        commits: {},
        loading: {},
        stagingOnly: !canTargetProduction,
        rollout: false,
        allServers: true,
        servers: [],

        isSelected(repositoryId) {
            return Boolean(this.selected[repositoryId]);
        },

        toggle(repositoryId) {
            if (this.selected[repositoryId]) {
                delete this.selected[repositoryId];

                return;
            }

            this.selected[repositoryId] = {
                refType: 'branch',
                refValue: defaultBranches[repositoryId] ?? 'master',
            };
        },

        refType(repositoryId) {
            return this.selected[repositoryId]?.refType ?? 'branch';
        },

        setRefType(repositoryId, type) {
            if (!this.selected[repositoryId]) {
                return;
            }

            this.selected[repositoryId].refType = type;

            if (type === 'commit') {
                this.selected[repositoryId].refValue = '';
                this.loadCommits(repositoryId);
            } else {
                this.selected[repositoryId].refValue = defaultBranches[repositoryId] ?? 'master';
            }
        },

        async loadCommits(repositoryId, branch = null) {
            if (this.commits[repositoryId] && !branch) {
                return;
            }

            this.loading[repositoryId] = true;

            try {
                const url = new URL(refsUrlTemplate.replace('__ID__', repositoryId), window.location.origin);

                if (branch) {
                    url.searchParams.set('branch', branch);
                }

                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                this.commits[repositoryId] = response.ok ? (await response.json()).commits ?? [] : [];
            } catch (error) {
                this.commits[repositoryId] = [];
            } finally {
                this.loading[repositoryId] = false;
            }
        },

        selectedCount() {
            return Object.keys(this.selected).length;
        },

        canSubmit() {
            return (
                this.selectedCount() > 0 &&
                Object.values(this.selected).every((entry) => (entry.refValue ?? '').trim().length > 0)
            );
        },
    };
}
