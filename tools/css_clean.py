#!/usr/bin/env python3
"""Make a stylesheet smaller without changing how any page looks.

    python3 tools/css_clean.py public/assets/theme.css            (writes the file in place)
    python3 tools/css_clean.py public/assets/theme.css --check    (only prints the sizes)

What it does, all of it safe for the cascade:
1. Removes comments and extra spaces.
2. A declaration is dropped when a LATER rule with exactly the same selector, inside the same kind of block
   (top level, or the same @media / @supports condition), sets the same property. The later one always wins,
   so the earlier one never shows. Skipped when the later value is a newer CSS feature that older phones may not
   understand (dvh, color-mix, env(), -webkit- ...), because there the earlier value is the fallback.
3. Rules left empty are removed, and so are @keyframes that a later @keyframes with the same name replaces.
"""
import re, sys

def strip_comments(s):
    out, i, n, q = [], 0, len(s), None
    while i < n:
        c = s[i]
        if q:
            out.append(c)
            if c == '\\' and i + 1 < n: out.append(s[i+1]); i += 2; continue
            if c == q: q = None
            i += 1; continue
        if c in '"\'': q = c; out.append(c); i += 1; continue
        if s.startswith('/*', i):
            j = s.find('*/', i + 2); i = n if j < 0 else j + 2; continue
        out.append(c); i += 1
    return ''.join(out)

def parse(s, i=0):
    """Returns (nodes, end). Node: ('rule', prelude, body) for a block without nested blocks, ('block', prelude, nodes)
    for @media/@supports/@layer..., ('raw', text) for statements like @import or @charset."""
    nodes, buf, n, q = [], [], len(s), None
    while i < n:
        c = s[i]
        if q:
            buf.append(c)
            if c == '\\' and i + 1 < n: buf.append(s[i+1]); i += 2; continue
            if c == q: q = None
            i += 1; continue
        if c in '"\'': q = c; buf.append(c); i += 1; continue
        if c == ';' and ''.join(buf).strip().startswith('@'):
            nodes.append(('raw', ''.join(buf).strip() + ';')); buf = []; i += 1; continue
        if c == '{':
            pre = ' '.join(''.join(buf).split()); buf = []
            if re.match(r'@(media|supports|layer|container|document)\b', pre):
                kids, i = parse(s, i + 1); nodes.append(('block', pre, kids)); continue
            # a plain block (rules, @keyframes, @font-face, @page ...): read to the matching brace
            depth, j, q2 = 1, i + 1, None
            while j < n and depth:
                d = s[j]
                if q2:
                    if d == '\\': j += 2; continue
                    if d == q2: q2 = None
                elif d in '"\'': q2 = d
                elif d == '{': depth += 1
                elif d == '}': depth -= 1
                j += 1
            nodes.append(('rule', pre, s[i+1:j-1])); i = j; continue
        if c == '}':
            return nodes, i + 1
        buf.append(c); i += 1
    return nodes, i

def split_decls(body):
    out, buf, q, depth = [], [], None, 0
    for k, c in enumerate(body):
        if q:
            buf.append(c)
            if c == q and body[k-1] != '\\': q = None
            continue
        if c in '"\'': q = c
        elif c == '(': depth += 1
        elif c == ')': depth -= 1
        if c == ';' and depth == 0:
            if ''.join(buf).strip(): out.append(''.join(buf).strip())
            buf = []; continue
        buf.append(c)
    if ''.join(buf).strip(): out.append(''.join(buf).strip())
    return out

def tidy_value(v):
    v = ' '.join(v.split())
    v = re.sub(r'\s*,\s*', ',', v) if 'url(' not in v and '"' not in v and "'" not in v else v
    return v

def decl_parts(d):
    if ':' not in d: return None
    p, v = d.split(':', 1)
    p = p.strip(); v = v.strip()
    imp = bool(re.search(r'!\s*important\s*$', v, re.I))
    return p.lower(), tidy_value(v), imp

NEWER = re.compile(r'dvh|svh|lvh|dvw|color-mix|env\(|-webkit-|-moz-|-ms-|clamp\(|min\(|max\(|:has|oklch|oklab|lab\(|lch\(|fit-content|inset|aspect-ratio|gap|backdrop', re.I)

def is_plain_rule(pre):
    return not pre.startswith('@')

def dedupe(nodes, ctx, seen):
    """Walk from the end: remember (context, selector, property) already set later on."""
    for idx in range(len(nodes) - 1, -1, -1):
        nd = nodes[idx]
        if nd[0] == 'block':
            dedupe(nd[2], ctx + (nd[1],), seen)
            continue
        if nd[0] != 'rule' or not is_plain_rule(nd[1]) or '{' in nd[2]: continue
        sel = nd[1]; ds = split_decls(nd[2]); keep = []
        later = seen.setdefault((ctx, sel), {})
        here = set()
        for d in reversed(ds):
            pv = decl_parts(d)
            if not pv: keep.append(d); continue
            p, v, imp = pv
            if p in here:            # the same property twice in one rule: a fallback, keep both
                keep.append(d); continue
            if p in later:
                lv, limp = later[p]
                if (limp or not imp) and not NEWER.search(lv) and not p.startswith('--'):
                    continue         # always overridden by the later rule
            keep.append(d); here.add(p)
        for d in keep:
            pv = decl_parts(d)
            if pv and pv[0] not in later: later[pv[0]] = (pv[1], pv[2])
            elif pv and pv[2] and not later[pv[0]][1]: later[pv[0]] = (pv[1], pv[2])
        nodes[idx] = ('rule', sel, ';'.join(reversed(keep)))

def drop_old_keyframes(nodes, seen=None):
    seen = set() if seen is None else seen
    for idx in range(len(nodes) - 1, -1, -1):
        nd = nodes[idx]
        if nd[0] == 'block': continue   # @keyframes inside @media: leave as is
        m = re.match(r'@(-webkit-)?keyframes\s+(\S+)', nd[1]) if nd[0] == 'rule' else None
        if m:
            k = (m.group(1) or '') + m.group(2)
            if k in seen: nodes[idx] = None
            else: seen.add(k)
    nodes[:] = [x for x in nodes if x is not None]

def minify_body(body, nested):
    if nested:   # @keyframes / @font-face with inner blocks: just squeeze spaces
        b = ' '.join(body.split())
        b = re.sub(r'\s*([{};:,])\s*', r'\1', b)
        return b
    return ';'.join(d if ('"' in d or "'" in d) else ' '.join(d.split()).replace(': ', ':', 1) for d in split_decls(body))

def emit(nodes):
    out = []
    for nd in nodes:
        if nd[0] == 'raw': out.append(nd[1]); continue
        if nd[0] == 'block':
            inner = emit(nd[2])
            if inner: out.append(nd[1] + '{' + inner + '}')
            continue
        pre, body = nd[1], nd[2]
        nested = '{' in body
        b = minify_body(body, nested)
        if not b.strip() and is_plain_rule(pre): continue
        sel = re.sub(r'\s*,\s*', ',', pre) if '"' not in pre and "'" not in pre else pre
        sel = re.sub(r'\s*>\s*', '>', sel) if '"' not in sel and "'" not in sel else sel
        out.append(sel + '{' + b + '}')
    return ''.join(out)

def main():
    path = sys.argv[1]; check = '--check' in sys.argv
    src = open(path, encoding='utf-8').read()
    nodes, _ = parse(strip_comments(src))
    dedupe(nodes, (), {})
    drop_old_keyframes(nodes)
    out = emit(nodes) + '\n'
    print(f'{path}: {len(src.encode()):,} -> {len(out.encode()):,} bytes')
    if not check: open(path, 'w', encoding='utf-8').write(out)

if __name__ == '__main__':
    main()
