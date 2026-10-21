"""Run a POSIX-only PHP integration test in local WSL using the isolated Windows test DB.

The TCP bridge binds only the WSL virtual gateway and exists only for this process.
No repository Git/Python command is run inside WSL.
"""

from __future__ import annotations

import os
import re
import select
import socket
import socketserver
import subprocess
import sys
import threading
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
TESTS = {
    "tests/feo_legacy_xa_mysql_test.php",
    "tests/feo_legacy_weight_bridge_xa_mysql_test.php",
    "tests/feo_placement_legacy_sync_xa_mysql_test.php",
}


class BridgeHandler(socketserver.BaseRequestHandler):
    def handle(self) -> None:
        with socket.create_connection(("127.0.0.1", self.server.mysql_port), timeout=5) as upstream:
            peers = (self.request, upstream)
            while True:
                readable, _, _ = select.select(peers, [], [], 60)
                if not readable:
                    return
                for source in readable:
                    chunk = source.recv(65536)
                    if not chunk:
                        return
                    (upstream if source is self.request else self.request).sendall(chunk)


class BridgeServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    allow_reuse_address = True
    daemon_threads = True


def main() -> int:
    if os.name != "nt" or len(sys.argv) != 2 or sys.argv[1] not in TESTS:
        raise RuntimeError("Windows and an allowlisted POSIX PHP test are required")
    if os.environ.get("APP_ENV") != "test" or not os.environ.get("PLANEX_TEST_FIXED_DB"):
        raise RuntimeError("An isolated test database is required")
    route = subprocess.run(
        ["wsl.exe", "-d", "Ubuntu", "--", "sh", "-lc", "ip route show default"],
        capture_output=True, text=True, check=True,
    ).stdout
    match = re.search(r"\bvia (\d{1,3}(?:\.\d{1,3}){3})\b", route)
    if not match:
        raise RuntimeError("WSL virtual gateway unavailable")
    gateway = match.group(1)
    windows_port = int(os.environ.get("PLANEX_SCHEMA_DB_PORT", "3306"))
    with BridgeServer((gateway, 0), BridgeHandler) as bridge:
        bridge.mysql_port = windows_port
        thread = threading.Thread(target=bridge.serve_forever, daemon=True)
        thread.start()
        env = os.environ.copy()
        env["PLANEX_SCHEMA_DB_HOST"] = gateway
        env["PLANEX_SCHEMA_DB_PORT"] = str(bridge.server_address[1])
        names = [
            "APP_ENV", "PLANEX_TEST_FIXED_DB", "PLANEX_TEST_FIXED_DB_ALLOWLIST",
            "PLANEX_SCHEMA_DB_HOST", "PLANEX_SCHEMA_DB_PORT", "PLANEX_SCHEMA_DB_USER",
            "PLANEX_SCHEMA_DB_PASSWORD", "PLANEX_TEST_LEGACY_USER",
            "PLANEX_TEST_LEGACY_PASSWORD",
        ]
        env["WSLENV"] = ":".join(filter(None, [env.get("WSLENV", ""), *names]))
        test = ROOT / sys.argv[1]
        drive = test.drive[0].lower()
        linux_path = "/mnt/" + drive + test.as_posix()[2:]
        result = subprocess.run(
            ["wsl.exe", "-d", "Ubuntu", "--", "/usr/bin/php", linux_path],
            cwd=ROOT, env=env, check=False,
        )
        bridge.shutdown()
        thread.join(timeout=5)
        return result.returncode


if __name__ == "__main__":
    raise SystemExit(main())
