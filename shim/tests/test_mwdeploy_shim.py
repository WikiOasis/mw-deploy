#!/usr/bin/env python3
"""Tests for mwdeploy-shim.

Run with:  python3 -m unittest discover -s shim/tests -t .
"""

from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import mwdeploy_shim as shim  # noqa: E402

SHIM = str(Path(__file__).resolve().parents[1] / "mwdeploy_shim.py")


def git(*arguments: str, cwd: str) -> None:
    subprocess.run(["git", *arguments], cwd=cwd, check=True, capture_output=True, text=True)


def run_shim(*arguments: str) -> tuple[int, dict, str]:
    """Invoke the shim as a subprocess and return (exit code, payload, stdout).

    The payload is taken from the *last* line of stdout, which is the contract
    the portal relies on.
    """
    completed = subprocess.run(
        [sys.executable, SHIM, *arguments],
        capture_output=True,
        text=True,
        env={**os.environ, "MWDEPLOY_WEB_USER": _current_user()},
    )

    lines = [line for line in completed.stdout.splitlines() if line.strip()]
    payload = json.loads(lines[-1]) if lines else {}

    return completed.returncode, payload, completed.stdout


def _current_user() -> str:
    import pwd

    return pwd.getpwuid(os.geteuid()).pw_name


class ResultContractTest(unittest.TestCase):
    """Every subcommand prints exactly one JSON object; ok=false implies exit 1."""

    def test_success_payload_omits_the_noisy_streams(self):
        payload = json.loads(shim.Result(ok=True, detail="did the thing", stdout="lots of rsync output").to_json())

        self.assertEqual({"ok": True, "detail": "did the thing"}, payload)

    def test_failure_payload_carries_stdout_and_stderr(self):
        payload = json.loads(
            shim.Result(ok=False, error="boom", stdout="out", stderr="err").to_json()
        )

        self.assertFalse(payload["ok"])
        self.assertEqual("boom", payload["error"])
        self.assertEqual("out", payload["stdout"])
        self.assertEqual("err", payload["stderr"])

    def test_extra_fields_survive(self):
        payload = json.loads(shim.Result(ok=True, detail="x", extra={"ref": "abc"}).to_json())

        self.assertEqual("abc", payload["ref"])

    def test_long_output_is_truncated_rather_than_flooding_the_deploy_log(self):
        payload = json.loads(shim.Result(ok=False, error="boom", stdout="x" * 20000).to_json())

        self.assertLess(len(payload["stdout"]), 20000)
        self.assertTrue(payload["stdout"].endswith("(truncated)"))

    def test_an_unknown_subcommand_exits_nonzero(self):
        completed = subprocess.run([sys.executable, SHIM, "not-a-verb"], capture_output=True, text=True)

        self.assertNotEqual(0, completed.returncode)


class RsyncArgvTest(unittest.TestCase):
    """rsync is the step that moves the bits; its flags are the whole behaviour."""

    def test_it_carries_the_base_flags_and_excludes(self):
        argv = shim.rsync_argv("/srv/staging/", "/srv/prod/", provision=False, paths=[])

        self.assertEqual("rsync", argv[0])
        for flag in ("--recursive", "--links", "--delete", "--delete-after"):
            self.assertIn(flag, argv)

        # .git must never reach production.
        self.assertIn(".git", argv)
        self.assertEqual(["/srv/staging/", "/srv/prod/"], argv[-2:])

    def test_provision_adds_the_first_run_flags(self):
        argv = shim.rsync_argv("/a/", "/b/", provision=True, paths=[])

        self.assertIn("--whole-file", argv)
        self.assertIn("--ignore-times", argv)

    def test_a_path_restriction_includes_every_ancestor_and_excludes_the_rest(self):
        argv = shim.rsync_argv(
            "/a/", "/b/", provision=False, paths=["versions/1.45/extensions/Echo"]
        )

        rendered = " ".join(argv)

        # Without the ancestor includes, rsync would never descend far enough to
        # reach the leaf.
        self.assertIn("--include /versions", rendered)
        self.assertIn("--include /versions/1.45", rendered)
        self.assertIn("--include /versions/1.45/extensions", rendered)
        self.assertIn("--include /versions/1.45/extensions/Echo", rendered)
        self.assertIn("--include /versions/1.45/extensions/Echo/***", rendered)

        # And the catch-all exclude must come last, or everything else syncs too.
        self.assertEqual("--exclude", argv[-4])
        self.assertEqual("*", argv[-3])

    def test_multiple_paths_are_all_included(self):
        argv = shim.rsync_argv("/a/", "/b/", provision=False, paths=["config", "extensions/Echo"])
        rendered = " ".join(argv)

        self.assertIn("--include /config/***", rendered)
        self.assertIn("--include /extensions/Echo/***", rendered)

    def test_no_paths_means_no_catch_all_exclude(self):
        argv = shim.rsync_argv("/a/", "/b/", provision=False, paths=[])

        self.assertNotEqual("*", argv[-3])

    def test_a_missing_source_fails_rather_than_syncing_nothing(self):
        code, payload, _ = run_shim("rsync-local", "--src", "/definitely/not/here/", "--dst", "/tmp/x/")

        self.assertEqual(1, code)
        self.assertFalse(payload["ok"])
        self.assertIn("does not exist", payload["error"])


class GitLineParsingTest(unittest.TestCase):
    def test_it_splits_on_the_unit_separator(self):
        line = shim.GIT_SEP.join(["master", "some subject", "A Name", "2026-01-01"])

        self.assertEqual(("master", "some subject", "A Name", "2026-01-01"), shim._split_git_line(line))

    def test_missing_trailing_fields_become_empty_strings(self):
        self.assertEqual(("master", "", "", ""), shim._split_git_line("master"))


class GitSubcommandTest(unittest.TestCase):
    """The git verbs against a real throwaway repository."""

    @classmethod
    def setUpClass(cls):
        cls._tmp = tempfile.TemporaryDirectory()
        root = Path(cls._tmp.name)

        cls.origin = str(root / "origin")
        os.makedirs(cls.origin)

        git("init", "-q", "-b", "master", ".", cwd=cls.origin)
        git("config", "user.email", "deploy@wikioasis.org", cwd=cls.origin)
        git("config", "user.name", "Deploy", cwd=cls.origin)

        (Path(cls.origin) / "a.txt").write_text("one\n")
        git("add", "-A", cwd=cls.origin)
        git("commit", "-qm", "first commit", cwd=cls.origin)

        cls.first_sha = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=cls.origin, capture_output=True, text=True, check=True
        ).stdout.strip()

        (Path(cls.origin) / "b.txt").write_text("two\n")
        git("add", "-A", cwd=cls.origin)
        git("commit", "-qm", "second commit", cwd=cls.origin)

        git("checkout", "-q", "-b", "feature", cwd=cls.origin)
        (Path(cls.origin) / "c.txt").write_text("three\n")
        git("add", "-A", cwd=cls.origin)
        git("commit", "-qm", "on feature", cwd=cls.origin)
        git("checkout", "-q", "master", cwd=cls.origin)

        cls.work = str(root / "work")
        subprocess.run(["git", "clone", "-q", cls.origin, cls.work], check=True, capture_output=True)

    @classmethod
    def tearDownClass(cls):
        cls._tmp.cleanup()

    def setUp(self):
        run_shim("git-checkout", "--path", self.work, "--ref", "master")

    def test_git_head_reports_a_branch_as_a_branch(self):
        code, payload, _ = run_shim("git-head", "--path", self.work)

        self.assertEqual(0, code)
        self.assertEqual("branch", payload["ref_type"])
        self.assertEqual("master", payload["ref"])

    def test_git_head_reports_a_detached_head_as_a_commit(self):
        run_shim("git-checkout", "--path", self.work, "--ref", self.first_sha)

        _, payload, _ = run_shim("git-head", "--path", self.work)

        self.assertEqual("commit", payload["ref_type"])
        self.assertEqual(self.first_sha, payload["ref"])

    def test_checking_out_a_branch_lands_on_the_branch_not_a_detached_head(self):
        # A rollback snapshot has to be able to say "it was on master", so a
        # branch checkout must not detach.
        code, payload, _ = run_shim("git-checkout", "--path", self.work, "--ref", "feature")

        self.assertEqual(0, code)
        self.assertEqual("branch", payload["ref_type"])
        self.assertEqual("feature", payload["ref"])

    def test_checking_out_a_commit_sha_works(self):
        code, payload, _ = run_shim("git-checkout", "--path", self.work, "--ref", self.first_sha)

        self.assertEqual(0, code)
        self.assertEqual(self.first_sha, payload["ref"])
        self.assertFalse((Path(self.work) / "b.txt").exists())

    def test_an_unknown_ref_fails_loudly(self):
        code, payload, _ = run_shim("git-checkout", "--path", self.work, "--ref", "no-such-ref")

        self.assertEqual(1, code)
        self.assertFalse(payload["ok"])
        self.assertIn("ref not found", payload["error"])

    def test_a_path_that_is_not_a_repository_fails(self):
        with tempfile.TemporaryDirectory() as empty:
            code, payload, _ = run_shim("git-checkout", "--path", empty, "--ref", "master")

        self.assertEqual(1, code)
        self.assertIn("not a git repository", payload["error"])

    def test_a_missing_path_fails(self):
        code, payload, _ = run_shim("git-head", "--path", "/definitely/not/here")

        self.assertEqual(1, code)
        self.assertIn("no such directory", payload["error"])

    def test_git_pull_resets_to_the_tracked_branch_tip(self):
        run_shim("git-checkout", "--path", self.work, "--ref", self.first_sha)

        code, payload, _ = run_shim("git-pull", "--path", self.work)

        self.assertEqual(0, code)
        self.assertTrue((Path(self.work) / "b.txt").exists())
        self.assertEqual(0, code)

    def test_git_checkout_discards_local_modifications(self):
        (Path(self.work) / "a.txt").write_text("locally scribbled\n")

        run_shim("git-checkout", "--path", self.work, "--ref", "master")

        self.assertEqual("one\n", (Path(self.work) / "a.txt").read_text())

    def test_git_refs_lists_branches_without_the_origin_head_symref(self):
        code, payload, _ = run_shim("git-refs", "--path", self.work, "--kind", "branches")

        values = [ref["value"] for ref in payload["refs"]]

        self.assertEqual(0, code)
        self.assertIn("master", values)
        self.assertIn("feature", values)
        # origin/HEAD is a symref, not a deployable branch.
        self.assertNotIn("HEAD", values)
        self.assertNotIn("origin", values)

    def test_git_refs_lists_commits_newest_first_and_honours_the_limit(self):
        code, payload, _ = run_shim("git-refs", "--path", self.work, "--kind", "commits", "--limit", "1")

        self.assertEqual(0, code)
        self.assertEqual(1, len(payload["refs"]))
        self.assertEqual("second commit", payload["refs"][0]["subject"])

    def test_git_refs_can_list_commits_on_a_named_branch(self):
        _, payload, _ = run_shim(
            "git-refs", "--path", self.work, "--kind", "commits", "--branch", "feature", "--limit", "1"
        )

        self.assertEqual("on feature", payload["refs"][0]["subject"])


class RepoRegisterTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        root = Path(self._tmp.name)

        self.origin = str(root / "origin")
        os.makedirs(self.origin)

        git("init", "-q", "-b", "master", ".", cwd=self.origin)
        git("config", "user.email", "deploy@wikioasis.org", cwd=self.origin)
        git("config", "user.name", "Deploy", cwd=self.origin)
        (Path(self.origin) / "a.txt").write_text("one\n")
        git("add", "-A", cwd=self.origin)
        git("commit", "-qm", "first", cwd=self.origin)

        self.root = root

    def tearDown(self):
        self._tmp.cleanup()

    def test_it_clones_into_a_fresh_path_and_creates_missing_parents(self):
        target = str(self.root / "staging" / "versions" / "1.45" / "extensions" / "Echo")

        code, payload, _ = run_shim("repo-register", "--url", self.origin, "--path", target)

        self.assertEqual(0, code)
        self.assertTrue(os.path.isdir(os.path.join(target, ".git")))
        self.assertEqual("branch", payload["ref_type"])

    def test_a_core_version_gets_its_extension_and_skin_scaffolding(self):
        target = str(self.root / "staging" / "versions" / "1.46")

        code, _, _ = run_shim(
            "repo-register", "--url", self.origin, "--path", target,
            "--kind", "core-version", "--version", "1.46",
        )

        self.assertEqual(0, code)
        self.assertTrue(os.path.isdir(os.path.join(target, "extensions")))
        self.assertTrue(os.path.isdir(os.path.join(target, "skins")))

    def test_core_version_without_a_version_is_rejected(self):
        target = str(self.root / "staging" / "versions" / "nope")

        code, payload, _ = run_shim(
            "repo-register", "--url", self.origin, "--path", target, "--kind", "core-version"
        )

        self.assertEqual(1, code)
        self.assertIn("--version is required", payload["error"])

    def test_re_registering_an_existing_checkout_is_idempotent(self):
        target = str(self.root / "staging" / "Echo")

        run_shim("repo-register", "--url", self.origin, "--path", target)
        code, payload, _ = run_shim("repo-register", "--url", self.origin, "--path", target)

        self.assertEqual(0, code)
        self.assertIn("already registered", payload["detail"])

    def test_it_refuses_to_clone_over_a_non_empty_directory(self):
        target = str(self.root / "occupied")
        os.makedirs(target)
        (Path(target) / "important.txt").write_text("do not clobber me\n")

        code, payload, _ = run_shim("repo-register", "--url", self.origin, "--path", target)

        self.assertEqual(1, code)
        self.assertIn("non-empty", payload["error"])
        self.assertTrue((Path(target) / "important.txt").exists())

    def test_an_unreachable_remote_fails(self):
        target = str(self.root / "staging" / "Nope")

        code, payload, _ = run_shim(
            "repo-register", "--url", str(self.root / "no-such-remote"), "--path", target
        )

        self.assertEqual(1, code)
        self.assertIn("clone", payload["error"])


class PatchApplyTest(unittest.TestCase):
    GOOD = "--- a/file.txt\n+++ b/file.txt\n@@ -1,2 +1,3 @@\n line one\n line two\n+line three\n"
    STALE = "--- a/file.txt\n+++ b/file.txt\n@@ -1,2 +1,3 @@\n nonexistent\n content here\n+line three\n"

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)

        self.target = self.root / "target"
        self.target.mkdir()
        (self.target / "file.txt").write_text("line one\nline two\n")

        self.good = self.root / "good.patch"
        self.good.write_text(self.GOOD)

        self.stale = self.root / "stale.patch"
        self.stale.write_text(self.STALE)

    def tearDown(self):
        self._tmp.cleanup()

    def test_a_dry_run_reports_success_without_touching_the_file(self):
        code, payload, _ = run_shim(
            "patch-apply", "--patch", str(self.good), "--target-dir", str(self.target), "--check"
        )

        self.assertEqual(0, code)
        self.assertTrue(payload["ok"])
        self.assertTrue(payload["checked"])
        self.assertEqual("line one\nline two\n", (self.target / "file.txt").read_text())

    def test_applying_changes_the_file(self):
        code, payload, _ = run_shim(
            "patch-apply", "--patch", str(self.good), "--target-dir", str(self.target)
        )

        self.assertEqual(0, code)
        self.assertFalse(payload["checked"])
        self.assertEqual("line one\nline two\nline three\n", (self.target / "file.txt").read_text())

    def test_a_stale_patch_is_refused_rather_than_applied_with_fuzz(self):
        # GNU patch defaults to fuzz 2, which would land this "somewhere near"
        # and exit 0. Silently patching the wrong place is worse than failing.
        code, payload, _ = run_shim(
            "patch-apply", "--patch", str(self.stale), "--target-dir", str(self.target), "--check"
        )

        self.assertEqual(1, code)
        self.assertFalse(payload["ok"])
        self.assertIn("would not apply", payload["error"])
        self.assertEqual("line one\nline two\n", (self.target / "file.txt").read_text())

    def test_a_missing_patch_file_fails(self):
        code, payload, _ = run_shim(
            "patch-apply", "--patch", str(self.root / "nope.patch"), "--target-dir", str(self.target)
        )

        self.assertEqual(1, code)
        self.assertIn("patch file not found", payload["error"])

    def test_a_missing_target_directory_fails(self):
        code, payload, _ = run_shim(
            "patch-apply", "--patch", str(self.good), "--target-dir", str(self.root / "nope")
        )

        self.assertEqual(1, code)
        self.assertIn("target directory not found", payload["error"])

    def test_git_format_check_uses_git_apply(self):
        repo = self.root / "repo"
        repo.mkdir()
        git("init", "-q", "-b", "master", ".", cwd=str(repo))
        git("config", "user.email", "d@w.org", cwd=str(repo))
        git("config", "user.name", "D", cwd=str(repo))
        (repo / "file.txt").write_text("line one\nline two\n")
        git("add", "-A", cwd=str(repo))
        git("commit", "-qm", "base", cwd=str(repo))

        code, payload, _ = run_shim(
            "patch-apply", "--patch", str(self.good), "--target-dir", str(repo),
            "--format", "git", "--check",
        )

        self.assertEqual(0, code)
        self.assertTrue(payload["ok"])


class HaproxyTest(unittest.TestCase):
    """The stats-socket conversation, against a stub socket."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.socket_path = os.path.join(self._tmp.name, "admin.sock")
        self.received: list[str] = []
        self.reply = ""

        self.server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.server.bind(self.socket_path)
        self.server.listen(4)

        self.thread = threading.Thread(target=self._serve, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.close()
        self._tmp.cleanup()

    def _serve(self):
        while True:
            try:
                connection, _ = self.server.accept()
            except OSError:
                return

            with connection:
                data = connection.recv(4096)
                self.received.append(data.decode().strip())
                connection.sendall(self.reply.encode())

    def test_depool_sends_the_maint_state_command(self):
        code, payload, _ = run_shim(
            "haproxy", "depool", "--proxy", "proxy-1", "--backend", "mediawiki",
            "--server", "mw-us-east-011", "--socket", self.socket_path,
        )

        self.assertEqual(0, code)
        self.assertEqual("set server mediawiki/mw-us-east-011 state maint", self.received[-1])
        self.assertEqual("maint", payload["state"])

    def test_repool_sends_the_ready_state_command(self):
        code, payload, _ = run_shim(
            "haproxy", "repool", "--proxy", "proxy-1", "--backend", "mediawiki",
            "--server", "mw-us-east-011", "--socket", self.socket_path,
        )

        self.assertEqual(0, code)
        self.assertEqual("set server mediawiki/mw-us-east-011 state ready", self.received[-1])
        self.assertEqual("ready", payload["state"])

    def test_a_complaint_from_haproxy_is_a_failure(self):
        # Treating "No such server" as success would leave a live box being
        # rsynced while it takes traffic.
        self.reply = "No such server.\n"

        code, payload, _ = run_shim(
            "haproxy", "depool", "--proxy", "proxy-1", "--backend", "mediawiki",
            "--server", "typo-in-hostname", "--socket", self.socket_path,
        )

        self.assertEqual(1, code)
        self.assertIn("refused", payload["error"])

    def test_a_missing_socket_is_a_failure(self):
        code, payload, _ = run_shim(
            "haproxy", "depool", "--proxy", "p", "--backend", "b", "--server", "s",
            "--socket", os.path.join(self._tmp.name, "not-there.sock"),
        )

        self.assertEqual(1, code)
        self.assertIn("socket not found", payload["error"])


class CanaryTest(unittest.TestCase):
    def test_an_unreachable_vhost_fails_after_the_configured_retries(self):
        code, payload, _ = run_shim(
            "canary", "--vhost", "canary.invalid", "--host", "127.0.0.1",
            "--retries", "2", "--backoff", "0", "--timeout", "2",
        )

        self.assertEqual(1, code)
        self.assertFalse(payload["ok"])
        self.assertIn("after 2 attempt", payload["error"])

    def test_it_never_waits_for_input(self):
        # There is no TTY under `salt cmd.run`; the retry/prompt decision belongs
        # to the portal. Closing stdin must not hang the check.
        completed = subprocess.run(
            [sys.executable, SHIM, "canary", "--vhost", "canary.invalid",
             "--retries", "1", "--backoff", "0", "--timeout", "2"],
            capture_output=True,
            text=True,
            stdin=subprocess.DEVNULL,
            timeout=30,
        )

        self.assertEqual(1, completed.returncode)


class L10nRebuildTest(unittest.TestCase):
    def test_a_missing_mediawiki_tree_fails_rather_than_reporting_success(self):
        code, payload, _ = run_shim(
            "l10n-rebuild", "--wiki", "testwiki", "--mediawiki", "/definitely/not/here"
        )

        self.assertEqual(1, code)
        self.assertFalse(payload["ok"])
        self.assertIn("l10n rebuild", payload["error"])


if __name__ == "__main__":
    unittest.main()
