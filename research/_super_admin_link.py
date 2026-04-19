#!/usr/bin/env python3
"""v3.114 — add Super Admin shortcut to each menu. Only renders when
<body data-is-master="1"> via the .requires-master CSS gate."""

# Desktop More dropdown + mobile off-canvas on index.html
idx_path = '/var/www/transcribe/index.html'
s = open(idx_path).read()

# Desktop: insert right BEFORE the feedback divider (so Super Admin is above
# the divider, Feedback below)
desktop_anchor = '<div class="header-more-divider"></div>'
desktop_new = '''<a href="/admin/" class="header-more-item requires-master" title="Super Admin Dashboard">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            Super Admin
                        </a>
                        <div class="header-more-divider"></div>'''
if 'requires-master' in s and 'Super Admin' in s:
    print('desktop: super admin already present')
else:
    # Replace only the FIRST divider to target Feedback's one (others shouldn't exist today)
    s = s.replace(desktop_anchor, desktop_new, 1)
    print('desktop: super admin link added to More dropdown')

# Off-canvas: insert BEFORE the feedback button
oc_anchor = '''<button class="offcanvas-item" data-target="feedbackBtn">'''
oc_new = '''<a class="offcanvas-item requires-master" href="/admin/">
                <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                <span>Super Admin</span>
                <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <button class="offcanvas-item" data-target="feedbackBtn">'''
if 'Super Admin</span>' in s:
    print('offcanvas: super admin already present')
else:
    s = s.replace(oc_anchor, oc_new, 1)
    print('offcanvas: super admin item inserted')

open(idx_path, 'w').write(s)

# Do the same for report.php and quiz-report.php off-canvas blocks. Both use
# <a class="offcanvas-item" href="/index.html#..."> links now.
for php_path in ('/var/www/transcribe/api/report.php', '/var/www/transcribe/api/quiz-report.php'):
    s = open(php_path).read()
    anchor = '<a class="offcanvas-item" href="/index.html#feedback">'
    new = '''<a class="offcanvas-item requires-master" href="/admin/">
            <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            <span>Super Admin</span>
            <svg class="offcanvas-arrow" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a class="offcanvas-item" href="/index.html#feedback">'''
    if 'Super Admin</span>' in s:
        print(f'{php_path}: already present')
    elif anchor in s:
        s = s.replace(anchor, new, 1)
        open(php_path, 'w').write(s)
        print(f'{php_path}: super admin link inserted before feedback')
    else:
        print(f'{php_path}: anchor miss')

print('DONE')
