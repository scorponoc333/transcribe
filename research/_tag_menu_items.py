#!/usr/bin/env python3
"""v3.114 — tag menu items across index.html / report.php / quiz-report.php
with role-gating classes, and append the CSS gate rules to style.css.

Classes:
  .requires-admin         — only tenant admins or master super-admin
  .requires-manager-plus  — admin, manager, or master (hides for editor)
  .requires-master        — master super-admin only

Items:
  Transcribe      — no class (all roles)
  Reports         — no class
  History         — no class
  Analytics       — requires-manager-plus
  Contacts        — no class
  Users           — requires-admin
  Feedback        — no class
  Settings gear   — requires-manager-plus
  Super Admin     — requires-master (new item — link to /api/admin/dashboard.php)
"""
import re

# ── CSS rules ────────────────────────────────────────────────────
CSS = """

/* ═══════════════════════════════════════════════════════════════
   v3.114 — role-based menu gating.
   <body> carries data-role="admin|manager|editor" and
   data-is-master="0|1" from the server. Items tagged with the
   classes below are hidden when the body state doesn't qualify.
   ═══════════════════════════════════════════════════════════════ */
body:not([data-is-master="1"]) .requires-master {
    display: none !important;
}
body:not([data-role="admin"]):not([data-is-master="1"]) .requires-admin {
    display: none !important;
}
body[data-role="editor"]:not([data-is-master="1"]) .requires-manager-plus {
    display: none !important;
}
"""

# ── Helper: add a class token to an attribute value if missing ──
def add_class(html, anchor_substring, new_class):
    """Find anchor_substring (which contains a class=".."), append new_class
    to its class list if not already present."""
    idx = html.find(anchor_substring)
    if idx < 0:
        return html, False
    # Find the class="..." in this tag
    start = html.rfind('<', 0, idx)
    end = html.find('>', idx) + 1
    tag = html[start:end]
    m = re.search(r'class="([^"]*)"', tag)
    if not m:
        return html, False
    classes = m.group(1).split()
    if new_class in classes:
        return html, False
    classes.append(new_class)
    new_tag = tag[:m.start()] + f'class="{" ".join(classes)}"' + tag[m.end():]
    return html[:start] + new_tag + html[end:], True


# ── Patch index.html ──
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()

# Desktop More dropdown items (around lines 99-119). Each is a <button class="header-more-item">.
# Off-canvas items (around lines 155-200). Each is a <button class="offcanvas-item" or <a class="offcanvas-item">.
# Add role classes to Analytics, Users, Settings, and add a new Super Admin entry.

# Patches apply to the FIRST match on each anchor substring — sufficient here
# because each menu item appears exactly once per menu with distinct data-target.

# Desktop More: Analytics button
s, _ = add_class(s, 'data-target="analyticsBtn"',     None) if False else (s, False)  # placeholder
# Let's just do direct tagging through regex passes keyed on data-target / href / data-stab

patches = [
    # desktop items
    (r'id="analyticsBtn"',       'requires-manager-plus'),
    (r'id="usersBtn"',           'requires-admin'),
    (r'id="settingsBtn"',        'requires-manager-plus'),
    (r'id="reportPagesBtn"',     None),
    # offcanvas items use data-target or href
    (r'data-target="analyticsBtn"', 'requires-manager-plus'),
    (r'data-target="usersBtn"',  'requires-admin'),
    (r'data-target="settingsBtn"', 'requires-manager-plus'),
    (r'href="/index.html#analytics"', 'requires-manager-plus'),
    (r'href="/index.html#users"',     'requires-admin'),
    (r'href="/index.html#settings"',  'requires-manager-plus'),
    (r'id="offcanvasSettings"',       'requires-manager-plus'),
]

for pat, cls in patches:
    if cls is None:
        continue
    for m in list(re.finditer(pat, s)):
        tag_start = s.rfind('<', 0, m.start())
        tag_end   = s.find('>', m.end()) + 1
        tag = s[tag_start:tag_end]
        cm = re.search(r'class="([^"]*)"', tag)
        if not cm:
            # insert class= before the >
            new_tag = tag[:-1] + f' class="{cls}">'
            s = s[:tag_start] + new_tag + s[tag_end:]
            continue
        existing = cm.group(1).split()
        if cls in existing:
            continue
        existing.append(cls)
        new_tag = tag[:cm.start()] + f'class="{" ".join(existing)}"' + tag[cm.end():]
        s = s[:tag_start] + new_tag + s[tag_end:]

open(idx_path, 'w').write(s)
print('index.html: role classes tagged')

# ── Patch report.php off-canvas items (inline from v3.89) ──
for php_path in ('/var/www/transcribe/api/report.php', '/var/www/transcribe/api/quiz-report.php'):
    s = open(php_path).read()
    for pat, cls in [
        (r'href="/index.html#analytics"', 'requires-manager-plus'),
        (r'href="/index.html#users"',     'requires-admin'),
        (r'href="/index.html#settings"',  'requires-manager-plus'),
        (r'id="offcanvasSettings"',       'requires-manager-plus'),
    ]:
        for m in list(re.finditer(pat, s)):
            tag_start = s.rfind('<', 0, m.start())
            tag_end   = s.find('>', m.end()) + 1
            tag = s[tag_start:tag_end]
            cm = re.search(r'class="([^"]*)"', tag)
            if not cm:
                new_tag = tag[:-1] + f' class="{cls}">'
                s = s[:tag_start] + new_tag + s[tag_end:]
                continue
            existing = cm.group(1).split()
            if cls in existing:
                continue
            existing.append(cls)
            new_tag = tag[:cm.start()] + f'class="{" ".join(existing)}"' + tag[cm.end():]
            s = s[:tag_start] + new_tag + s[tag_end:]
    open(php_path, 'w').write(s)
    print(f'{php_path}: role classes tagged')

# ── Append CSS rules to style.css ──
css_path = '/var/www/transcribe/css/style.css'
c = open(css_path).read()
if 'v3.114 — role-based menu gating' in c:
    print('css/style.css: already tagged')
else:
    c = c.rstrip() + CSS
    open(css_path, 'w').write(c)
    print('css/style.css: role gating rules appended')

print('DONE')
