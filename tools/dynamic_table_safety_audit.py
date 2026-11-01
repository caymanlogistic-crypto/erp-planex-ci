#!/usr/bin/env python3
"""Fail-closed audit for the intentional dynamic SQL identifier sites.

The remaining Document-action and Superadmin dynamic identifiers have closed
whitelists. Contractor submit actions now use literal read-only prerequisites.
This audit fails if an identifier becomes request-derived or a schema-write
fallback returns to those contractor actions.
"""
from __future__ import annotations

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]

DOCUMENT_FILES = {
    Path("app/Http/Controllers/Company/DocumentActions/index.php"): "$whitelist",
    Path("app/Http/Controllers/Company/DocumentActions/upload_form.php"): "$entityInfo",
}
CONTRACTOR_FILES = {
    Path("app/Http/Controllers/Company/ContractorActions/create_full_submit.php"),
    Path("app/Http/Controllers/Company/ContractorActions/add_crew_submit.php"),
}
SUPERADMIN_ENTITY_FILE = Path(
    "app/Http/Controllers/Superadmin/ManagementActions/entity_list.php"
)
EXPECTED = set(DOCUMENT_FILES) | {SUPERADMIN_ENTITY_FILE}

ALLOWED_ENTITY_TABLES = {
    "client": ("clients", "name"),
    "contractor": ("contractors", "name"),
    "driver": ("drivers", "full_name"),
    "vehicle_unit": ("vehicle_units", "plate_number"),
    "vehicle_set": ("vehicle_sets", "id"),
    "driver_vehicle_block": ("driver_vehicle_blocks", "id"),
    "crew": ("crews", "id"),
}

SUPERADMIN_ENTITY_TABLES = {
    "client": "clients",
    "contractor": "contractors",
    "driver": "drivers",
    "vehicle_unit": "vehicle_units",
    "crew": "crews",
}

REQUIRED_CONTRACTOR_TABLES = (
    "contractors", "drivers", "vehicle_units", "vehicle_sets",
    "driver_vehicle_blocks", "crews",
)

# Keep deliberately aligned with architecture_guard.php's broad heuristic.
DYNAMIC_FROM = re.compile(r"FROM\s+`?\$[A-Za-z_][A-Za-z0-9_]*`?", re.I)


def fail(message: str) -> None:
    print(f"FAIL: {message}", file=sys.stderr)
    raise SystemExit(1)


def text(rel: Path) -> str:
    path = ROOT / rel
    if not path.is_file():
        fail(f"missing file: {rel}")
    return path.read_text(encoding="utf-8")


def scan_dynamic_identifier_sites() -> set[Path]:
    found: set[Path] = set()
    for path in (ROOT / "app").rglob("*.php"):
        content = path.read_text(encoding="utf-8")
        if DYNAMIC_FROM.search(content):
            found.add(path.relative_to(ROOT))
    return found


def audit_document_file(rel: Path, map_variable: str) -> None:
    content = text(rel)
    if map_variable not in content:
        fail(f"{rel}: expected closed map {map_variable} is missing")
    if "$entityType" not in content or "isset(" not in content:
        fail(f"{rel}: entity type is not visibly validated before identifier selection")

    for entity_type, (table, field) in ALLOWED_ENTITY_TABLES.items():
        if f"'{entity_type}'" not in content:
            fail(f"{rel}: missing entity whitelist entry {entity_type}")
        if f"'table'=>'{table}'" not in content and f"'table' => '{table}'" not in content:
            fail(f"{rel}: missing literal table mapping {entity_type}->{table}")
        field_tokens = (
            f"'df'=>'{field}'",
            f"'df' => '{field}'",
            f"'displayField'=>'{field}'",
            f"'displayField' => '{field}'",
        )
        if not any(token in content for token in field_tokens):
            fail(f"{rel}: missing literal display-field mapping {entity_type}->{field}")

    forbidden = (
        r"\$(?:entityTable|displayField|df)\s*=\s*\$_(?:GET|POST|REQUEST)",
        r"\$(?:entityTable|displayField|df)\s*=\s*[^;]*\$_(?:GET|POST|REQUEST)",
    )
    for pattern in forbidden:
        if re.search(pattern, content):
            fail(f"{rel}: request-derived SQL identifier detected")

    validation_pos = min(
        [
            p
            for p in (
                content.find("isset($entityInfo[$entityType])"),
                content.find("isset($whitelist[$entityType])"),
            )
            if p >= 0
        ]
        or [-1]
    )
    query_pos = content.find("FROM `$entityTable`")
    if query_pos < 0:
        query_pos = content.find("FROM $entityTable")
    if validation_pos < 0 or (query_pos >= 0 and validation_pos > query_pos):
        fail(f"{rel}: whitelist validation does not precede dynamic identifier use")


def audit_contractor_file(rel: Path) -> None:
    content = text(rel)
    for table in REQUIRED_CONTRACTOR_TABLES:
        if f"SELECT 1 FROM `{table}` LIMIT 0" not in content:
            fail(f"{rel}: literal read-only prerequisite missing for {table}")
    if re.search(r"\b(?:CREATE|ALTER|RENAME|DROP)\s+TABLE\b|->exec\s*\(", content, re.I):
        fail(f"{rel}: request-time schema mutation remains")
    if re.search(r"FROM\s+`?\$table", content, re.I):
        fail(f"{rel}: dynamic table identifier remains")


def audit_superadmin_entity_file(rel: Path) -> None:
    content = text(rel)
    if "$entityMap" not in content:
        fail(f"{rel}: entityMap closed whitelist is missing")
    validation = "!isset($entityMap[$entityType])"
    if validation not in content:
        fail(f"{rel}: entityType is not rejected against entityMap")
    if "$tableName=$meta['table']" not in content and "$tableName = $meta['table']" not in content:
        fail(f"{rel}: tableName no longer derives from validated entityMap metadata")
    if 'SELECT * FROM `$tableName` ORDER BY id DESC LIMIT 100' not in content:
        fail(f"{rel}: expected whitelisted table query changed; review required")

    for entity_type, table in SUPERADMIN_ENTITY_TABLES.items():
        if f"'{entity_type}'" not in content:
            fail(f"{rel}: missing superadmin whitelist entry {entity_type}")
        if f"'table'=>'{table}'" not in content and f"'table' => '{table}'" not in content:
            fail(f"{rel}: missing literal superadmin table mapping {entity_type}->{table}")

    if re.search(r"\$tableName\s*=\s*[^;]*\$_(?:GET|POST|REQUEST)", content):
        fail(f"{rel}: request-derived tableName detected")

    validation_pos = content.find(validation)
    query_pos = content.find('SELECT * FROM `$tableName`')
    if validation_pos < 0 or query_pos < 0 or validation_pos > query_pos:
        fail(f"{rel}: whitelist validation does not precede dynamic identifier use")


def main() -> int:
    found = scan_dynamic_identifier_sites()
    if found != EXPECTED:
        missing = sorted(str(p) for p in EXPECTED - found)
        unexpected = sorted(str(p) for p in found - EXPECTED)
        fail(f"dynamic identifier site set changed; missing={missing}, unexpected={unexpected}")

    for rel, map_variable in DOCUMENT_FILES.items():
        audit_document_file(rel, map_variable)
    for rel in CONTRACTOR_FILES:
        audit_contractor_file(rel)
    audit_superadmin_entity_file(SUPERADMIN_ENTITY_FILE)

    print("DYNAMIC_TABLE_SAFETY_AUDIT=PASS")
    print(f"approved_dynamic_identifier_sites={len(EXPECTED)}")
    for rel in sorted(EXPECTED):
        print(f"approved={rel}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
