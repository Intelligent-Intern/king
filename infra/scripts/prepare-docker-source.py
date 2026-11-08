#!/usr/bin/env python3
"""Snapshot current source files with shallow Git provenance for container builds.

The Git data is used only in the build stage; runtime images contain the
release package, its manifest and migration documentation, not this archive.
"""
import gzip
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile

root = Path(__file__).resolve().parents[2]
output = root / 'dist/docker-source/king-source.tar.gz'
output.parent.mkdir(parents=True, exist_ok=True)
epoch = int(subprocess.check_output(['git', '-C', str(root), 'show', '-s', '--format=%ct', 'HEAD'], text=True))
files = subprocess.check_output(['git', '-C', str(root), 'ls-files', '--cached', '--others', '--exclude-standard', '-z']).decode().split('\0')


def normalize(info):
    info.uid = info.gid = 0
    info.uname = info.gname = ''
    info.mtime = epoch
    return info


with tempfile.TemporaryDirectory(prefix='king-docker-provenance-') as temporary:
    checkout = Path(temporary) / 'checkout'
    subprocess.run(['git', 'clone', '--quiet', '--depth', '1', root.as_uri(), str(checkout)], check=True)
    subprocess.run(['git', '-C', str(checkout), 'remote', 'remove', 'origin'], check=True)
    with output.open('wb') as stream, gzip.GzipFile(filename='', mode='wb', fileobj=stream, mtime=epoch) as compressed:
        with tarfile.open(fileobj=compressed, mode='w') as archive:
            archive.add(checkout / '.git', arcname='.git', filter=normalize)
            for name in sorted(set(files)):
                path = root / name
                if name and (path.is_file() or path.is_symlink()):
                    archive.add(path, arcname=name, recursive=False, filter=normalize)
print(json.dumps({'archive': str(output), 'sha256': hashlib.sha256(output.read_bytes()).hexdigest()}))
