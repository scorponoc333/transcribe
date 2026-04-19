#!/usr/bin/env python3
"""v3.86 — show the bottom action bar (PDF + Share) on the PUBLIC
view of report.php (and keep it on private). Quiz-report already
exists without a $logged guard so nothing to move there, but we
wire the share button so public viewers get a mailto: fallback.

Changes in report.php:
  * Move the closing <?php endif; ?> so only the hamburger + drawer
    stay logged-only. The bottom bar + email lightbox render for
    everyone.
  * The lightbox's send handler now branches: logged users go
    through the API flow; public viewers get a mailto: URL opened
    in their default mail app (the URL + body + subject baked in).

Changes in quiz-report.php:
  * Same branching in qrShareSend so public viewers get a mailto:
    with a link to the quiz-report URL.
"""
rp = '/var/www/transcribe/api/report.php'
s = open(rp).read()

# 1) Move the </aside> of the drawer to be followed by <?php endif; ?>.
#    Then the bottom bar + lightbox become unconditional.
#    We look for the specific structure: </aside>\n\n<!-- ═══════ v3.56 — MOBILE: floating bottom action bar ═══════ -->
split_point = '</aside>\n\n<!-- ═══════ v3.56 — MOBILE: floating bottom action bar ═══════ -->'
split_replace = '</aside>\n<?php endif; /* $logged */ ?>\n\n<!-- ═══════ v3.56 — MOBILE: floating bottom action bar ═══════ -->'
if '<?php endif; /* $logged */ ?>' in s:
    print('1) already split')
elif split_point in s:
    s = s.replace(split_point, split_replace, 1)
    print('1) closed $logged block before bottom bar')
else:
    print('1) WARN split anchor not found')

# 2) Remove the now-duplicate <?php endif; ?> that sits after the lightbox.
old_close = """    </div>
</div>
<?php endif; ?>

<style id=\"v356ReportMobile\">"""
new_close = """    </div>
</div>

<style id=\"v356ReportMobile\">"""
if old_close in s:
    s = s.replace(old_close, new_close, 1)
    print('2) removed trailing endif after lightbox')
else:
    print('2) trailing endif already removed or anchor miss')

# 3) Tag the body with a flag so JS knows if we're in public-viewer mode.
#    We write it via <script> near the existing script block.
flag_script = """
<script id=\"v386PublicFlag\">
window.rpIsLogged = <?= $logged ? 'true' : 'false' ?>;
</script>
"""
if 'window.rpIsLogged' in s:
    print('3) public flag already present')
else:
    # Put right before the existing v3.56 glue script
    marker = '<script>\n/* ═══════ v3.56 — mobile glue ═══════ */'
    if marker in s:
        s = s.replace(marker, flag_script + '\n' + marker, 1)
        print('3) public flag script injected before glue')
    else:
        print('3) WARN marker not found')

# 4) Patch rpShareSend to branch on rpIsLogged.
old_send_head = """    window.rpShareSend = async function () {
        const to = document.getElementById('rpEmailTo')?.value.trim();
        const subject = document.getElementById('rpEmailSubject')?.value.trim();
        const note = document.getElementById('rpEmailMsg')?.value.trim();
        const btn = document.getElementById('rpEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }"""
new_send_head = """    window.rpShareSend = async function () {
        const to = document.getElementById('rpEmailTo')?.value.trim();
        const subject = document.getElementById('rpEmailSubject')?.value.trim();
        const note = document.getElementById('rpEmailMsg')?.value.trim();
        const btn = document.getElementById('rpEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }

        // Public viewers can't call send-smtp.php (requires auth).
        // Open their mail app instead with the URL pre-filled.
        if (!window.rpIsLogged) {
            const publicUrl = window.location.href;
            const reportTitle = <?= json_encode($title) ?>;
            const body = (note ? (note + '\\n\\n') : 'I thought you would find this report useful.\\n\\n')
                + 'View the full report here:\\n' + publicUrl;
            const mailto = 'mailto:' + encodeURIComponent(to)
                + '?subject=' + encodeURIComponent(subject || ('Transcription | ' + reportTitle))
                + '&body=' + encodeURIComponent(body);
            window.location.href = mailto;
            rpShareClose();
            return;
        }"""
if 'Public viewers can' in s:
    print('4) share branch already in place')
elif old_send_head in s:
    s = s.replace(old_send_head, new_send_head, 1)
    print('4) rpShareSend now branches for public viewers (mailto)')
else:
    print('4) WARN rpShareSend head anchor not found')

open(rp, 'w').write(s)

# ── Quiz-report: same mailto branch ──
qr = '/var/www/transcribe/api/quiz-report.php'
q = open(qr).read()

# We don't have a $logged concept on quiz-report.php, but the same
# send-smtp.php call requires auth. Feature-detect by whether the
# /api/transcription-share.php POST succeeds — or just always try
# the mailto fallback for simplicity when the API call fails with
# 401/403. Simpler: unconditional branch on whether session cookie
# is present by checking document.cookie for PHPSESSID presence.

old_qr_head = """    window.qrShareSend = async function () {
        const to = document.getElementById('qrEmailTo')?.value.trim();
        const subject = document.getElementById('qrEmailSubject')?.value.trim();
        const note = document.getElementById('qrEmailMsg')?.value.trim();
        const btn = document.getElementById('qrEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }
        if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }"""
new_qr_head = """    window.qrShareSend = async function () {
        const to = document.getElementById('qrEmailTo')?.value.trim();
        const subject = document.getElementById('qrEmailSubject')?.value.trim();
        const note = document.getElementById('qrEmailMsg')?.value.trim();
        const btn = document.getElementById('qrEmailSendBtn');
        if (!to) { alert('Please enter a recipient email.'); return; }

        // No session cookie -> use mailto: fallback so public viewers
        // can share the quiz-report URL with no backend call.
        const hasSession = /PHPSESSID=/.test(document.cookie) || /transcribe_session=/.test(document.cookie);
        if (!hasSession) {
            const quizUrl = window.location.href;
            const body = (note ? (note + '\\n\\n') : 'Sharing a pop quiz report.\\n\\n') + 'Open the quiz report here:\\n' + quizUrl;
            const mailto = 'mailto:' + encodeURIComponent(to)
                + '?subject=' + encodeURIComponent(subject || 'Pop Quiz Report')
                + '&body=' + encodeURIComponent(body);
            window.location.href = mailto;
            qrShareClose();
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }"""
if 'No session cookie -> use mailto' in q:
    print('qr) share branch already in place')
elif old_qr_head in q:
    q = q.replace(old_qr_head, new_qr_head, 1)
    open(qr, 'w').write(q)
    print('qr) qrShareSend public-viewer mailto fallback added')
else:
    print('qr) WARN qrShareSend head anchor not found')

print('DONE')
