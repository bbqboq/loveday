#!/usr/bin/env python3
"""바뀐 파일만 zip으로 묶기 + '이어 올리기' 대비 끝 덧붙임 (1019v)

실서버에 파일을 올리는 도구가 같은 이름 파일을 덮어쓸 때 파일을 비우지 않고(이어 올리기/resume)
앞부분만 덮어써서, 새 파일이 예전보다 작으면 예전 파일 뒷부분이 남는 일이 있었음
(1018z17p HTML · 1019u editor.min.js → 문법 오류로 에디터가 '여는 중'에서 멈춤, section_defaults.php → 500).
그래서 zip에 넣는 파일은 지난 커밋들에서 가장 컸던 크기 + 여유만큼 끝을 채움 (내용은 그대로):
  .js/.css  → 끝에 /* 공백 */ 주석
  .php      → 끝에 __halt_compiler(); (그 뒤는 PHP가 읽지 않음) + 공백
  .html     → 끝에 공백 (맨 끝 <plaintext hidden> 가 남은 부분을 숨김)
압축 전송(gzip)이면 공백은 거의 0바이트. 저장소 파일은 바꾸지 않음.

쓰는 법: python3 tools/pack-zip.py <zip 경로> <기준 커밋(이 커밋 뒤로 바뀐 파일)> [파일 ...]
  파일을 안 주면 기준 커밋..HEAD 사이에 바뀐 invite/ 파일 (원본 editor.js·editor.css·루트 render-invite.js는 뺌)
"""
import os, subprocess, sys, zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SKIP = {'invite/assets/editor.js', 'invite/assets/editor.css', 'invite/render-invite.js', 'invite/config.php'}
MARGIN = 4096


def git(*a):
    return subprocess.run(['git', *a], cwd=ROOT, capture_output=True, text=True).stdout


def hist_max(path, n=60):
    m = 0
    for c in git('log', '-n', str(n), '--format=%h', '--', path).split():
        s = git('cat-file', '-s', f'{c}:{path}').strip()
        if s.isdigit():
            m = max(m, int(s))
    return m


def padded(path, data):
    target = max(hist_max(path), len(data)) + MARGIN
    need = target - len(data)
    ext = os.path.splitext(path)[1].lower()
    if ext in ('.js', '.css'):
        return data + b'\n/*' + b' ' * max(0, need - 5) + b'*/\n'
    if ext == '.php':
        txt = data.rstrip()
        in_php = txt.rfind(b'<?php') > txt.rfind(b'?>')  # 파일 끝이 PHP 코드 안인지 (닫는 ?> 없음)
        tail = (b'\n' if in_php else b'\n<?php ') + b'__halt_compiler();'
        return data + tail + b' ' * max(0, need - len(tail))
    if ext in ('.html', '.htm'):
        return data + b'\n' + b' ' * max(0, need - 1)
    return data  # 그림 등은 그대로


def main():
    if len(sys.argv) < 3:
        print(__doc__); sys.exit(1)
    out, base, files = sys.argv[1], sys.argv[2], sys.argv[3:]
    if not files:
        files = [f for f in git('diff', '--name-only', f'{base}', 'HEAD', '--', 'invite').split() if f not in SKIP and os.path.exists(os.path.join(ROOT, f))]
    if os.path.exists(out):
        os.remove(out)
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for f in files:
            if f in SKIP:
                continue
            data = open(os.path.join(ROOT, f), 'rb').read()
            p = padded(f, data)
            z.writestr(f, p)
            print(f'{f}: {len(data)} → {len(p)}')


if __name__ == '__main__':
    main()
