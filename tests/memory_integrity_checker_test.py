#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "tools" / "memory_integrity_check.py"


def run(cmd, cwd, check=True, env=None):
    return subprocess.run(cmd, cwd=cwd, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=check, env=env)


def commit(repo: Path, message: str) -> str:
    env = os.environ | {"GIT_AUTHOR_NAME":"Test", "GIT_AUTHOR_EMAIL":"test@example.com", "GIT_COMMITTER_NAME":"Test", "GIT_COMMITTER_EMAIL":"test@example.com"}
    run(["git", "add", "-A"], repo, env=env)
    run(["git", "commit", "-m", message], repo, env=env)
    return run(["git", "rev-parse", "HEAD"], repo).stdout.strip()


def ownership():
    return {"owners":[{"id":"test.owner","owning_paths":["owned.txt"]}]}


def manifest(sha: str, path: str = "owned.txt", **overrides):
    record = {
        "canonical_id":"test.record",
        "semantic_class":"IMPLEMENTATION",
        "truth_status":"VERIFIED",
        "criticality":"CRITICAL",
        "verified_sha":sha,
        "evidence":"https://example.test/evidence",
        "owning_paths":[path],
        "navigation":"active",
        "machine_ownership_id":"test.owner",
    }
    record.update(overrides)
    return {"schema_version":1, "records":[record]}


def check(repo: Path, data: dict, expected: int, owners=None):
    p = repo / "manifest.json"
    o = repo / "ownership.json"
    p.write_text(json.dumps(data), encoding="utf-8")
    o.write_text(json.dumps(owners or ownership()), encoding="utf-8")
    proc = run(["python3", str(SCRIPT), str(p), "--head", "HEAD", "--ownership-manifest", str(o), "--json"], repo, check=False)
    if proc.returncode != expected:
        raise AssertionError(f"expected rc={expected}, got {proc.returncode}\nstdout={proc.stdout}\nstderr={proc.stderr}")
    return json.loads(proc.stdout)


def main() -> int:
    with tempfile.TemporaryDirectory() as td:
        repo = Path(td)
        run(["git", "init", "-q"], repo)
        (repo / "owned.txt").write_text("v1\n", encoding="utf-8")
        (repo / "unrelated.txt").write_text("u1\n", encoding="utf-8")
        base = commit(repo, "base")

        (repo / "unrelated.txt").write_text("u2\n", encoding="utf-8")
        commit(repo, "unrelated")
        result = check(repo, manifest(base), 0)
        assert result["pass"] is True
        assert result["metrics"]["changed_owning_paths_after_verification"] == 0

        (repo / "owned.txt").write_text("v2\n", encoding="utf-8")
        commit(repo, "owned drift")
        result = check(repo, manifest(base), 1)
        codes = {i["code"] for i in result["issues"]}
        assert "changed_owning_paths_after_verification" in codes
        assert "critical_stale_verified" in codes

        result = check(repo, manifest("OWNER-2026-08-23"), 1)
        assert "invalid_verified_sha" in {i["code"] for i in result["issues"]}

        result = check(repo, manifest(base, "database/migrations-local/042–076"), 1)
        assert "fragile_migration_scope" in {i["code"] for i in result["issues"]}

        dup = manifest(base)
        dup["records"].append(dict(dup["records"][0]))
        result = check(repo, dup, 1)
        assert "duplicate_canonical_id" in {i["code"] for i in result["issues"]}

        result = check(repo, manifest(run(["git", "rev-parse", "HEAD"], repo).stdout.strip(), evidence=""), 1)
        assert "critical_missing_evidence" in {i["code"] for i in result["issues"]}

        result = check(repo, manifest(run(["git", "rev-parse", "HEAD"], repo).stdout.strip(), machine_ownership_id=""), 1)
        assert "missing_machine_ownership" in {i["code"] for i in result["issues"]}

        result = check(repo, manifest(run(["git", "rev-parse", "HEAD"], repo).stdout.strip(), machine_ownership_id="unknown.owner"), 1)
        assert "unknown_machine_ownership" in {i["code"] for i in result["issues"]}

        result = check(repo, manifest(run(["git", "rev-parse", "HEAD"], repo).stdout.strip(), truth_status="NEEDS_REVERIFY"), 1)
        assert "critical_needs_reverify" in {i["code"] for i in result["issues"]}

        result = check(repo, manifest(run(["git", "rev-parse", "HEAD"], repo).stdout.strip(), semantic_class="LEGACY_IMPLEMENTATION"), 1)
        assert "legacy_only_verified" in {i["code"] for i in result["issues"]}

        owner = {
            "schema_version":1,
            "records":[{
                "canonical_id":"policy.test",
                "semantic_class":"OWNER_DECISION",
                "truth_status":"VERIFIED",
                "criticality":"CRITICAL",
                "verified_sha":base,
                "evidence":"https://example.test/owner",
                "owning_paths":[],
                "navigation":"active",
                "machine_ownership_id":"test.owner",
                "active_policy_key":"test",
                "decision":{"id":"", "date":"2026-08-23", "source":"https://example.test/owner", "implementation_status":"IMPLEMENTED"}
            }]
        }
        result = check(repo, owner, 1)
        codes = {i["code"] for i in result["issues"]}
        assert "invalid_owner_verified_sha" in codes
        assert "owner_decision_missing_provenance" in codes

    print("memory_integrity_checker_test: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
