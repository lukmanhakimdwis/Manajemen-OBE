#!/usr/bin/env python3
"""Build the installable Moodle plugin from the reviewed obe/ source tree."""
from pathlib import Path
import os
import tempfile
import zipfile

root = Path(__file__).resolve().parents[1]
with tempfile.NamedTemporaryFile(dir=root, suffix='.zip', delete=False) as tmp:
    temporary = Path(tmp.name)
try:
    with zipfile.ZipFile(temporary, 'w', zipfile.ZIP_DEFLATED) as archive:
        for path in sorted((root / 'obe').rglob('*')):
            if not path.is_file() or any(part in {'.git', '__pycache__', '.pytest_cache'} for part in path.parts):
                continue
            info = zipfile.ZipInfo(path.relative_to(root).as_posix(), (2026, 11, 15, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)
    os.replace(temporary, root / 'obe.zip')
finally:
    temporary.unlink(missing_ok=True)
