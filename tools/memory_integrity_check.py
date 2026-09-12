#!/usr/bin/env python3
"""Path-aware integrity checker for ERP PLANEX critical semantic-memory records."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from collections import Counter, defaultdict
from pathlib import Path

SHA_RE = re.compile(r"^[0-9a-f]{40}$")
SEMANTIC_CLASSES = {"IMPLEMENTATION", "OWNER_DECISION", "TARGET", "LEGACY_IMPLEMENTATION", "HISTORICAL"}
TRUTH_STATUSES = {"VERIFIED", "NEEDS_REVERIFY", "CONFLICT", "SUPERSEDED", "UNKNOWN"}
CRITICALITIES = {"LOW", "MEDIUM", "HIGH", "CRITICAL"}
FRAGILE_MIGRATION_RANGE_RE = re.compile(r"migrations(?:-local)?[^\n;]*\b\d{3}\s*[-–—]\s*\d{3}\b", re.I)


def git(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["git", *args], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=check)


def commit_exists(sha: str) -> bool:
    return git("cat-file", "-e", f"{sha}^{{commit}}", check=False).returncode == 0


def to_pathspec(path: str) -> str:
    return f":(glob){path}" if any(ch in path for ch in "*?[{") else path


def owning_paths_changed(verified_sha: str, head: str, paths: list[str]) -> bool:
    if verified_sha == head:
        return False
    proc = git("diff", "--quiet", f"{verified_sha}..{head}", "--", *[to_pathspec(p) for p in paths], check=False)
    if proc.returncode == 0:
        return False
    if proc.returncode == 1:
        return True
    raise RuntimeError(proc.stderr.strip() or "git diff failed")


def issue(issues: list[dict[str, str]], record_id: str, code: str, detail: str) -> None:
    issues.append({"canonical_id": record_id, "code": code, "detail": detail})


def ownership_scopes(ownership_manifest: dict | None) -> dict[str, list[str]]:
    if ownership_manifest is None:
        return {}
    owners = ownership_manifest.get("owners")
    if not isinstance(owners, list):
        raise ValueError("ownership manifest owners must be a list")
    scopes: dict[str, list[str]] = {}
    for owner in owners:
        owner_id = str(owner.get("id", "")).strip()
        paths = owner.get("owning_paths")
        if not owner_id or owner_id in scopes:
            raise ValueError("ownership manifest ids must be unique and non-empty")
        if not isinstance(paths, list) or not paths or any(not isinstance(p, str) or not p.strip() for p in paths):
            raise ValueError(f"ownership manifest owning_paths invalid for {owner_id or '<missing>'}")
        scopes[owner_id] = [p.strip() for p in paths]
    return scopes


def validate_manifest(manifest: dict, head: str, ownership_manifest: dict | None = None) -> tuple[list[dict[str, str]], dict[str, int]]:
    issues: list[dict[str, str]] = []
    records = manifest.get("records")
    if not isinstance(records, list):
        return ([{"canonical_id": "<manifest>", "code": "invalid_manifest", "detail": "records must be a list"}], {})
    owner_scopes = ownership_scopes(ownership_manifest)
    known_owners = set(owner_scopes)

    ids = [str(r.get("canonical_id", "")).strip() for r in records]
    for cid, count in Counter(ids).items():
        if not cid:
            issue(issues, "<missing>", "missing_canonical_id", "record has no canonical_id")
        elif count > 1:
            issue(issues, cid, "duplicate_canonical_id", f"canonical_id appears {count} times")

    active_policy: dict[str, list[str]] = defaultdict(list)
    metrics = Counter()

    for rec in records:
        cid = str(rec.get("canonical_id", "")).strip() or "<missing>"
        semantic_class = str(rec.get("semantic_class", "")).strip()
        truth_status = str(rec.get("truth_status", "")).strip()
        criticality = str(rec.get("criticality", "")).strip()
        verified_sha = rec.get("verified_sha")
        evidence = str(rec.get("evidence", "")).strip()
        paths = rec.get("owning_paths") or []
        navigation = str(rec.get("navigation", "")).strip().lower()
        machine_owner = str(rec.get("machine_ownership_id", "")).strip()

        if semantic_class not in SEMANTIC_CLASSES:
            issue(issues, cid, "invalid_semantic_class", semantic_class or "missing")
        if truth_status not in TRUTH_STATUSES:
            issue(issues, cid, "invalid_truth_status", truth_status or "missing")
        if criticality not in CRITICALITIES:
            issue(issues, cid, "invalid_criticality", criticality or "missing")
        if not isinstance(paths, list) or any(not isinstance(p, str) or not p.strip() for p in paths):
            issue(issues, cid, "invalid_owning_paths", "owning_paths must be an array of non-empty strings")
            paths = []

        active = navigation == "active" and truth_status != "SUPERSEDED"
        if active and not machine_owner:
            issue(issues, cid, "missing_machine_ownership", "active record has no machine_ownership_id")
            metrics["unresolved_machine_ownership"] += 1
        elif active and known_owners and machine_owner not in known_owners:
            issue(issues, cid, "unknown_machine_ownership", machine_owner)
            metrics["unresolved_machine_ownership"] += 1

        # The Machine Ownership ID is the normative drift boundary. Rendered
        # record owning_paths are human/compatibility context only (Memory
        # Governance contract §9) and must not drive git pathspec evaluation
        # when a canonical ownership manifest is available.
        machine_paths = owner_scopes.get(machine_owner, paths) if owner_scopes else paths

        if criticality == "CRITICAL" and truth_status == "NEEDS_REVERIFY":
            issue(issues, cid, "critical_needs_reverify", "critical record cannot remain NEEDS_REVERIFY at terminal state")
            metrics["critical_needs_reverify"] += 1
        if semantic_class == "LEGACY_IMPLEMENTATION" and truth_status == "VERIFIED":
            issue(issues, cid, "legacy_only_verified", "legacy implementation must not be current VERIFIED truth")
            metrics["legacy_only_verified"] += 1

        for path in machine_paths:
            if FRAGILE_MIGRATION_RANGE_RE.search(path):
                issue(issues, cid, "fragile_migration_scope", path)
                metrics["fragile_migration_scopes"] += 1

        if navigation == "active" and truth_status == "SUPERSEDED":
            issue(issues, cid, "superseded_active_navigation", "SUPERSEDED record is marked active")
            metrics["superseded_leaks"] += 1

        if semantic_class == "OWNER_DECISION" and truth_status != "SUPERSEDED":
            decision = rec.get("decision") or {}
            required = ["id", "date", "source", "implementation_status"]
            missing = [k for k in required if not str(decision.get(k, "")).strip()]
            if missing:
                issue(issues, cid, "owner_decision_missing_provenance", ", ".join(missing))
                metrics["owner_decisions_missing_provenance"] += 1
            key = str(rec.get("active_policy_key", "")).strip()
            if key and truth_status in {"VERIFIED", "UNKNOWN", "CONFLICT"}:
                active_policy[key].append(cid)
            if verified_sha not in (None, ""):
                issue(issues, cid, "invalid_owner_verified_sha", "OWNER_DECISION must not use Git Verified SHA")
                metrics["invalid_verified_sha"] += 1
            if criticality == "CRITICAL" and not evidence:
                issue(issues, cid, "critical_missing_evidence", "critical OWNER_DECISION has no evidence")
                metrics["critical_missing_evidence"] += 1
            continue

        implementation_like = semantic_class in {"IMPLEMENTATION", "LEGACY_IMPLEMENTATION"}
        if criticality == "CRITICAL" and implementation_like and not machine_paths:
            issue(issues, cid, "orphan_critical_record", "critical implementation record has no machine ownership scope")
            metrics["orphan_critical_records"] += 1

        if truth_status == "VERIFIED" and implementation_like:
            if not isinstance(verified_sha, str) or not SHA_RE.fullmatch(verified_sha):
                issue(issues, cid, "invalid_verified_sha", str(verified_sha))
                metrics["invalid_verified_sha"] += 1
                continue
            if not commit_exists(verified_sha):
                issue(issues, cid, "verified_sha_not_found", verified_sha)
                metrics["invalid_verified_sha"] += 1
                continue
            if criticality == "CRITICAL" and not evidence:
                issue(issues, cid, "critical_missing_evidence", "critical VERIFIED implementation has no evidence")
                metrics["critical_missing_evidence"] += 1
            if machine_paths:
                try:
                    changed = owning_paths_changed(verified_sha, head, machine_paths)
                except RuntimeError as exc:
                    issue(issues, cid, "git_diff_error", str(exc))
                    continue
                if changed:
                    issue(issues, cid, "changed_owning_paths_after_verification", f"relevant diff exists in {verified_sha}..{head}")
                    metrics["changed_owning_paths_after_verification"] += 1
                    if criticality == "CRITICAL":
                        issue(issues, cid, "critical_stale_verified", "critical record remains VERIFIED after owning-path drift")
                        metrics["critical_stale_verified"] += 1

        if criticality == "CRITICAL" and truth_status == "VERIFIED" and not evidence:
            if not any(i["canonical_id"] == cid and i["code"] == "critical_missing_evidence" for i in issues):
                issue(issues, cid, "critical_missing_evidence", "critical VERIFIED record has no evidence")
                metrics["critical_missing_evidence"] += 1

    for key, record_ids in active_policy.items():
        if len(record_ids) > 1:
            for cid in record_ids:
                issue(issues, cid, "conflicting_active_policy", f"active_policy_key={key!r} shared by {record_ids}")
            metrics["critical_conflicts"] += 1

    for name in (
        "critical_conflicts", "critical_stale_verified", "critical_needs_reverify", "legacy_only_verified",
        "invalid_verified_sha", "critical_missing_evidence", "changed_owning_paths_after_verification",
        "orphan_critical_records", "owner_decisions_missing_provenance", "unresolved_machine_ownership",
        "superseded_leaks", "fragile_migration_scopes",
    ):
        metrics.setdefault(name, 0)
    return issues, dict(metrics)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifest", type=Path)
    parser.add_argument("--head", default="HEAD", help="Git revision representing current canonical/feature state")
    parser.add_argument("--ownership-manifest", type=Path)
    parser.add_argument("--json", action="store_true", dest="as_json")
    args = parser.parse_args()

    try:
        head = git("rev-parse", args.head).stdout.strip()
        manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
        ownership = json.loads(args.ownership_manifest.read_text(encoding="utf-8")) if args.ownership_manifest else None
        issues, metrics = validate_manifest(manifest, head, ownership)
    except (OSError, json.JSONDecodeError, subprocess.CalledProcessError, RuntimeError, ValueError) as exc:
        print(f"memory-integrity: ERROR: {exc}", file=sys.stderr)
        return 2

    result = {"head": head, "issues": issues, "metrics": metrics, "pass": not issues}
    if args.as_json:
        print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    else:
        print(f"memory-integrity head={head}")
        for item in issues:
            print(f"FAIL {item['code']} [{item['canonical_id']}]: {item['detail']}")
        print("metrics=" + json.dumps(metrics, sort_keys=True))
        print("MEMORY_INTEGRITY_PASS" if not issues else "MEMORY_INTEGRITY_FAIL")
    return 0 if not issues else 1


if __name__ == "__main__":
    raise SystemExit(main())
