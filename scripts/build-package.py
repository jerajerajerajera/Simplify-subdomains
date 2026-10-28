#!/usr/bin/env python3
"""Builds the plugin zip for the Pelican Hub: dist/simplify-subdomains-v<version>.zip.

The zip holds one folder named after the plugin id with plugin.json, README.md, LICENSE.md, src, resources and lang.
Updates are published on the Hub only; this repository has no update files.
Usage: build-package.py [output-dir]   (default: dist; SOURCE_DATE_EPOCH fixes the timestamps)
"""
from pathlib import Path
from datetime import datetime, timezone
import json
import os
import sys
import zipfile

root = Path(__file__).resolve().parent.parent


def build(output: Path, epoch: int | None = None) -> Path:
    manifest = json.loads((root / 'plugin.json').read_text())
    plugin_id = manifest['id']
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f"{plugin_id}-v{manifest['version']}.zip"
    # ZIP extraction keeps mtimes: each release needs newer ones than the last, or compiled views look up to date.
    moment = datetime.fromtimestamp(epoch, timezone.utc) if epoch is not None else datetime.now(timezone.utc)
    files = [root / name for name in ['plugin.json', 'README.md', 'LICENSE.md']]
    for name in ['src', 'resources', 'database', 'lang']:
        if (root / name).is_dir():
            files.extend(p for p in (root / name).rglob('*') if p.is_file())
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as package:
        for path in sorted(files):
            info = zipfile.ZipInfo(plugin_id + '/' + path.relative_to(root).as_posix(), moment.timetuple()[:6])
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            package.writestr(info, path.read_bytes())
    return archive


if __name__ == '__main__':
    target = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root / 'dist'
    epoch = int(os.environ['SOURCE_DATE_EPOCH']) if 'SOURCE_DATE_EPOCH' in os.environ else None
    print(build(target, epoch))
