#!/usr/bin/env python3
"""Tests for mwdeploy-shim.

Run with:  python3 -m unittest discover -s shim/tests -t .
"""

from __future__ import annotations

import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
import unittest.mock
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

        # .git must reach production and the appservers now, so `git
        # status`/Special:Version reflect the commit actually deployed rather
        # than whatever was on disk when that host's copy was first cloned.
        self.assertNotIn(".git", argv)
        self.assertIn(".gitignore", argv)
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

    def test_git_fetch_updates_remote_tracking_refs_without_touching_head(self):
        git("checkout", "-q", "-b", "brand-new", cwd=self.origin)
        (Path(self.origin) / "d.txt").write_text("four\n")
        git("add", "-A", cwd=self.origin)
        git("commit", "-qm", "brand new branch", cwd=self.origin)
        git("checkout", "-q", "master", cwd=self.origin)

        code, payload, _ = run_shim("git-fetch", "--path", self.work)
        self.assertEqual(0, code)
        self.assertTrue(payload["ok"])

        _, refs, _ = run_shim("git-refs", "--path", self.work, "--kind", "branches")
        self.assertIn("brand-new", [ref["value"] for ref in refs["refs"]])

        # git-fetch must not move HEAD, unlike git-pull.
        _, head, _ = run_shim("git-head", "--path", self.work)
        self.assertEqual("master", head["ref"])

    def test_git_resolve_returns_the_full_forty_character_sha(self):
        code, payload, _ = run_shim("git-resolve", "--path", self.work, "--ref", "master")

        self.assertEqual(0, code)
        self.assertEqual(40, len(payload["sha"]))
        self.assertRegex(payload["sha"], r"^[0-9a-f]{40}$")

    def test_git_resolve_resolves_an_abbreviated_sha_to_the_full_one(self):
        code, payload, _ = run_shim("git-resolve", "--path", self.work, "--ref", self.first_sha[:10])

        self.assertEqual(0, code)
        self.assertEqual(self.first_sha, payload["sha"])

    def test_git_resolve_an_unknown_ref_fails(self):
        code, payload, _ = run_shim("git-resolve", "--path", self.work, "--ref", "no-such-ref")

        self.assertEqual(1, code)
        self.assertIn("could not resolve", payload["error"])

    def test_git_ls_tree_lists_the_root_at_a_commit(self):
        code, payload, _ = run_shim(
            "git-ls-tree", "--path", self.work, "--ref", self.first_sha, "--dir", ""
        )

        self.assertEqual(0, code)
        names = [entry["name"] for entry in payload["entries"]]
        self.assertEqual(["a.txt"], names)
        self.assertEqual("blob", payload["entries"][0]["type"])

    def test_git_ls_tree_reflects_the_commit_not_the_working_tree(self):
        # b.txt only exists as of the second commit, so the first commit's tree
        # must not list it even though the working tree (checked out to master)
        # has it.
        code, payload, _ = run_shim(
            "git-ls-tree", "--path", self.work, "--ref", "master", "--dir", ""
        )

        names = [entry["name"] for entry in payload["entries"]]
        self.assertEqual(0, code)
        self.assertIn("b.txt", names)

    def test_git_show_blob_reads_file_content_at_a_commit(self):
        code, payload, _ = run_shim(
            "git-show-blob", "--path", self.work, "--ref", self.first_sha, "--file", "a.txt"
        )

        self.assertEqual(0, code)
        self.assertEqual("one\n", payload["content"])
        self.assertFalse(payload["binary"])
        self.assertFalse(payload["truncated"])

    def test_git_show_blob_truncates_past_max_bytes(self):
        code, payload, _ = run_shim(
            "git-show-blob", "--path", self.work, "--ref", self.first_sha, "--file", "a.txt",
            "--max-bytes", "2",
        )

        self.assertEqual(0, code)
        self.assertTrue(payload["truncated"])
        self.assertEqual(2, len(payload["content"]))

    def test_git_show_blob_flags_binary_content(self):
        (Path(self.origin) / "bin.dat").write_bytes(b"\x00\x01\x02binary")
        git("add", "-A", cwd=self.origin)
        git("commit", "-qm", "add binary", cwd=self.origin)
        run_shim("git-fetch", "--path", self.work)

        code, payload, _ = run_shim(
            "git-show-blob", "--path", self.work, "--ref", "origin/master", "--file", "bin.dat"
        )

        self.assertEqual(0, code)
        self.assertTrue(payload["binary"])
        self.assertEqual("", payload["content"])


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


class DependencyInstallTest(unittest.TestCase):
    """git-checkout, git-pull, repo-register and both rsync verbs install
    whatever a composer.json / package.json in the checkout declares."""

    @classmethod
    def setUpClass(cls):
        cls._tmp = tempfile.TemporaryDirectory()
        root = Path(cls._tmp.name)

        cls.origin = str(root / "origin")
        os.makedirs(cls.origin)

        git("init", "-q", "-b", "master", ".", cwd=cls.origin)
        git("config", "user.email", "deploy@wikioasis.org", cwd=cls.origin)
        git("config", "user.name", "Deploy", cwd=cls.origin)

        (Path(cls.origin) / "composer.json").write_text('{"name": "wikioasis/fixture"}\n')
        (Path(cls.origin) / "package.json").write_text('{"name": "fixture", "version": "1.0.0"}\n')
        git("add", "-A", cwd=cls.origin)
        git("commit", "-qm", "with manifests", cwd=cls.origin)

        cls.plain_origin = str(root / "plain-origin")
        os.makedirs(cls.plain_origin)

        git("init", "-q", "-b", "master", ".", cwd=cls.plain_origin)
        git("config", "user.email", "deploy@wikioasis.org", cwd=cls.plain_origin)
        git("config", "user.name", "Deploy", cwd=cls.plain_origin)
        (Path(cls.plain_origin) / "a.txt").write_text("one\n")
        git("add", "-A", cwd=cls.plain_origin)
        git("commit", "-qm", "no manifests", cwd=cls.plain_origin)

    @classmethod
    def tearDownClass(cls):
        cls._tmp.cleanup()

    def setUp(self):
        self._work_tmp = tempfile.TemporaryDirectory()

    def tearDown(self):
        self._work_tmp.cleanup()

    def _clone(self, origin: str) -> str:
        work = os.path.join(self._work_tmp.name, "work")
        subprocess.run(["git", "clone", "-q", origin, work], check=True, capture_output=True)

        return work

    def test_git_checkout_installs_composer_and_npm_dependencies(self):
        work = self._clone(self.origin)

        code, payload, _ = run_shim("git-checkout", "--path", work, "--ref", "master")

        self.assertEqual(0, code, payload)
        self.assertIn("composer install", payload["installed"])
        self.assertIn("npm install", payload["installed"])
        self.assertTrue((Path(work) / "vendor" / "autoload.php").exists())

    def test_git_checkout_is_a_no_op_without_a_manifest(self):
        work = self._clone(self.plain_origin)

        code, payload, _ = run_shim("git-checkout", "--path", work, "--ref", "master")

        self.assertEqual(0, code, payload)
        self.assertEqual([], payload["installed"])
        self.assertFalse((Path(work) / "vendor").exists())

    def test_git_pull_installs_dependencies_too(self):
        work = self._clone(self.origin)
        run_shim("git-checkout", "--path", work, "--ref", "master")
        shutil.rmtree(Path(work) / "vendor")

        code, payload, _ = run_shim("git-pull", "--path", work)

        self.assertEqual(0, code, payload)
        self.assertIn("composer install", payload["installed"])
        self.assertTrue((Path(work) / "vendor" / "autoload.php").exists())

    def test_repo_register_installs_dependencies_on_first_clone(self):
        target = os.path.join(self._work_tmp.name, "registered")

        code, payload, _ = run_shim("repo-register", "--url", self.origin, "--path", target)

        self.assertEqual(0, code, payload)
        self.assertIn("composer install", payload["installed"])
        self.assertTrue((Path(target) / "vendor" / "autoload.php").exists())

    def test_rsync_local_installs_dependencies_in_a_restricted_path(self):
        src = os.path.join(self._work_tmp.name, "src")
        dst = os.path.join(self._work_tmp.name, "dst")
        extension = os.path.join(src, "extensions", "Fixture")
        os.makedirs(extension)
        (Path(extension) / "composer.json").write_text('{"name": "wikioasis/fixture"}\n')
        os.makedirs(dst)

        code, payload, _ = run_shim(
            "rsync-local", "--src", src + "/", "--dst", dst + "/",
            "--path", "extensions/Fixture",
        )

        self.assertEqual(0, code, payload)
        self.assertEqual(1, len(payload["installed"]))
        self.assertIn("composer install", payload["installed"][0]["ran"])
        self.assertTrue((Path(dst) / "extensions" / "Fixture" / "vendor" / "autoload.php").exists())

    def test_rsync_local_without_a_path_restriction_only_checks_the_root(self):
        # No --path means a full-tree sync names no individual checkout to scope
        # to, so only the destination root itself is checked — not a recursive
        # walk of everything rsync just landed.
        src = os.path.join(self._work_tmp.name, "src2")
        dst = os.path.join(self._work_tmp.name, "dst2")
        extension = os.path.join(src, "extensions", "Fixture")
        os.makedirs(extension)
        (Path(extension) / "composer.json").write_text('{"name": "wikioasis/fixture"}\n')
        os.makedirs(dst)

        code, payload, _ = run_shim("rsync-local", "--src", src + "/", "--dst", dst + "/")

        self.assertEqual(0, code, payload)
        self.assertNotIn("installed", payload)
        self.assertFalse((Path(dst) / "extensions" / "Fixture" / "vendor").exists())

    def test_rsync_remote_installs_dependencies_in_a_restricted_path(self):
        src = os.path.join(self._work_tmp.name, "src3")
        dst = os.path.join(self._work_tmp.name, "dst3")
        extension = os.path.join(src, "extensions", "Fixture")
        os.makedirs(extension)
        (Path(extension) / "package.json").write_text('{"name": "fixture", "version": "1.0.0"}\n')
        os.makedirs(dst)

        code, payload, _ = run_shim(
            "rsync-remote", "--src", src + "/", "--dst", dst + "/",
            "--path", "extensions/Fixture",
        )

        self.assertEqual(0, code, payload)
        self.assertEqual(1, len(payload["installed"]))
        self.assertIn("npm install", payload["installed"][0]["ran"])


class ComposerMergeRootTest(unittest.TestCase):
    """An extension's dependencies belong to the core install above it."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)

    def tearDown(self):
        self._tmp.cleanup()

    def _core(self, *segments: str) -> Path:
        core = self.root.joinpath(*segments)
        (core / "extensions").mkdir(parents=True)
        (core / "skins").mkdir(parents=True)
        (core / "composer.json").write_text('{"name": "mediawiki/core"}\n')

        return core

    def test_an_extension_resolves_to_the_core_root_above_it(self):
        core = self._core("staging")
        extension = core / "extensions" / "Echo"
        extension.mkdir()

        self.assertEqual(str(core), shim.composer_merge_root(str(extension)))

    def test_a_skin_resolves_to_the_core_root_too(self):
        core = self._core("staging", "versions", "1.43")
        skin = core / "skins" / "Vector"
        skin.mkdir()

        self.assertEqual(str(core), shim.composer_merge_root(str(skin)))

    def test_the_nearest_core_root_wins(self):
        # A versioned tree has a composer.json at the deploy root as well; an
        # extension of 1.43 belongs to 1.43, not to whatever sits above it.
        outer = self._core("staging")
        inner = self._core("staging", "versions", "1.43")
        extension = inner / "extensions" / "Echo"
        extension.mkdir()

        resolved = shim.composer_merge_root(str(extension))

        self.assertEqual(str(inner), resolved)
        self.assertNotEqual(str(outer), resolved)

    def test_core_itself_has_no_merge_root(self):
        core = self._core("staging")

        self.assertIsNone(shim.composer_merge_root(str(core)))

    def test_a_checkout_outside_extensions_and_skins_has_no_merge_root(self):
        core = self._core("staging")
        config = core / "config"
        config.mkdir()

        self.assertIsNone(shim.composer_merge_root(str(config)))

    def test_an_extension_without_a_core_above_it_has_no_merge_root(self):
        extension = self.root / "loose" / "extensions" / "Echo"
        extension.mkdir(parents=True)

        self.assertIsNone(shim.composer_merge_root(str(extension)))


class ComposerLocalJsonTest(unittest.TestCase):
    """composer.local.json declares the manifest globs without eating anything
    an operator put there by hand."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.core = Path(self._tmp.name)

    def tearDown(self):
        self._tmp.cleanup()

    def _read(self) -> dict:
        return json.loads((self.core / "composer.local.json").read_text())

    def test_it_is_created_with_the_extension_and_skin_globs(self):
        self.assertTrue(shim.ensure_composer_merge_plugin(str(self.core)))

        merge = self._read()["extra"]["merge-plugin"]

        self.assertEqual(
            ["extensions/*/composer.json", "skins/*/composer.json"], merge["include"]
        )
        self.assertTrue(merge["recurse"])
        self.assertFalse(merge["merge-dev"])

    def test_writing_it_twice_is_a_no_op_the_second_time(self):
        self.assertTrue(shim.ensure_composer_merge_plugin(str(self.core)))
        self.assertFalse(shim.ensure_composer_merge_plugin(str(self.core)))

    def test_existing_content_is_preserved(self):
        (self.core / "composer.local.json").write_text(
            json.dumps(
                {
                    "require": {"wikioasis/pinned": "1.2.3"},
                    "extra": {"merge-plugin": {"include": ["extensions/Echo/composer.json"]}},
                }
            )
        )

        self.assertTrue(shim.ensure_composer_merge_plugin(str(self.core)))

        data = self._read()

        self.assertEqual({"wikioasis/pinned": "1.2.3"}, data["require"])
        self.assertEqual(
            [
                "extensions/Echo/composer.json",
                "extensions/*/composer.json",
                "skins/*/composer.json",
            ],
            data["extra"]["merge-plugin"]["include"],
        )

    def test_unparseable_json_is_an_error_rather_than_being_overwritten(self):
        (self.core / "composer.local.json").write_text("{not json")

        with self.assertRaises(shim.ShimError):
            shim.ensure_composer_merge_plugin(str(self.core))

        self.assertEqual("{not json", (self.core / "composer.local.json").read_text())


class ExtensionDependencyInstallTest(unittest.TestCase):
    """An extension inside a core tree installs from the core root, not in place."""

    @classmethod
    def setUpClass(cls):
        cls._tmp = tempfile.TemporaryDirectory()
        cls.origin = str(Path(cls._tmp.name) / "origin")
        os.makedirs(cls.origin)

        git("init", "-q", "-b", "master", ".", cwd=cls.origin)
        git("config", "user.email", "deploy@wikioasis.org", cwd=cls.origin)
        git("config", "user.name", "Deploy", cwd=cls.origin)
        (Path(cls.origin) / "composer.json").write_text('{"name": "wikioasis/fixture"}\n')
        git("add", "-A", cwd=cls.origin)
        git("commit", "-qm", "extension with a manifest", cwd=cls.origin)

    @classmethod
    def tearDownClass(cls):
        cls._tmp.cleanup()

    def setUp(self):
        self._work_tmp = tempfile.TemporaryDirectory()
        self.core = Path(self._work_tmp.name) / "staging" / "versions" / "1.43"
        (self.core / "extensions").mkdir(parents=True)
        (self.core / "composer.json").write_text('{"name": "mediawiki/core"}\n')

    def tearDown(self):
        self._work_tmp.cleanup()

    def _clone(self, name: str) -> str:
        target = str(self.core / "extensions" / name)
        subprocess.run(
            ["git", "clone", "-q", self.origin, target], check=True, capture_output=True
        )

        return target

    def test_git_checkout_installs_from_the_core_root(self):
        extension = self._clone("Fixture")

        code, payload, _ = run_shim("git-checkout", "--path", extension, "--ref", "master")

        self.assertEqual(0, code, payload)
        self.assertIn(f"composer install in {self.core}", payload["installed"])
        self.assertIn(f"composer.local.json merge in {self.core}", payload["installed"])
        self.assertTrue((self.core / "vendor" / "autoload.php").exists())
        # The vendor/ MediaWiki never autoloads is not created.
        self.assertFalse((Path(extension) / "vendor").exists())

    def test_git_pull_installs_from_the_core_root_too(self):
        extension = self._clone("Fixture")
        run_shim("git-checkout", "--path", extension, "--ref", "master")
        shutil.rmtree(self.core / "vendor")

        code, payload, _ = run_shim("git-pull", "--path", extension)

        self.assertEqual(0, code, payload)
        self.assertIn(f"composer install in {self.core}", payload["installed"])
        # Already declared by the checkout above, so not rewritten.
        self.assertNotIn(f"composer.local.json merge in {self.core}", payload["installed"])
        self.assertTrue((self.core / "vendor" / "autoload.php").exists())

    def test_repo_register_installs_from_the_core_root(self):
        target = str(self.core / "extensions" / "Registered")

        code, payload, _ = run_shim("repo-register", "--url", self.origin, "--path", target)

        self.assertEqual(0, code, payload)
        self.assertIn(f"composer install in {self.core}", payload["installed"])
        self.assertTrue((self.core / "vendor" / "autoload.php").exists())
        self.assertFalse((Path(target) / "vendor").exists())

    def test_several_synced_extensions_install_their_shared_root_once(self):
        # What a path-restricted rsync of two extensions of the same core does:
        # one root install covers both, so the second must not repeat it.
        for name in ("One", "Two"):
            extension = self.core / "extensions" / name
            extension.mkdir(parents=True)
            (extension / "composer.json").write_text('{"name": "wikioasis/fixture"}\n')

        # Called in-process rather than through rsync-local, so the web user has
        # to be pinned the way run_shim pins it for the subprocess tests.
        with unittest.mock.patch.object(shim, "WEB_USER", _current_user()):
            installed = shim.install_dependencies_for_sync(
                str(self.core), ["extensions/One", "extensions/Two"]
            )

        installs = [
            entry
            for target in installed
            for entry in target["ran"]
            if entry.startswith("composer install")
        ]

        self.assertEqual([f"composer install in {self.core}"], installs)
        self.assertTrue((self.core / "vendor" / "autoload.php").exists())


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

    def test_it_connects_directly_and_sends_the_vhost_as_a_host_header(self):
        # The check must work purely from a Host header — no DNS entry, real or
        # fake, exists anywhere for this vhost. If the shim ever went back to
        # resolving/pinning it instead, this would fail to connect exactly like
        # the bug it replaced.
        import http.server

        received_host = {}

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                received_host["value"] = self.headers.get("Host")
                body = b'<meta name="generator" content="MediaWiki 1.45.0"/>'
                self.send_response(200)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        server = http.server.HTTPServer(("127.0.0.1", 0), Handler)
        port = server.server_address[1]
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()

        try:
            code, payload, _ = run_shim(
                "canary", "--vhost", "canary.invalid", "--host", "127.0.0.1",
                "--port", str(port), "--scheme", "http",
                "--expect", 'content="MediaWiki', "--retries", "1", "--timeout", "5",
            )
        finally:
            server.shutdown()
            thread.join(timeout=5)

        self.assertEqual(0, code)
        self.assertTrue(payload["ok"])
        self.assertEqual("canary.invalid", received_host["value"])

    def test_a_non_200_response_still_passes_if_the_marker_is_present(self):
        # Only the body content is a canary failure now — a 503 from a maintenance
        # page or a redirect that still renders the wiki isn't one, matching the
        # icinga check this mirrors: it only alarms on missing MediaWiki content.
        import http.server

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                body = b'<meta name="generator" content="MediaWiki 1.45.0"/>'
                self.send_response(503)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        server = http.server.HTTPServer(("127.0.0.1", 0), Handler)
        port = server.server_address[1]
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()

        try:
            code, payload, _ = run_shim(
                "canary", "--vhost", "canary.invalid", "--host", "127.0.0.1",
                "--port", str(port), "--scheme", "http",
                "--expect", 'content="MediaWiki', "--retries", "1", "--timeout", "5",
            )
        finally:
            server.shutdown()
            thread.join(timeout=5)

        self.assertEqual(0, code)
        self.assertTrue(payload["ok"])

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


class RepoRemoveGuardTest(unittest.TestCase):
    """The guards on the most destructive operation in the system.

    Every one of these is a path that must never be deleted. They are tested at
    the shim rather than only in the portal because this is where `rm -rf`
    actually happens, and a bug upstream must not be able to reach it.
    """

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.base = Path(self._tmp.name)

        self.root = self.base / "srv" / "mediawiki"
        (self.root / "versions" / "1.45" / "extensions" / "Echo").mkdir(parents=True)
        (self.root / "versions" / "1.45" / "skins" / "Vector").mkdir(parents=True)
        (self.root / "config").mkdir(parents=True)
        (self.root / "versions" / "1.45" / "extensions" / "Echo" / "Echo.php").write_text("<?php\n")

        # A sibling that merely shares a name prefix with the root.
        self.sibling = self.base / "srv" / "mediawiki-old"
        self.sibling.mkdir(parents=True)
        (self.sibling / "keep.txt").write_text("important\n")

    def tearDown(self):
        self._tmp.cleanup()

    def remove(self, path, root=None, *extra):
        return run_shim("repo-remove", "--path", str(path), "--root", str(root or self.root), *extra)

    def test_it_refuses_the_deploy_root_itself(self):
        code, payload, _ = self.remove(self.root)

        self.assertEqual(1, code)
        self.assertIn("deploy root itself", payload["error"])
        self.assertTrue(self.root.is_dir())

    def test_it_refuses_the_filesystem_root(self):
        code, payload, _ = self.remove("/", root="/")

        self.assertEqual(1, code)
        self.assertIn("refusing to operate on /", payload["error"])

    def test_it_refuses_the_versions_directory(self):
        # Deleting this removes every core version at once.
        code, payload, _ = self.remove(self.root / "versions")

        self.assertEqual(1, code)
        self.assertIn("versions directory itself", payload["error"])
        self.assertTrue((self.root / "versions").is_dir())

    def test_it_refuses_a_whole_core_version_without_the_explicit_flag(self):
        code, payload, _ = self.remove(self.root / "versions" / "1.45")

        self.assertEqual(1, code)
        self.assertIn("--allow-version-root", payload["error"])
        self.assertTrue((self.root / "versions" / "1.45").is_dir())

    def test_it_refuses_a_path_outside_the_root(self):
        code, payload, _ = self.remove("/etc/passwd")

        self.assertEqual(1, code)
        self.assertIn("outside the deploy root", payload["error"])

    def test_it_refuses_a_sibling_that_shares_a_name_prefix(self):
        # A naive startswith() check would let /srv/mediawiki-old through a
        # /srv/mediawiki root.
        code, payload, _ = self.remove(self.sibling)

        self.assertEqual(1, code)
        self.assertIn("outside the deploy root", payload["error"])
        self.assertTrue((self.sibling / "keep.txt").exists())

    def test_it_refuses_a_relative_path(self):
        code, payload, _ = self.remove("versions/1.45/extensions/Echo")

        self.assertEqual(1, code)
        self.assertIn("must be absolute", payload["error"])

    def test_it_refuses_a_dotdot_escape(self):
        code, payload, _ = self.remove(str(self.root) + "/versions/1.45/../../../etc")

        self.assertEqual(1, code)
        self.assertIn("'..'", payload["error"])

    def test_it_refuses_without_a_root(self):
        code, payload, _ = run_shim(
            "repo-remove", "--path", str(self.root / "config"), "--root", ""
        )

        self.assertEqual(1, code)
        self.assertIn("--root is required", payload["error"])

    def test_it_refuses_a_symlink_pointing_outside_the_root(self):
        link = self.root / "versions" / "1.45" / "extensions" / "Escape"
        link.symlink_to(self.sibling)

        code, payload, _ = self.remove(link)

        self.assertEqual(1, code)
        self.assertIn("outside the deploy root", payload["error"])
        self.assertTrue((self.sibling / "keep.txt").exists())

    def test_it_refuses_a_file(self):
        code, payload, _ = self.remove(self.root / "versions" / "1.45" / "extensions" / "Echo" / "Echo.php")

        self.assertEqual(1, code)
        self.assertIn("not a directory", payload["error"])


class RepoRemoveTest(unittest.TestCase):
    """The removals that must succeed."""

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name) / "srv" / "mediawiki"
        self.echo = self.root / "versions" / "1.45" / "extensions" / "Echo"
        self.echo.mkdir(parents=True)
        (self.echo / "Echo.php").write_text("<?php\n")
        (self.root / "config").mkdir(parents=True)

    def tearDown(self):
        self._tmp.cleanup()

    def remove(self, path, *extra):
        return run_shim("repo-remove", "--path", str(path), "--root", str(self.root), *extra)

    def test_a_dry_run_reports_without_deleting(self):
        code, payload, _ = self.remove(self.echo, "--check")

        self.assertEqual(0, code)
        self.assertTrue(payload["checked"])
        self.assertFalse(payload["removed"])
        self.assertTrue(self.echo.is_dir())

    def test_it_removes_an_extension_checkout(self):
        code, payload, _ = self.remove(self.echo)

        self.assertEqual(0, code)
        self.assertTrue(payload["removed"])
        self.assertFalse(self.echo.exists())
        # The parent tree is left intact — only the checkout goes.
        self.assertTrue((self.root / "versions" / "1.45" / "extensions").is_dir())

    def test_removing_an_absent_checkout_succeeds(self):
        # The portal runs this once per server; a retry, or a server provisioned
        # after the checkout was already gone, must not fail the deployment.
        self.remove(self.echo)
        code, payload, _ = self.remove(self.echo)

        self.assertEqual(0, code)
        self.assertTrue(payload["ok"])
        self.assertFalse(payload["removed"])
        self.assertIn("already absent", payload["detail"])

    def test_it_removes_the_unversioned_config_checkout(self):
        code, _, _ = self.remove(self.root / "config")

        self.assertEqual(0, code)
        self.assertFalse((self.root / "config").exists())

    def test_it_removes_a_whole_core_version_with_the_explicit_flag(self):
        version = self.root / "versions" / "1.45"

        code, payload, _ = self.remove(version, "--allow-version-root")

        self.assertEqual(0, code)
        self.assertTrue(payload["removed"])
        self.assertFalse(version.exists())
        # The versions/ parent must survive: other versions live there.
        self.assertTrue((self.root / "versions").is_dir())


class VersionScaffoldTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)

    def tearDown(self):
        self._tmp.cleanup()

    def test_it_creates_the_version_tree(self):
        target = self.root / "srv" / "mediawiki-staging" / "versions" / "1.46"

        code, payload, _ = run_shim(
            "version-scaffold", "--path", str(target), "--version", "1.46"
        )

        self.assertEqual(0, code)
        for subdirectory in ("extensions", "skins", "cache"):
            self.assertTrue((target / subdirectory).is_dir(), subdirectory)
        self.assertEqual("1.46", payload["version"])

    def test_it_is_idempotent(self):
        target = self.root / "versions" / "1.46"

        run_shim("version-scaffold", "--path", str(target), "--version", "1.46")
        code, payload, _ = run_shim("version-scaffold", "--path", str(target), "--version", "1.46")

        self.assertEqual(0, code)
        self.assertEqual([], payload["created"])


class TreeScanTest(unittest.TestCase):
    """The read-only inventory the portal adopts an existing farm from.

    Built against real git checkouts rather than fixtures on purpose: the whole
    subcommand is an assertion about what git leaves on disk, and a hand-written
    .git/config would only prove the parser agrees with itself.
    """

    @classmethod
    def setUpClass(cls):
        cls._tmp = tempfile.TemporaryDirectory()
        root = Path(cls._tmp.name)

        cls.upstream = root / "upstream"
        cls.tree = root / "srv" / "mediawiki-staging"

        # One upstream per repository, so each checkout has a real remote.
        for name, manifest in (
            ("mediawiki", None),
            ("Echo", {"name": "Notifications", "version": "1.45", "license-name": "MIT",
                      "requires": {"MediaWiki": ">= 1.43.0"}}),
            ("Vector", {"name": "Vector"}),
        ):
            path = cls.upstream / name
            os.makedirs(path)
            git("init", "-q", "-b", "master", ".", cwd=str(path))
            git("config", "user.email", "deploy@wikioasis.org", cwd=str(path))
            git("config", "user.name", "Deploy", cwd=str(path))

            if manifest is not None:
                filename = "skin.json" if name == "Vector" else "extension.json"
                (path / filename).write_text(json.dumps(manifest))
            else:
                os.makedirs(path / "includes")
                (path / "includes" / "Defines.php").write_text(
                    "<?php\ndefine( 'MW_VERSION', '1.45.0' );\n"
                )

            git("add", "-A", cwd=str(path))
            git("commit", "-qm", "initial", cwd=str(path))
            git("branch", "-q", "REL1_45", cwd=str(path))

        # Core is the version directory itself, so it is cloned before the
        # extensions/ and skins/ scaffolding is created inside it — git refuses to
        # clone into a non-empty directory, which is the same reason the shim's
        # repo-register does.
        cls._clone("mediawiki", cls.tree / "versions" / "1.45", "REL1_45")

        os.makedirs(cls.tree / "versions" / "1.45" / "extensions", exist_ok=True)
        os.makedirs(cls.tree / "versions" / "1.45" / "skins", exist_ok=True)

        cls._clone("Echo", cls.tree / "versions" / "1.45" / "extensions" / "Echo", "REL1_45")
        cls._clone("Vector", cls.tree / "versions" / "1.45" / "skins" / "Vector", "master")

        # A directory nothing manages, which is exactly what the scan has to report
        # rather than quietly skip.
        os.makedirs(cls.tree / "versions" / "1.45" / "extensions" / "HandRolled")
        (cls.tree / "versions" / "1.45" / "extensions" / "HandRolled" / "x.php").write_text("<?php\n")

        # Config lives outside the version trees.
        cls._clone("Echo", cls.tree / "config", "master")

    @classmethod
    def _clone(cls, name: str, destination: Path, branch: str) -> None:
        subprocess.run(
            ["git", "clone", "-q", "--branch", branch, str(cls.upstream / name), str(destination)],
            check=True,
            capture_output=True,
        )

    @classmethod
    def tearDownClass(cls):
        cls._tmp.cleanup()

    def scan(self, *extra: str) -> dict:
        code, payload, _ = run_shim("tree-scan", "--root", str(self.tree), *extra)

        self.assertEqual(0, code, payload)

        return payload

    def entry(self, payload: dict, path: str) -> dict:
        matches = [entry for entry in payload["entries"] if entry["path"] == path]

        self.assertEqual(1, len(matches), f"expected exactly one entry for {path}")

        return matches[0]

    def test_it_finds_every_version_extension_and_skin(self):
        payload = self.scan()

        self.assertEqual(["1.45"], payload["versions"])
        self.assertEqual(
            sorted(
                [
                    "versions/1.45",
                    "versions/1.45/extensions/Echo",
                    "versions/1.45/extensions/HandRolled",
                    "versions/1.45/skins/Vector",
                    "config",
                ]
            ),
            sorted(entry["path"] for entry in payload["entries"]),
        )
        self.assertEqual({"core": 1, "extension": 2, "skin": 1, "config": 1}, payload["counts"])

    def test_it_reads_the_remote_and_the_current_ref_off_disk(self):
        echo = self.entry(self.scan(), "versions/1.45/extensions/Echo")

        self.assertTrue(echo["is_git"])
        self.assertEqual(str(self.upstream / "Echo"), echo["git"]["url"])
        # A branch checkout reports the branch, so an import pins to the branch
        # rather than freezing the farm at today's commit.
        self.assertEqual("branch", echo["git"]["ref_type"])
        self.assertEqual("REL1_45", echo["git"]["ref"])
        self.assertEqual("origin/REL1_45", echo["git"]["upstream"])
        self.assertEqual("master", echo["git"]["default_branch"])
        self.assertRegex(echo["git"]["commit"], r"^[0-9a-f]{40}$")

    def test_a_detached_head_reports_the_commit(self):
        path = self.tree / "versions" / "1.45" / "skins" / "Vector"
        sha = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=str(path), capture_output=True, text=True, check=True
        ).stdout.strip()

        git("checkout", "-q", "--detach", sha, cwd=str(path))

        try:
            vector = self.entry(self.scan(), "versions/1.45/skins/Vector")

            self.assertEqual("commit", vector["git"]["ref_type"])
            self.assertEqual(sha, vector["git"]["ref"])
        finally:
            git("checkout", "-q", "master", cwd=str(path))

    def test_it_reads_the_declared_name_out_of_extension_json(self):
        echo = self.entry(self.scan(), "versions/1.45/extensions/Echo")

        # The directory is Echo; the extension calls itself Notifications.
        self.assertEqual("Notifications", echo["meta"]["name"])
        self.assertEqual("1.45", echo["meta"]["version"])
        self.assertEqual("MIT", echo["meta"]["license-name"])
        self.assertEqual(">= 1.43.0", echo["meta"]["requires_mediawiki"])
        self.assertEqual("extension.json", echo["meta"]["manifest"])

    def test_a_skin_manifest_is_read_too(self):
        vector = self.entry(self.scan(), "versions/1.45/skins/Vector")

        self.assertEqual("skin.json", vector["meta"]["manifest"])
        self.assertEqual("skin", vector["kind"])

    def test_no_metadata_skips_manifest_parsing(self):
        echo = self.entry(self.scan("--no-metadata"), "versions/1.45/extensions/Echo")

        self.assertNotIn("meta", echo)
        # …but the git facts, which is what an import actually needs, are still there.
        self.assertEqual("REL1_45", echo["git"]["ref"])

    def test_core_reports_the_version_it_actually_is(self):
        core = self.entry(self.scan(), "versions/1.45")

        self.assertEqual("core", core["kind"])
        # Read from MW_VERSION rather than trusted from the directory name: a
        # versions/1.45 tree on a REL1_44 checkout is drift worth reporting.
        self.assertEqual("1.45.0", core["core_version"])

    def test_an_unmanaged_directory_is_reported_not_skipped(self):
        payload = self.scan()
        hand_rolled = self.entry(payload, "versions/1.45/extensions/HandRolled")

        self.assertFalse(hand_rolled["is_git"])
        self.assertNotIn("git", hand_rolled)
        self.assertIn(
            "versions/1.45/extensions/HandRolled: not a git checkout", payload["warnings"]
        )

    def test_it_follows_a_git_file_pointing_at_a_module_directory(self):
        """Extensions were historically submodules of core, so .git is often a file."""
        path = self.tree / "versions" / "1.45" / "extensions" / "Echo"
        real = path / ".git"
        moved = self.tree / "versions" / "1.45" / "modules-Echo"

        os.rename(real, moved)
        real.write_text(f"gitdir: {moved}\n")

        try:
            echo = self.entry(self.scan(), "versions/1.45/extensions/Echo")

            self.assertTrue(echo["is_git"])
            self.assertEqual("REL1_45", echo["git"]["ref"])
        finally:
            os.remove(real)
            os.rename(moved, real)

    def test_it_resolves_a_ref_out_of_packed_refs(self):
        path = self.tree / "versions" / "1.45" / "extensions" / "Echo"
        expected = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=str(path), capture_output=True, text=True, check=True
        ).stdout.strip()

        # `git pack-refs --all` removes the loose ref files the easy path reads.
        git("pack-refs", "--all", cwd=str(path))

        echo = self.entry(self.scan(), "versions/1.45/extensions/Echo")

        self.assertEqual(expected, echo["git"]["commit"])
        self.assertEqual("REL1_45", echo["git"]["ref"])

    def test_restricting_to_a_version_skips_the_unversioned_checkouts(self):
        payload = self.scan("--version", "1.45")

        self.assertEqual(["1.45"], payload["versions"])
        self.assertNotIn("config", [entry["path"] for entry in payload["entries"]])

        empty = self.scan("--version", "1.99")

        self.assertEqual([], empty["versions"])
        self.assertEqual([], empty["entries"])

    def test_the_config_directory_is_configurable(self):
        payload = self.scan("--config-dir", "nowhere")

        self.assertNotIn("config", [entry["path"] for entry in payload["entries"]])
        self.assertNotIn("nowhere", [entry["path"] for entry in payload["entries"]])

    def test_the_limit_is_reported_rather_than_silently_truncating(self):
        payload = self.scan("--limit", "2")

        self.assertEqual(2, len(payload["entries"]))
        self.assertTrue(any("--limit 2" in warning for warning in payload["warnings"]))

    def test_a_missing_root_fails_loudly(self):
        code, payload, _ = run_shim("tree-scan", "--root", "/definitely/not/here")

        self.assertEqual(1, code)
        self.assertFalse(payload["ok"])
        self.assertIn("no such directory", payload["error"])

    def test_scanning_changes_nothing_on_disk(self):
        def snapshot() -> set:
            return {
                (str(Path(base).relative_to(self.tree)), tuple(sorted(files)))
                for base, _, files in os.walk(self.tree)
            }

        before = snapshot()
        self.scan()

        self.assertEqual(before, snapshot())


class GitConfigParsingTest(unittest.TestCase):
    """The .git/config reader, on the shapes git actually writes."""

    def test_it_reads_a_subsectioned_remote_url(self):
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "config").write_text(
                "[core]\n\trepositoryformatversion = 0\n"
                '[remote "origin"]\n'
                "\turl = https://github.com/wikimedia/mediawiki.git\n"
                "\tfetch = +refs/heads/*:refs/remotes/origin/*\n"
                '[branch "REL1_45"]\n\tremote = origin\n\tmerge = refs/heads/REL1_45\n'
            )

            sections = shim.parse_git_config(tmp)

        self.assertEqual(
            "https://github.com/wikimedia/mediawiki.git",
            sections['remote "origin"']["url"],
        )
        self.assertEqual("refs/heads/REL1_45", sections['branch "REL1_45"']["merge"])

    def test_a_missing_config_is_empty_rather_than_an_exception(self):
        self.assertEqual({}, shim.parse_git_config("/definitely/not/here"))


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
