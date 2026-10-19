#!/usr/bin/env python3
"""Localhost-only versioned UI sandbox for iterative PLANEX design work."""
from __future__ import annotations

import argparse
import base64
from contextlib import contextmanager
from datetime import datetime, timezone
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
from typing import Iterator
from urllib.parse import parse_qs, unquote, urlsplit

SCHEMA = "planex-design-lab/v1"
ACCEPTANCE_SCHEMA = "planex-design-lab-acceptance/v1"
TASK_ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,95}")
VERSION = re.compile(r"v[0-9]{3}")
MAX_SNAPSHOT_BYTES = 8 * 1024 * 1024
LOOPBACK_HOSTS = {"127.0.0.1", "localhost", "[::1]"}

PARITY_CONTRACT = {
    "required": True,
    "baseline": "current-erp-page-visual-and-behavioral-1-to-1",
    "visual": [
        "shell-and-navigation",
        "layout-and-component-sizes",
        "typography-colors-and-spacing",
        "responsive-breakpoints-and-visible-states",
    ],
    "behavior": [
        "tabs-filters-and-sorting",
        "dropdowns-modals-and-tooltips",
        "hover-focus-keyboard-and-validation",
        "local-state-transitions",
    ],
    "safety_exceptions": [
        "production-writes",
        "production-api-and-network",
        "external-navigation",
    ],
}

UI_QUALITY_CONTRACT = {
    "required": True,
    "forms": [
        "reuse-existing-erp-components-and-tokens",
        "shared-grid-and-aligned-edges",
        "consistent-control-heights-padding-gaps-borders-radius-and-type",
        "semantic-checkbox-radio-and-field-groups",
        "stable-help-validation-and-action-placement",
        "complete-hover-focus-selected-disabled-error-success-states",
        "responsive-no-overflow-no-clipping",
    ],
    "verification": [
        "empty-filled-long-content-error-disabled-selected",
        "desktop-mobile-and-keyboard",
        "compare-adjacent-fields-and-repeated-rows-before-owner-review",
    ],
}

PRESETS = {
    "ui-standard": {
        "label": "Согласованный интерфейс ERP PLANEX",
        "target_route": "/ui-standard",
        "renderer": [sys.executable, "tools/ui_standard.py", "html"],
        "source_paths": ["docs/ui/approved-components.json", "docs/ui/specimens", "public/assets/css/registry-components.css"],
    },
    "trip-closing-documents": {
        "label": "Сбор закрывающих документов",
        "target_route": "/company/trips/closing-documents",
        "renderer": ["php", "tests/fixtures/trip_closing_design_lab_render.php"],
        "source_paths": [
            "app/View/pages/company_waste_trip_closing.php",
            "public/assets/css/waste-trip-closing.css",
            "public/assets/js/waste-trip-closing.js",
            "tests/fixtures/trip_closing_design_lab_render.php",
        ],
    },
    "waste-trips": {
        "label": "Перевозка отходов",
        "target_route": "/company/trips/departures",
        "renderer": ["php", "tests/fixtures/waste_trips_design_lab_render.php"],
        "source_paths": [
            "app/View/pages/company_waste_trips.php",
            "app/View/partials/company_waste_trip_modal_view.php",
            "app/View/partials/company_waste_trip_modal_edit.php",
            "public/assets/css/waste-trip-registry.css",
            "tests/fixtures/waste_trips_design_lab_render.php",
        ],
    },
    "manager-statistics": {
        "label": "Статистика менеджеров",
        "target_route": "/company/statistics/managers",
        "renderer": ["php", "tests/fixtures/manager_statistics_render.php"],
        "source_paths": [
            "app/View/pages/company_manager_statistics.php",
            "public/assets/css/manager-statistics.css",
            "public/assets/js/manager-statistics.js",
            "tests/fixtures/manager_statistics_render.php",
        ],
    },
}

DEFAULT_CSS = """/* DESIGN LAB overrides. Keep selectors scoped to the target page. */
"""

DEFAULT_JS = """(() => {
  document.documentElement.dataset.planexDesignLab = 'active';
  document.addEventListener('submit', (event) => {
    event.preventDefault();
    window.alert('DESIGN LAB: отправка форм и записи отключены.');
  }, true);
  document.addEventListener('click', (event) => {
    const target = event.target.closest?.('a[href]');
    if (!target) return;
    const url = new URL(target.href, window.location.href);
    const allowedPaths = new Set([window.location.pathname, '/']);
    if (url.origin !== window.location.origin || !allowedPaths.has(url.pathname)) {
      event.preventDefault();
      window.alert('DESIGN LAB: выход со страницы песочницы отключён.');
    }
  }, true);
})();
"""

PARITY_CHECKLIST = """# DESIGN LAB — базовая копия 1:1

До первой дизайнерской правки сравните песочницу с текущей ERP-страницей.

- [ ] Совпадают общий каркас, навигация, размеры блоков и расположение элементов.
- [ ] Совпадают шрифты, цвета, отступы, иконки и адаптивные состояния.
- [ ] Совпадают вкладки, фильтры, сортировки, списки, модальные окна и подсказки.
- [ ] Совпадают hover/focus, управление с клавиатуры, валидация и локальные переходы состояния.
- [ ] Синтетические данные сохраняют форму, порядок и набор доменных состояний реальной страницы.
- [ ] До подтверждения базового совпадения не внесено ни одного улучшения дизайна.

Допустимые отличия только защитные: production-записи, production API/сеть и внешние
переходы отключены. Их пользовательский результат имитируется локально, когда он нужен
для проверки сценария.
"""

UI_QUALITY_CHECKLIST = """# DESIGN LAB — качество форм и визуальная сетка

Проверяйте этот список после каждой законченной правки и до показа варианта OWNER.

- [ ] Использованы существующие ERP-компоненты и дизайн-токены; нет случайных локальных значений для одинаковых элементов.
- [ ] У формы единая сетка: левые и правые края полей, подписи, сообщения и действия выровнены.
- [ ] Одинаковые по смыслу элементы имеют одинаковые высоту, внутренние отступы, интервалы, рамку, радиус, шрифт и размер иконок.
- [ ] Независимое «да/нет» оформлено checkbox; выбор одного варианта — radio или единым переключателем, а не взаимоисключающими checkbox.
- [ ] Поля объединены семантически: label/fieldset/legend связаны с элементами, виден focus, форма управляется клавиатурой.
- [ ] Подсказки, ошибки, успешные состояния и кнопки находятся в постоянных местах и не вызывают неожиданных скачков раскладки.
- [ ] Проверены empty, filled, длинная подпись/значение, error, disabled, selected, hover и focus.
- [ ] Проверены согласованные desktop/mobile размеры: нет наложений, обрезки и случайной горизонтальной прокрутки.
- [ ] Перед показом OWNER визуально сравнены соседние поля и повторяющиеся строки; заметные расхождения исправлены.

Правка не готова к показу или checkpoint, пока применимые пункты не выполнены. В цикле
песочницы достаточно быстрой браузерной проверки: полный preflight запускается один раз
после принятия варианта и переноса в ERP.
"""


class Refused(RuntimeError):
    pass


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise Refused(reason)


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def atomic_write(path: Path, data: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=".design-lab-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def write_json(path: Path, value: object) -> None:
    atomic_write(path, (json.dumps(value, indent=2, ensure_ascii=False, sort_keys=True) + "\n").encode("utf-8"))


def read_json(path: Path) -> dict[str, object]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise Refused(f"INVALID_JSON:{path.name}:{exc}") from exc
    require(isinstance(value, dict), f"INVALID_JSON_OBJECT:{path.name}")
    return value


def git(worktree: Path, *args: str) -> str:
    process = subprocess.run(
        [os.environ.get("PLANEX_GIT", "git"), *args], cwd=worktree, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    require(process.returncode == 0, "GIT_FAILED:" + (process.stderr.strip() or "unknown"))
    return process.stdout.strip()


def resolve_worktree(value: str) -> Path:
    worktree = Path(value).resolve()
    require(worktree.is_dir() and not worktree.is_symlink(), "WORKTREE_REQUIRED")
    require((worktree / ".git").exists(), "GIT_WORKTREE_REQUIRED")
    return worktree


def resolve_local_root(value: str, worktree: Path) -> Path:
    local_root = Path(value).resolve()
    require(local_root.is_dir() and not local_root.is_symlink(), "LOCAL_ROOT_REQUIRED")
    require(not local_root.is_relative_to(worktree), "LOCAL_ROOT_INSIDE_WORKTREE")
    require(not worktree.is_relative_to(local_root), "WORKTREE_INSIDE_LOCAL_ROOT")
    return local_root


def session_root(local_root: Path, task_id: str) -> Path:
    require(TASK_ID.fullmatch(task_id) is not None, "INVALID_TASK_ID")
    root = (local_root / "design-lab" / task_id).resolve()
    require(root.is_relative_to((local_root / "design-lab").resolve()), "SESSION_OUTSIDE_LOCAL_ROOT")
    return root


def sanitize_snapshot(data: bytes) -> bytes:
    require(0 < len(data) <= MAX_SNAPSHOT_BYTES, "SNAPSHOT_SIZE_INVALID")
    text = data.decode("utf-8", errors="strict")
    lower = text.lower()
    require("<html" in lower and "</html>" in lower, "SNAPSHOT_HTML_REQUIRED")
    for token in ["type=\"password\"", "type='password'", "db_password", "erpv2_ssh_private_key"]:
        require(token not in lower, "SNAPSHOT_SENSITIVE_MARKER:" + token)
    text = re.sub(r"(?is)<base\b[^>]*>", "", text)
    # Snapshot forms must never preserve anti-CSRF material from a captured session.
    text = re.sub(
        r"(?is)(<input\b(?=[^>]*\bname=[\"'](?:_?csrf(?:_token)?|authenticity_token)[\"'])[^>]*\bvalue=[\"'])[^\"']*([\"'][^>]*>)",
        r"\1DESIGN-LAB-REDACTED\2",
        text,
    )
    return text.encode("utf-8")


def render_preset(worktree: Path, preset: dict[str, object]) -> bytes:
    command = [str(part) for part in preset["renderer"]]
    if command[0] == "php":
        command[0] = os.environ.get("PLANEX_PHP", "php")
    process = subprocess.run(
        command,
        cwd=worktree,
        env={**os.environ, "APP_ENV": "test", "PYTHONDONTWRITEBYTECODE": "1"},
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    require(process.returncode == 0, "PRESET_RENDER_FAILED:" + process.stderr.decode("utf-8", "replace").strip())
    return sanitize_snapshot(process.stdout)


def create_session(args: argparse.Namespace) -> None:
    worktree = resolve_worktree(args.worktree)
    local_root = resolve_local_root(args.local_root, worktree)
    root = session_root(local_root, args.task_id)
    if root.exists():
        require(args.resume, "SESSION_ALREADY_EXISTS")
        manifest = read_json(root / "session.json")
        require(manifest.get("worktree") == str(worktree), "SESSION_WORKTREE_MISMATCH")
        print("DESIGN_LAB_SESSION=" + str(root))
        print("DESIGN_LAB_RESUMED=YES")
        return
    require(args.preset in PRESETS, "UNKNOWN_PRESET")
    preset = PRESETS[args.preset]
    if args.mode == "synthetic":
        require(args.snapshot is None, "SNAPSHOT_FOR_SYNTHETIC_FORBIDDEN")
        baseline = render_preset(worktree, preset)
        source = "preset:" + args.preset
    else:
        require(args.snapshot is not None, "SNAPSHOT_FILE_REQUIRED")
        snapshot = Path(args.snapshot).resolve()
        require(snapshot.is_file() and not snapshot.is_symlink(), "SNAPSHOT_FILE_INVALID")
        baseline = sanitize_snapshot(snapshot.read_bytes())
        source = "snapshot:" + snapshot.name
    head = git(worktree, "rev-parse", "HEAD")
    temporary = root.with_name(root.name + ".creating-" + str(os.getpid()))
    require(not temporary.exists(), "SESSION_TEMP_EXISTS")
    try:
        (temporary / "workspace").mkdir(parents=True)
        (temporary / "versions").mkdir()
        atomic_write(temporary / "baseline.html", baseline)
        atomic_write(temporary / "workspace" / "overrides.css", DEFAULT_CSS.encode("utf-8"))
        atomic_write(temporary / "workspace" / "overrides.js", DEFAULT_JS.encode("utf-8"))
        atomic_write(temporary / "workspace" / "notes.md", b"# DESIGN LAB notes\n\n")
        atomic_write(temporary / "parity-checklist.md", PARITY_CHECKLIST.encode("utf-8"))
        atomic_write(temporary / "ui-quality-checklist.md", UI_QUALITY_CHECKLIST.encode("utf-8"))
        # Freeze visual dependencies with the baseline. A later source edit must
        # never silently change an already reviewed sandbox or checkpoint.
        ui_assets = {}
        asset_paths = list((worktree / "public/assets").rglob("*"))
        asset_paths += [worktree / "docs/ui" / name for name in ("gallery.css", "preview.css", "gallery.js", "approved-components.json")]
        for asset in sorted(asset_paths):
            if not asset.is_file():
                continue
            require(not asset.is_symlink(), "UI_ASSET_SYMLINK_FORBIDDEN")
            relative = asset.relative_to(worktree).as_posix()
            content = asset.read_bytes()
            atomic_write(temporary / "ui-assets" / relative, content)
            ui_assets[relative] = sha256(content)
        write_json(
            temporary / "session.json",
            {
                "schema": SCHEMA,
                "task_id": args.task_id,
                "preset": args.preset,
                "label": preset["label"],
                "target_route": preset["target_route"],
                "data_mode": args.mode,
                "data_source": source,
                "baseline_sha256": sha256(baseline),
                "source_head": head,
                "source_paths": preset["source_paths"],
                "worktree": str(worktree),
                "created_at": utc_now(),
                "accepted_version": None,
                "parity_contract": PARITY_CONTRACT,
                "ui_quality_contract": UI_QUALITY_CONTRACT,
                "ui_assets": ui_assets,
            },
        )
        root.parent.mkdir(parents=True, exist_ok=True)
        os.replace(temporary, root)
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)
    print("DESIGN_LAB_SESSION=" + str(root))
    print("DESIGN_LAB_MODE=" + args.mode.upper())
    print("DESIGN_LAB_TARGET=" + str(preset["target_route"]))


@contextmanager
def mutation_lock(root: Path) -> Iterator[None]:
    path = root / ".mutation.lock"
    path.touch(exist_ok=True)
    stream = path.open("r+b")
    try:
        if os.name == "nt":
            import msvcrt
            if path.stat().st_size == 0:
                stream.write(b"\0")
                stream.flush()
            stream.seek(0)
            msvcrt.locking(stream.fileno(), msvcrt.LK_LOCK, 1)
        else:
            import fcntl
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX)
        yield
    finally:
        if os.name == "nt":
            import msvcrt
            stream.seek(0)
            msvcrt.locking(stream.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl
            fcntl.flock(stream.fileno(), fcntl.LOCK_UN)
        stream.close()


def load_session(args: argparse.Namespace) -> tuple[Path, dict[str, object]]:
    task_id = args.task_id
    require(TASK_ID.fullmatch(task_id) is not None, "INVALID_TASK_ID")
    local_root = Path(args.local_root).resolve()
    root = session_root(local_root, task_id)
    require(root.is_dir() and not root.is_symlink(), "SESSION_NOT_FOUND")
    manifest = read_json(root / "session.json")
    require(manifest.get("schema") == SCHEMA and manifest.get("task_id") == task_id, "SESSION_IDENTITY_MISMATCH")
    return root, manifest


def checkpoint(args: argparse.Namespace) -> None:
    verify_session(args)
    root, manifest = load_session(args)
    with mutation_lock(root):
        versions = sorted(path.name for path in (root / "versions").iterdir() if path.is_dir() and VERSION.fullmatch(path.name))
        next_number = max((int(name[1:]) for name in versions), default=0) + 1
        require(next_number <= 999, "VERSION_LIMIT_REACHED")
        version = f"v{next_number:03d}"
        destination = root / "versions" / version
        destination.mkdir()
        hashes: dict[str, str] = {}
        for name in ["overrides.css", "overrides.js", "notes.md"]:
            source = root / "workspace" / name
            require(source.is_file(), "WORKSPACE_FILE_MISSING:" + name)
            data = source.read_bytes()
            atomic_write(destination / name, data)
            hashes[name] = sha256(data)
        screenshot_name = None
        if args.screenshot:
            screenshot = Path(args.screenshot).resolve()
            require(screenshot.is_file() and screenshot.suffix.lower() in {".png", ".jpg", ".jpeg", ".webp"}, "SCREENSHOT_INVALID")
            screenshot_name = "preview" + screenshot.suffix.lower()
            shutil.copyfile(screenshot, destination / screenshot_name)
            hashes[screenshot_name] = sha256((destination / screenshot_name).read_bytes())
        write_json(
            destination / "checkpoint.json",
            {
                "schema": SCHEMA,
                "version": version,
                "task_id": manifest["task_id"],
                "source_head": manifest["source_head"],
                "baseline_sha256": manifest["baseline_sha256"],
                "ui_assets": manifest.get("ui_assets", {}),
                "note": args.note or "",
                "created_at": utc_now(),
                "files": hashes,
                "screenshot": screenshot_name,
            },
        )
    print("DESIGN_LAB_CHECKPOINT=" + version)


def accept(args: argparse.Namespace) -> None:
    verify_session(args)
    root, manifest = load_session(args)
    require(VERSION.fullmatch(args.version) is not None, "INVALID_VERSION")
    version_root = root / "versions" / args.version
    require(version_root.is_dir(), "VERSION_NOT_FOUND")
    checkpoint_data = read_json(version_root / "checkpoint.json")
    with mutation_lock(root):
        acceptance = {
            "schema": ACCEPTANCE_SCHEMA,
            "task_id": manifest["task_id"],
            "approved_version": args.version,
            "approved_at": utc_now(),
            "source_head": manifest["source_head"],
            "target_route": manifest["target_route"],
            "data_mode": manifest["data_mode"],
            "data_source": manifest["data_source"],
            "baseline_sha256": manifest["baseline_sha256"],
            "ui_assets": manifest.get("ui_assets", {}),
            "files": checkpoint_data["files"],
            "owner_instruction": "ВНЕДРЯЙ",
        }
        write_json(root / "acceptance.json", acceptance)
        manifest["accepted_version"] = args.version
        manifest["accepted_at"] = acceptance["approved_at"]
        write_json(root / "session.json", manifest)
    print("DESIGN_LAB_ACCEPTED=" + args.version)
    print("DESIGN_LAB_ACCEPTANCE=" + str(root / "acceptance.json"))


def copy_checkpoint(args: argparse.Namespace, *, preview: bool) -> tuple[Path, dict[str, object]]:
    """Copy a verified immutable checkpoint; authority is handled by the caller."""
    worktree = resolve_worktree(args.worktree)
    local_root = resolve_local_root(args.local_root, worktree)
    destination = session_root(local_root, args.task_id)
    require(not destination.exists(), "SESSION_ALREADY_EXISTS")
    source_args = argparse.Namespace(task_id=args.source_task_id, local_root=str(local_root))
    verify_session(source_args)
    source, original = load_session(source_args)
    require(VERSION.fullmatch(args.version) is not None, "INVALID_VERSION")
    version_root = source / "versions" / args.version
    require(version_root.is_dir(), "VERSION_NOT_FOUND")
    require(bool(original.get("ui_assets")), "FROZEN_ASSETS_REQUIRED")
    if not preview:
        require(args.owner_instruction.strip() and args.decision_source.strip(), "OWNER_DECISION_REQUIRED")
    # A copied symlink/junction could escape the trusted immutable source.
    require(not any(path.is_symlink() or (hasattr(path, 'is_junction') and path.is_junction()) for path in source.rglob('*')), "REFERENCE_LINK_FORBIDDEN")
    temporary = destination.with_name(destination.name + '.importing-' + str(os.getpid()))
    require(not temporary.exists(), "SESSION_TEMP_EXISTS")
    try:
        temporary.mkdir(parents=True)
        for name in ('baseline.html','parity-checklist.md','ui-quality-checklist.md'):
            shutil.copyfile(source / name, temporary / name)
        shutil.copytree(source / 'ui-assets', temporary / 'ui-assets')
        shutil.copytree(version_root, temporary / 'versions' / args.version)
        (temporary / 'workspace').mkdir()
        for name in ('overrides.css','overrides.js','notes.md'):
            shutil.copyfile(version_root / name, temporary / 'workspace' / name)
        provenance = {'source_task_id':args.source_task_id,'version':args.version,'source_manifest_sha256':sha256((source/'session.json').read_bytes()),'source_checkpoint_sha256':sha256((version_root/'checkpoint.json').read_bytes()),'mode':'preview_only' if preview else 'approved_reference','imported_at':utc_now()}
        if not preview:
            provenance.update(owner_instruction=args.owner_instruction, decision_source=args.decision_source, excluded_roles=args.exclude_role)
        manifest = {**original,'task_id':args.task_id,'worktree':str(worktree),'accepted_version':None,'reference_origin':provenance}
        manifest.pop('accepted_at', None)
        write_json(temporary / 'session.json', manifest)
        write_json(temporary / 'reference-origin.json', provenance)
        os.replace(temporary, destination)
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)
    return destination, provenance


def fork_preview(args: argparse.Namespace) -> None:
    """Continue discussion in an owned copy without creating release authority."""
    destination, _ = copy_checkpoint(args, preview=True)
    verify_session(argparse.Namespace(task_id=args.task_id, local_root=args.local_root))
    print('DESIGN_LAB_PREVIEW_ONLY=' + str(destination))


def import_reference(args: argparse.Namespace) -> None:
    """Recover existing OWNER-approved implementation authority, not a proposal."""
    destination, provenance = copy_checkpoint(args, preview=False)
    local_root = Path(args.local_root).resolve()
    accept(argparse.Namespace(task_id=args.task_id,local_root=str(local_root),version=args.version))
    acceptance=read_json(destination/'acceptance.json')
    acceptance['owner_instruction']=args.owner_instruction
    acceptance['reference_origin']=provenance
    write_json(destination/'acceptance.json',acceptance)
    print('DESIGN_LAB_IMPORTED_REFERENCE=' + str(destination))


def verify_session(args: argparse.Namespace) -> None:
    root, manifest = load_session(args)
    baseline = (root / "baseline.html").read_bytes()
    require(sha256(baseline) == manifest.get("baseline_sha256"), "BASELINE_HASH_MISMATCH")
    for name, expected in dict(manifest.get("ui_assets", {})).items():
        frozen = (root / "ui-assets" / name).resolve()
        require(frozen.is_relative_to((root / "ui-assets").resolve()) and frozen.is_file() and sha256(frozen.read_bytes()) == expected, "UI_ASSET_HASH_MISMATCH:" + name)
        if getattr(args, "against_worktree", False):
            current = Path(str(manifest["worktree"])) / name
            require(current.is_file() and sha256(current.read_bytes()) == expected, "UI_STANDARD_SOURCE_DRIFT:" + name)
    for version_root in sorted((root / "versions").iterdir()):
        if not version_root.is_dir() or not VERSION.fullmatch(version_root.name):
            continue
        checkpoint_data = read_json(version_root / "checkpoint.json")
        require(checkpoint_data.get("ui_assets", {}) == manifest.get("ui_assets", {}), "CHECKPOINT_UI_ASSETS_MISMATCH:" + version_root.name)
        for name, expected in dict(checkpoint_data.get("files", {})).items():
            path = version_root / name
            require(path.is_file() and sha256(path.read_bytes()) == expected, "VERSION_HASH_MISMATCH:" + version_root.name + ":" + name)
    if (root / "acceptance.json").exists():
        acceptance = read_json(root / "acceptance.json")
        require(acceptance.get("schema") == ACCEPTANCE_SCHEMA, "ACCEPTANCE_SCHEMA_INVALID")
        require(acceptance.get("approved_version") == manifest.get("accepted_version"), "ACCEPTANCE_VERSION_MISMATCH")
        require(acceptance.get("ui_assets", {}) == manifest.get("ui_assets", {}), "ACCEPTANCE_UI_ASSETS_MISMATCH")
        accepted_checkpoint = read_json(root / "versions" / str(acceptance["approved_version"]) / "checkpoint.json")
        require(acceptance.get("files") == accepted_checkpoint.get("files"), "ACCEPTANCE_FILES_MISMATCH")
    print("DESIGN_LAB_VERIFY=PASS")


def inject_overrides(html: bytes, version: str = "") -> bytes:
    text = html.decode("utf-8")
    suffix = "?version=" + version if version else ""
    head = '<meta name="robots" content="noindex,nofollow,noarchive"><link rel="stylesheet" href="/__design_lab__/overrides.css' + suffix + '">'
    script = '<script src="/__design_lab__/overrides.js' + suffix + '" defer></script>'
    text = text.replace("</head>", head + "</head>", 1)
    text = text.replace("</body>", script + "</body>", 1)
    return text.encode("utf-8")


class DesignLabHandler(BaseHTTPRequestHandler):
    server_version = "PLANEXDesignLab/1"

    def _security_headers(self, content_type: str, inline_hashes: str = "") -> None:
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store, private")
        self.send_header("Pragma", "no-cache")
        self.send_header("X-Robots-Tag", "noindex, nofollow, noarchive")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Cross-Origin-Resource-Policy", "same-origin")
        self.send_header("Content-Security-Policy", "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'" + inline_hashes + "; connect-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")

    def _host_allowed(self) -> bool:
        raw = self.headers.get("Host", "").strip().lower()
        host = "[::1]" if raw.startswith("[::1]") else raw.split(":", 1)[0]
        return host in LOOPBACK_HOSTS

    def _send(self, status: int, data: bytes, content_type: str) -> None:
        self.send_response(status)
        inline_hashes = ""
        if content_type.startswith("text/html") and self.server.manifest.get("data_mode") == "synthetic":  # type: ignore[attr-defined]
            scripts = re.findall(rb"(?is)<script\b(?![^>]*\bsrc\s*=)[^>]*>(.*?)</script\s*>", data)
            inline_hashes = "".join(" 'sha256-" + base64.b64encode(hashlib.sha256(script).digest()).decode("ascii") + "'" for script in scripts)
        self._security_headers(content_type, inline_hashes)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self) -> None:  # noqa: N802
        if not self._host_allowed():
            self._send(421, b"Loopback Host header required.\n", "text/plain; charset=utf-8")
            return
        request_url = urlsplit(self.path)
        path = unquote(request_url.path)
        root: Path = self.server.session_root  # type: ignore[attr-defined]
        worktree: Path = self.server.worktree  # type: ignore[attr-defined]
        manifest: dict[str, object] = self.server.manifest  # type: ignore[attr-defined]
        version = parse_qs(request_url.query).get("version", [""])[0]
        if version == "accepted":
            # Read acceptance live so a running review server can display the
            # newly accepted immutable version without a process restart.
            acceptance_file = root / "acceptance.json"
            version = str(read_json(acceptance_file).get("approved_version", "")) if acceptance_file.is_file() else "missing"
        override_root = root / "workspace"
        if version:
            if VERSION.fullmatch(version) is None or not (root / "versions" / version / "checkpoint.json").is_file():
                self._send(404, b"DESIGN_LAB_VERSION_NOT_FOUND\n", "text/plain; charset=utf-8")
                return
            override_root = root / "versions" / version
        if path in {"/", "/index.html", str(manifest["target_route"])}:
            self._send(200, inject_overrides((root / "baseline.html").read_bytes(), version), "text/html; charset=utf-8")
            return
        if path == "/__design_lab__/overrides.css":
            self._send(200, (override_root / "overrides.css").read_bytes(), "text/css; charset=utf-8")
            return
        if path == "/__design_lab__/overrides.js":
            self._send(200, (override_root / "overrides.js").read_bytes(), "text/javascript; charset=utf-8")
            return
        if path == "/__design_lab__/status.json":
            status = {
                "schema": SCHEMA,
                "task_id": manifest["task_id"],
                "target_route": manifest["target_route"],
                "accepted_version": manifest.get("accepted_version"),
                "parity_required": bool(dict(manifest.get("parity_contract", {})).get("required")),
                "ui_quality_required": bool(dict(manifest.get("ui_quality_contract", {})).get("required")),
            }
            self._send(200, json.dumps(status, ensure_ascii=False).encode("utf-8"), "application/json; charset=utf-8")
            return
        if path.startswith("/__ui_standard__/") and path.rsplit("/", 1)[-1] in {"gallery.css", "preview.css", "gallery.js"}:
            name = path.rsplit("/", 1)[-1]
            asset = root / "ui-assets" / "docs/ui" / name
            if not asset.is_file():
                self._send(404, b"Not found.\n", "text/plain; charset=utf-8")
                return
            self._send(200, asset.read_bytes(), "text/javascript; charset=utf-8" if name.endswith(".js") else "text/css; charset=utf-8")
            return
        if path.startswith("/assets/"):
            public = ((root / "ui-assets/public") if manifest.get("ui_assets") else (worktree / "public")).resolve()
            asset = (public / path.lstrip("/")).resolve()
            if not asset.is_relative_to(public) or not asset.is_file() or asset.is_symlink():
                self._send(404, b"Not found.\n", "text/plain; charset=utf-8")
                return
            suffix = asset.suffix.lower()
            content_type = {".css": "text/css; charset=utf-8", ".js": "text/javascript; charset=utf-8", ".svg": "image/svg+xml", ".png": "image/png", ".woff2": "font/woff2"}.get(suffix, "application/octet-stream")
            self._send(200, asset.read_bytes(), content_type)
            return
        self._send(404, b"Not found.\n", "text/plain; charset=utf-8")

    def do_POST(self) -> None:  # noqa: N802
        self._send(405, b'{"error":"DESIGN_LAB_READ_ONLY"}\n', "application/json; charset=utf-8")

    do_PUT = do_POST
    do_PATCH = do_POST
    do_DELETE = do_POST

    def log_message(self, format: str, *args: object) -> None:
        sys.stderr.write("DESIGN_LAB_HTTP " + (format % args) + "\n")


def serve(args: argparse.Namespace) -> None:
    verify_session(args)
    root, manifest = load_session(args)
    worktree = Path(str(manifest["worktree"])).resolve()
    require(worktree.is_dir(), "SESSION_WORKTREE_MISSING")
    require(args.host in {"127.0.0.1", "::1"}, "LOOPBACK_BIND_REQUIRED")
    server = ThreadingHTTPServer((args.host, args.port), DesignLabHandler)
    server.daemon_threads = True
    server.session_root = root  # type: ignore[attr-defined]
    server.worktree = worktree  # type: ignore[attr-defined]
    server.manifest = manifest  # type: ignore[attr-defined]
    port = int(server.server_address[1])
    if args.ready_file:
        ready = Path(args.ready_file).resolve()
        write_json(ready, {"task_id": manifest["task_id"], "pid": os.getpid(), "host": args.host, "port": port, "session": str(root), "ready_at": utc_now()})
    print(f"DESIGN_LAB_URL=http://127.0.0.1:{port}{manifest['target_route']}", flush=True)
    try:
        server.serve_forever(poll_interval=0.2)
    finally:
        server.server_close()


def status(args: argparse.Namespace) -> None:
    root, manifest = load_session(args)
    versions = sorted(path.name for path in (root / "versions").iterdir() if path.is_dir() and VERSION.fullmatch(path.name))
    print(json.dumps({"session": str(root), "manifest": manifest, "versions": versions}, indent=2, ensure_ascii=False, sort_keys=True))


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    commands = result.add_subparsers(dest="command", required=True)
    create = commands.add_parser("create")
    create.add_argument("--task-id", required=True)
    create.add_argument("--worktree", required=True)
    create.add_argument("--local-root", required=True)
    create.add_argument("--preset", choices=sorted(PRESETS), default="manager-statistics")
    create.add_argument("--mode", choices=["synthetic", "snapshot"], default="synthetic")
    create.add_argument("--snapshot")
    create.add_argument("--resume", action="store_true")
    create.set_defaults(handler=create_session)
    imported = commands.add_parser('import-reference')
    for flag in ('task-id','source-task-id','local-root','worktree','version','owner-instruction','decision-source'):
        imported.add_argument('--'+flag, required=True)
    imported.add_argument('--exclude-role', action='append', default=[])
    imported.set_defaults(handler=import_reference)
    preview = commands.add_parser('fork-preview')
    for flag in ('task-id','source-task-id','local-root','worktree','version'):
        preview.add_argument('--'+flag, required=True)
    preview.set_defaults(handler=fork_preview)
    for name, handler in [("checkpoint", checkpoint), ("accept", accept), ("verify", verify_session), ("status", status), ("serve", serve)]:
        command = commands.add_parser(name)
        command.add_argument("--task-id", required=True)
        command.add_argument("--local-root", required=True)
        if name == "checkpoint":
            command.add_argument("--note")
            command.add_argument("--screenshot")
        if name == "accept":
            command.add_argument("--version", required=True)
        if name == "verify":
            command.add_argument("--against-worktree", action="store_true", help="also reject visual dependency drift since sandbox creation")
        if name == "serve":
            command.add_argument("--host", default="127.0.0.1")
            command.add_argument("--port", type=int, default=0)
            command.add_argument("--ready-file")
        command.set_defaults(handler=handler)
    return result


def main() -> int:
    args = parser().parse_args()
    try:
        args.handler(args)
        return 0
    except (Refused, OSError, UnicodeError, subprocess.SubprocessError, ValueError) as exc:
        print("DESIGN_LAB_REFUSED=" + str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
