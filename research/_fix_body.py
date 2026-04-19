#!/usr/bin/env python3
"""v3.114 — revert body-tag injection from inside the error template
and apply it to the real main body instead."""
p = '/var/www/transcribe/api/report.php'
s = open(p).read()

# 1) Revert the bad replacement inside the render_error echo block
bad_substring = '</head><body data-role="<?= e(getCurrentOrgRole() ?: \'guest\') ?>" data-is-master="<?= isMasterAdmin() ? \'1\' : \'0\' ?>">'
if bad_substring in s:
    s = s.replace(bad_substring, '</head><body>', 1)
    print('reverted error-template body')

# 2) Replace the real <body> (outside PHP string literals).
# It lives on its own line somewhere around line 2312.
# Pattern: a line that is exactly "<body>\n"
old_line = '\n<body>\n'
new_line = '\n<body data-role="<?= e(getCurrentOrgRole() ?: \'guest\') ?>" data-is-master="<?= isMasterAdmin() ? \'1\' : \'0\' ?>">\n'
if new_line not in s:
    s = s.replace(old_line, new_line, 1)
    print('real <body> now carries role attrs')
else:
    print('real body already patched')

open(p, 'w').write(s)
