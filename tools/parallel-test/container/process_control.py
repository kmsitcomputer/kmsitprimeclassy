"""Owner execution only: Linux process ownership and pidfd signalling. Never imported by the auditor.
No fallback to numeric-PID kill: unsupported pidfds or unreadable live /proc entries fail closed.
"""
import json
import os
from pathlib import Path
import re
import signal
import sys


def identity(pid):
    directory = Path('/proc') / str(pid)
    try:
        env = dict(part.split(b'=', 1) for part in (directory / 'environ').read_bytes().split(b'\0') if b'=' in part)
        stat = (directory / 'stat').read_text()
        start = stat[stat.rfind(')') + 2:].split()[19]
        return env, start
    except (FileNotFoundError, ProcessLookupError):
        return None
    except PermissionError:
        if directory.exists():
            raise RuntimeError('cannot inspect a live process')
        return None


def owned(run_id, job_id=''):
    result = []
    for directory in Path('/proc').glob('[0-9]*'):
        current = identity(int(directory.name))
        if current is None:
            continue
        env, start = current
        if env.get(b'PC_RUN_ID') == run_id.encode() and (not job_id or env.get(b'PC_JOB_ID') == job_id.encode()):
            result.append((int(directory.name), start))
    return result


def control(run_id, job_id, action):
    if action not in ('probe', 'scan', 'TERM', 'KILL'):
        raise ValueError('invalid action')
    if not re.fullmatch(r'[a-f0-9]{12}', run_id) or (job_id and not re.fullmatch(r'[A-Za-z0-9_.-]+', job_id)):
        raise ValueError('invalid ownership marker')
    if not hasattr(os, 'pidfd_open') or not hasattr(signal, 'pidfd_send_signal'):
        raise RuntimeError('Linux pidfd support is required')
    # Prove the syscall is available (including seccomp permissions) without sending a signal.
    fd = os.pidfd_open(os.getpid())
    os.close(fd)
    matches = owned(run_id, job_id)
    if action in ('TERM', 'KILL'):
        for pid, start in matches:
            try:
                fd = os.pidfd_open(pid)
            except ProcessLookupError:
                continue
            try:
                current = identity(pid)
                if current is None:
                    continue
                env, current_start = current
                if current_start != start or env.get(b'PC_RUN_ID') != run_id.encode() or (job_id and env.get(b'PC_JOB_ID') != job_id.encode()):
                    raise RuntimeError('process identity changed; refusing to signal')
                try:
                    signal.pidfd_send_signal(fd, getattr(signal, 'SIG' + action))
                except ProcessLookupError:
                    pass
            finally:
                os.close(fd)
    return {'ok': True, 'pids': [pid for pid, _ in owned(run_id, job_id)]}


if __name__ == '__main__':
    try:
        print(json.dumps(control(*sys.argv[1:])))
    except Exception as exc:
        print(json.dumps({'ok': False, 'error': type(exc).__name__ + ': ' + str(exc)}))
        sys.exit(4)
