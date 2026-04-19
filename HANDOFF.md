**Active Plan:** C:/Users/User/.claude/plans/i-need-to-come-pure-toucan.md

# Jasonai Platform — Session Handoff

> **For Claude Code:** If you're a new session picking this up, read this file FIRST,
> then read the memory files at `C:\Users\User\.claude\projects\C--xampp-htdocs-transcribe\memory\MEMORY.md`
> for full context. This file tells you WHERE WE ARE. Memory tells you WHO Jason is and WHAT we're building.

## Last updated: 2026-04-19 (post-SSO)

## 2026-04-19 session snapshot #5 — SSO EXCHANGE WIRED — READ FIRST IF RESUMING

### What shipped
Cross-subdomain SSO is live. User logs in at `app.jasonai.ca`, clicks a tool tile, lands in the tool already authenticated. Demonstrated end-to-end between hub → transcribe.

### Transcribe side (droplet /var/www/transcribe/)
- `api/_jwt.php` (new) — HS256 verify with constant-time sig compare + exp enforcement
- `api/sso-exchange.php` (new) — `?t=<jwt>[&r=<returnPath>]`. Verifies with shared secret from `/etc/jai-transcribe/secrets.env` key `JWT_SECRET`. Finds user by lowercased email OR auto-provisions (new org + admin user; hub tier→transcribe org plan mapping: personal→solo, professional→team, enterprise→enterprise, comp→enterprise, else→trial). Mints PHP session, redirects to `r` or `/`.
- `/etc/jai-transcribe/secrets.env` — added `JWT_SECRET=` matching hub's `jwt_secret`.

### Hub side (app.jasonai.ca)
- `assets/hub.js` — new helpers `getCookie(name)` + `buildSsoUrl(toolUrl)`. Access button on the app detail page now wraps the tool URL as `{tool}/api/sso-exchange.php?t=<jasonai_jwt>`. Only applies when URL matches `*.jasonai.ca` (skips dev_url/localhost to avoid token leakage). Falls back to bare URL if JWT cookie absent.
- Deployed via scp to `/home/customer/www/app.jasonai.ca/public_html/assets/hub.js`.

### E2E verified
- **Existing user path** (jasonhogan333@gmail.com): token issued → sso-exchange returned 302 → session.php showed `user_id:1, is_master_admin:true`. No duplicate row created. `last_login_at` bumped.
- **Auto-provision path** (sso-test-<ts>@example.com): new org `SSO Test User's Team` plan=`team` + new admin user created. Test data cleaned up after.
- Invalid/expired JWT → returns "Invalid or expired sign-in link" page (styled dark, matches tool aesthetic).
- Returns `r` path is validated (only allows `/...` paths, not arbitrary redirects — prevents open-redirect).

### How Jason tests manually
1. Open `https://app.jasonai.ca/` — should redirect to auth.html (not logged in yet)
2. Click "Forgot password", enter `jasonhogan333@gmail.com`, check email for reset link
3. Set password, log in
4. Click Transcribe tile → detail page → "Open Transcribe" button → should land inside transcribe WITHOUT a second login prompt

### GitHub commits (this session)
- scorponoc333/jasonai-hub@d189070 — hub.js SSO handoff
- scorponoc333/jasonai-landing — initial commit (landing page)
- scorponoc333/transcribe@d8dc3f8 — `research/api/_jwt.php` + `research/api/sso-exchange.php`

### Outstanding next-sprint options
1. **Seat-cap enforcement** on transcribe `api/users.php` — 402 + upsell modal. Upgrade URL now points at a live hub (can deep-link to `app.jasonai.ca/?upgrade=transcribe`).
2. **First tool fork** via `/base-fork` skill — invoice.jasonai.ca. Second live tool alongside transcribe.
3. **Stripe wiring** — replace hub's `sk_test_XXXX` stubs with real keys after creating products + prices in Stripe dashboard.
4. **Silent SSO refresh** — today SSO only fires via explicit tile click. Could check cookie on tool's page load (`_bootstrap.php`) and trigger SSO exchange automatically if logged into hub but not tool. Polish.
5. **Hub → tool logout propagation** — per seats_and_billing memory, mismatch nonce every 30s. Not wired.

---

## Last updated: 2026-04-19 (late late night, post-hub-deploy)

## 2026-04-19 session snapshot #4 — HUB LIVE AT https://app.jasonai.ca/ — READ FIRST IF RESUMING

### What shipped
Hub MVP deployed to SG subdomain `app.jasonai.ca`. Auth + dashboard + app catalog (9 apps) + admin + welcome wizard + settings all live. SSO cookies configured for cross-subdomain with `Domain=.jasonai.ca` + `SameSite=None`. CORS wired so landing page + tools can call the hub with credentials.

### Hub deploy specifics
- **URL**: https://app.jasonai.ca/ (200, served from SG + Cloudflare-proxied)
- **SG docroot**: `/home/customer/www/app.jasonai.ca/public_html/` (new subdomain, docroot auto-created by SG)
- **DB**: same as landing — `dbgxecucfghbgg` on SG localhost. Schema imported: 12 `research_*` tables coexist with the 2 `jasonai_blog_*` tables. No collisions.
- **Owner seeded**: uid=1, email `jasonhogan333@gmail.com`, password `!pending-set-password`. **Password reset link was sent to Jason's inbox** (reset_token generated, expires ~1hr from deploy).
- **Config**: `/home/customer/www/app.jasonai.ca/public_html/config.php`, chmod 600, includes `cookie_domain='.jasonai.ca'` + `jwt_secret` shared with transcribe.

### Pre-deploy code fixes that landed in `C:\xampp\htdocs\jasonai-hub\`
- `api/db.php` — config-loader refactor (reads `db_host`/`db_name`/`db_user`/`db_pass` from config)
- `api/_bootstrap.php` — CORS block allowing `*.jasonai.ca` + localhost origins, handles OPTIONS preflight with full preflight headers + `Vary: Origin`
- `api/auth.php` — new `auth_cookie_params()` helper. When `config.cookie_domain` set, cookies use `Domain=.jasonai.ca` + `SameSite=None + Secure`. On localhost: no domain + `SameSite=Lax`. Applied to `research_sid` session cookie AND `jasonai_jwt` JWT cookie.
- `api/schema.sql` — owner seed email corrected to `jasonhogan333@gmail.com`
- `config.example.php` — rewritten with `db_*`, `cookie_domain`, `landing_url`, hub-specific defaults

### Live verification (passed)
- https://app.jasonai.ca/ → 200 (index.html dashboard shell)
- /auth.html, /admin.html, /reset.html → 200
- /api/auth.php?action=me → `{"success":true,"user":null}`
- /api/apps.php → JSON with 9 apps, Transcribe first
- POST /api/auth.php?action=register (throwaway email) → `{"success":true,"message":"Account created..."}` + row inserted in DB
- POST /api/auth.php?action=reset-request for `jasonhogan333@gmail.com` → success + `reset_token` column populated in `research_users`
- OPTIONS preflight from `Origin: https://jasonai.ca` → 204 with all ACAO/ACAC/ACAM headers
- GET from `Origin: https://jasonai.ca` (cache-busted) → CORS headers present + `Vary: Origin` to keep CF cache correct

### Jason's to-do on his side
1. **Check jasonhogan333@gmail.com inbox** for the password reset email from `hello@jasonai.ca`. Click the link to set his real password and log in to the hub.
2. (Later) Set up **Stripe** products/prices + paste real secrets into hub `config.php`. Stripe keys are stubs right now; checkout endpoints will 500 until replaced.

### Side deliverables this session
- New SiteGround SSH skill: `/siteground-ssh-setup` (reusable for future SG sites)
- WP backup dir (417MB) deleted from SG as confirmed abandoned
- Memory updated: [jasonai_landing_deployment.md](memory/jasonai_landing_deployment.md), [jason_custom_skills.md](memory/jason_custom_skills.md)

### Outstanding (next sprint's options)
1. **Seat-cap enforcement on transcribe** — 402 + upsell modal, with upgrade URL now pointing at the real live hub at `app.jasonai.ca/?upgrade=<slug>` (not dead anymore). Medium sprint.
2. **First tool fork** — use `/base-fork` to spin up e.g. invoice.jasonai.ca. Gives you a second live tool besides transcribe.
3. **Stripe wiring** — create products/prices in Stripe, replace config stubs, test checkout flow end-to-end. Unblocks actual paid signups.
4. **Hub polish** — welcome wizard tested + onboarding UX, admin panel live testing, app detail pages.
5. **Transcribe sub-app SSO exchange** — wire `/api/sso-exchange.php` on transcribe so clicking a tile on the hub deep-links with a short JWT and auto-logs the user in. Right now hub tile opens transcribe but user has to log in again.
6. **301 redirect** `transcribe.jasonhogan.ca → transcribe.jasonai.ca` — still deferred.

---

## Last updated: 2026-04-19 (deeply late night, post-landing-deploy)

## 2026-04-19 session snapshot #3 — LANDING PAGE LIVE AT https://jasonai.ca/ — READ FIRST IF RESUMING

### What shipped
`https://jasonai.ca/` now serves the Jason AI landing page from SiteGround. 9 tool tiles (Transcribe first), state-aware CTAs, blog infra wired, hub-sign-in link points at `app.jasonai.ca` (not yet deployed → graceful stub).

### SiteGround deploy specifics
- **SSH**: `ssh.jasonai.ca:18765`, user `u2721-vn2sjvqllhhc`, key `C:\Users\User\.ssh\jasonai_sg` (ed25519). Imported via SG Site Tools → SSH Keys Manager. Cloudflare `ssh` A record is **DNS-only** (unproxied) — required for port 18765 reachability.
- **Document root**: `/home/customer/www/jasonai.ca/public_html/` (same as `~/public_html`).
- **PHP**: 8.2.30 (ZTS).
- **Site IP**: `35.208.47.198` (Cloudflare-proxied A record on `jasonai.ca` and `www.jasonai.ca`). No DNS cutover needed — root already pointed at SG.
- **MySQL**: reused the pre-existing `dbgxecucfghbgg` (DB that WP used). Dropped 73 `rmd_*` WP tables. Added `jasonai_blog_posts` + `jasonai_blog_related`. User `u8hbcleq7git2` / `akccwmad5l5e`, host `127.0.0.1`.
- **Server config**: `/home/customer/www/jasonai.ca/public_html/config.php` (chmod 600, never FTP'd — written server-side only). Contains OpenRouter + Fal.ai + EmailIt + JWT secret + DB creds.
- **WP backup**: **DELETED** — `public_html_wp_backup_20260419_195618/` cleared after Jason confirmed the WordPress install was abandoned. 417MB freed.

### Pre-deploy code fixes landed in `C:\xampp\htdocs\jasonai-landing\`
- `api/blog.php` — Fal.ai key now read from `$cfg['fal_api_key']` (was hardcoded `F:/home/...` Windows path)
- `api-shared/db.php` — reads DB creds from config.php (was hardcoded root/empty)
- 5 HTML sign-in links → `https://app.jasonai.ca/auth.html` (with localhost override in `js/loader.js`)
- `apps/apps.js` — admin bar localhost-only, HUB_BASE constant added
- `blog/blog.js` — admin bar localhost-only (prod hub not live yet)
- `tools/tools-data.json` — all 9 `appUrl` entries → `https://{slug}.jasonai.ca`
- `config.example.php` — new template with `fal_api_key`, `db_*`, `hub_url` keys
- Local `config.php` — added `fal_api_key` + `db_*` for dev parity (NEVER ships)

### Live URLs verified (all 200)
- https://jasonai.ca/
- https://jasonai.ca/apps/
- https://jasonai.ca/blog/
- https://jasonai.ca/tools/?tool=transcribe
- https://jasonai.ca/api/tools.php (JSON, 9 tools)
- https://jasonai.ca/api/blog.php (JSON, 0 posts)
- https://jasonai.ca/img/tool-transcribe.jpg (58KB Fal image)

### Outstanding / next sprint
1. **Hub `app.jasonai.ca` deploy** — landing's Sign in + CTA all point here; currently 404. Priority.
2. **Remaining tool subdomains** — tools-data.json points at `https://{slug}.jasonai.ca`; only `transcribe.jasonai.ca` is live. Rest 404 until those forks ship.
3. **Blog admin** — shipped with admin UI gated to localhost-only. Hub-session-based admin validation will come when hub is live.
4. **FTP via `ftp.jasonai.ca`** — currently Cloudflare-proxied so it doesn't work externally. Left as-is; SSH covers deploy. Toggle to DNS-only later if FTP ever needed.
5. **Seat-cap enforcement** on transcribe (HANDOFF item from snapshot #2) — still outstanding.
6. **Stripe webhook URL** on transcribe — still `transcribe.jasonhogan.ca`; flip to `transcribe.jasonai.ca` when canonical.

### Skill added
- `C:\Users\User\.claude\skills\siteground-ssh-setup\` — reusable skill for wiring SSH access to any SG site. Generates keypair, gives Jason the public key + Cloudflare DNS-unproxy instructions.

---

## Last updated: 2026-04-19 (very late night, post-migration)

## 2026-04-19 session snapshot #2 — transcribe migrated + landing wired — READ FIRST IF RESUMING

### What shipped this session
Transcribe is now reachable at **both** `transcribe.jasonhogan.ca` AND `transcribe.jasonai.ca` (dual-host, not cutover). Same droplet (165.22.237.23), same nginx server block, cert covers both. App is host-aware — any endpoint that used to hardcode `transcribe.jasonhogan.ca` now reads `$_SERVER['HTTP_HOST']`.

**Migration artifacts:**
- DNS: Cloudflare A record `transcribe.jasonai.ca → 165.22.237.23`, DNS-only (unproxied). Added manually by Jason (cloud.env token didn't have Zone:DNS:Edit scope).
- Nginx: `/etc/nginx/sites-available/transcribe.jasonhogan.ca` — `server_name` on both :80 and :443 blocks now lists both hosts. Backup: `.bak_1776622988`.
- Cert: `certbot --nginx --expand` — one cert now covers both hostnames, expires 2026-07-18.
- App URL fixes (7 files edited, backups tagged `.bak_1776622662`):
  - `api/stripe-checkout.php` — successUrl + cancelUrl now use HTTP_HOST
  - `api/stripe-success.php` — Sign In link relativized
  - `api/feedback.php` — email footer uses HTTP_HOST
  - `api/ai-chat.php` + `api/classify-content.php` — OpenRouter HTTP-Referer uses HTTP_HOST
  - `api/send-smtp.php` — fallback default flipped to transcribe.jasonai.ca
  - `js/email-template.js` — heroBase flipped to transcribe.jasonai.ca (canonical for outbound emails)

**Landing + hub integration:**
- Fal.ai image generated: `C:\xampp\htdocs\jasonai-landing\img\tool-transcribe.jpg` (cinematic navy/cyan, microphone + waveform)
- `tools-data.json` — Transcribe inserted as first entry (color `#0d9488`, icon `fa-microphone-lines`, appUrl `https://transcribe.jasonai.ca`)
- `index.html` carousel — Transcribe tile added as first (+ duplicate loop)
- `js/landing.js` — apps array prepended with Transcribe
- `tools/tools.js` — **3-state CTA** wired for ALL tools (not just Transcribe). Calls hub `/api/auth.php?action=me` + `/api/apps.php` to decide: Start Free Trial / Open {Tool} / Add to Plan. Uses HUB_BASE constant that flips to `app.jasonai.ca` when not on localhost.
- Hub: `C:\xampp\htdocs\jasonai-hub\api\apps.json` — Transcribe entry added (first). Cover at `app-assets/transcribe-cover.jpg` (reuses Fal image — first tool to have a cover; others still missing).

### Outstanding / still TODO

1. **Stripe webhook URL** — when canonical URL flips, Jason must update Stripe Dashboard → Developers → Webhooks endpoint from `transcribe.jasonhogan.ca` to `transcribe.jasonai.ca`. Both work right now.
2. **Seat-cap enforcement** (HANDOFF item 5, deferred this session) — `api/users.php` + new `organizations.seats_included`/`seats_used` migration + 402 handler + upsell modal. Next session.
3. **301 redirect** `transcribe.jasonhogan.ca → transcribe.jasonai.ca` — add after a couple weeks of dual-host so any bookmarks/email links land.
4. **Landing page deploy to SiteGround** — still local. HANDOFF item 6.
5. **Hub `app.jasonai.ca` deploy** — still localhost only. CTA's HUB_BASE auto-detects, so nothing to change once deployed.
6. **Stripe products** — still stubbed; `Add to Plan` CTA points at `app.jasonai.ca/?upgrade=<slug>` which is a dead link for now.
7. **Transcribe SSO token** — CTA currently deep-links to tool appUrl without a short-TTL JWT. The cross-subdomain session exchange described in `memory/jasonai_seats_and_billing.md` section "Cross-subdomain session (SSO)" is not wired yet.

### Test accounts (unchanged from session #1)
- Jason: jasonhogan333@gmail.com / JasonTest2026! (uid=1, admin + is_super_admin=1, org=1)
- Acme Admin: uid=4, admin, org=2
- Kur: uid=6, manager, org=1
- Richard: uid=5, editor, org=1

### Critical file paths touched
- `C:\xampp\htdocs\jasonai-landing\img\tool-transcribe.jpg`
- `C:\xampp\htdocs\jasonai-landing\tools\tools-data.json`
- `C:\xampp\htdocs\jasonai-landing\tools\tools.js`
- `C:\xampp\htdocs\jasonai-landing\index.html`
- `C:\xampp\htdocs\jasonai-landing\js\landing.js`
- `C:\xampp\htdocs\jasonai-hub\api\apps.json`
- `C:\xampp\htdocs\jasonai-hub\app-assets\transcribe-cover.jpg`
- Droplet `/var/www/transcribe/api/{stripe-checkout,stripe-success,feedback,ai-chat,classify-content,send-smtp}.php`
- Droplet `/var/www/transcribe/js/email-template.js`
- Droplet `/etc/nginx/sites-available/transcribe.jasonhogan.ca`

### Plan file reference
- `C:\Users\User\.claude\plans\here-is-a-chat-lovely-lynx.md` — migration + landing plan (COMPLETE — can archive after next session)

---

## Last updated: 2026-04-19 (late night)

## 2026-04-19 session snapshot — READ THIS FIRST IF RESUMING

### Where things stand on the transcribe tool itself
v3.114 shipped today — role-based access control is live. The tool is deployed to `/var/www/transcribe/` on `165.22.237.23` (still on transcribe.jasonhogan.ca). NOT on jasonai.ca yet.

**Role model (final, in prod):**
- Master Super-Admin = `users.is_super_admin = 1` (Jason, cross-tenant)
- Admin = `role_in_org = 'admin'` (tenant top; edits branding + settings)
- Manager = `role_in_org = 'manager'` (read-only settings + per-user Light/Dark)
- Editor = `role_in_org = 'editor'` (own reports + history only)

Test accounts seeded:
- Jason (uid=1, admin + is_super_admin=1, org=1)
- Acme Admin (uid=4, admin, org=2) — separate tenant
- Kur (uid=6, manager, org=1)
- Richard (uid=5, editor, org=1)

**What's wired:**
- `/api/session.php` returns role + is_master + theme; SPA tags `<body data-role data-is-master>`
- CSS gates in `css/style.css` (v3.114 section): `.requires-admin`, `.requires-manager-plus`, `.requires-master`
- `/api/settings.php` — per-key gating; theme always allowed (per-user via `settings.user_id`)
- `/api/list.php`, `/api/report.php`, `/api/quiz-report.php` — editor ownership scoping
- `js/app.js` `applyRolePermissions` now fetches session endpoint; `setThemeFromSettings` persists server-side; `openSettings` locks every input for managers except the Light/Dark toggle
- Super Admin menu link (only visible when is-master=1) → `/admin/` (existing super-admin console)
- Migration: `api/migrations/002_rbac_rename.sql` (already executed on prod)

### Active approved plans
- `C:\Users\User\.claude\plans\elegant-beaming-cloud.md` — RBAC plan (COMPLETED this session, but still useful reference)
- `memory/jasonai_master_plan.md` — THE canonical platform plan (approved 2026-04-15)
- `memory/jasonai_seats_and_billing.md` — NEW 2026-04-19, extends master plan with Stripe seats + volume pricing + SSO exchange + Whisper cost strategy

### NEXT SESSION — pick up here

Jason wants to wire transcribe into the jasonai.ca landing page as the first product tile. Specifics he asked for:

1. **Generate a Fal.ai image** for the transcribe tool to use on the landing page carousel + product page (same style as the other 8 tool images).
2. **Add transcribe to the landing page**:
   - First tile in the carousel
   - New product detail page at `C:\xampp\htdocs\jasonai-landing\tools\transcribe.html` (use the same template as the existing product pages)
   - Add to `C:\xampp\htdocs\jasonai-landing\apps\tools-data.json`
3. **Wire the product-page CTA** per `jasonai_seats_and_billing.md`:
   - Not signed in → Start Free Trial
   - Signed in, plan includes transcribe → Open {Transcribe} deep link to `transcribe.jasonhogan.ca` (until we migrate to transcribe.jasonai.ca subdomain)
   - Signed in, plan doesn't include → Add to Plan (stub Stripe flow)
4. **Hub tile** — add Transcribe to `C:\xampp\htdocs\jasonai-hub\api\apps.json` so the portal shows it after login.
5. **Seat-cap enforcement** on `api/admin/users.php` create action: return 402 with upgrade_url when `seats_used >= seats_included`. Client catches the 402 and renders an upsell modal reusing the workflow-modal pattern. Dead-link the upgrade URL until hub Stripe is wired.
6. **Deploy** — eventually copy landing page to SiteGround, keep hub + tools on DO droplets. Cloudflare DNS still chan.ns + conrad.ns.

### Memory files to read before starting next session
- `memory/MEMORY.md` — the index
- `memory/jasonai_master_plan.md` — approved master plan
- `memory/jasonai_seats_and_billing.md` — newer Stripe seat + pricing rules (**THIS extends the master plan, read last so it overrides conflicts**)
- `memory/transcribe_deployment.md` — droplet info, SSH details
- `memory/feedback_no_blind_scp.md` — local copy is often stale vs droplet; surgical edits via ssh preferred

### Files touched today (for context)
- `/var/www/transcribe/api/auth_middleware.php` (new role helpers)
- `/var/www/transcribe/api/session.php` (NEW endpoint)
- `/var/www/transcribe/api/settings.php` (rewrote for role gating)
- `/var/www/transcribe/api/list.php` (editor scoping)
- `/var/www/transcribe/api/report.php`, `quiz-report.php` (editor ownership + body role attrs)
- `/var/www/transcribe/api/migrations/002_rbac_rename.sql` (one-time, ran)
- `/var/www/transcribe/js/app.js` (applyRolePermissions, setThemeFromSettings, openSettings lockdown)
- `/var/www/transcribe/index.html` (menu class tags + Super Admin link)
- `/var/www/transcribe/css/style.css` (role gate CSS appended)

All committed locally as v3.114. Cache version: `?v=2026041bc`.

---

## Last updated: 2026-04-16 (evening)

## What is this project?

Jason Hogan (jasonhogan333@gmail.com) is building **Jasonai** — a suite of AI-powered productivity tools sold as a SaaS bundle at **jasonai.ca**. Each tool is a fork of a shared base template. The master plan is documented in memory at `jasonai_master_plan.md`.

## Current status

### COMPLETED

- [x] **Research tool base** — fully functional AI research report generator at `C:\xampp\htdocs\transcribe\research\`
  - Split-pane chat + live preview, PDF export, public share, email delivery
  - Analysis lightbox with orbital animations, rotating taglines, celebratory completion
  - 2.5s intro choreography, AppModal, mobile responsive
  - Cost-protected public AI chat (Haiku, $2.50 lifetime / $1.50 rolling caps)

- [x] **Multi-tenancy** — user_id on every table, tenant-scoped queries on all 17 endpoints

- [x] **User auth** — register/login/verify/reset at `api/auth.php`, session cookies + JWT SSO
  - Owner account: jasonhogan333@gmail.com (id=1, enterprise tier)
  - Test password set: `JasonTest2026!`

- [x] **Usage metering** — monthly credit tracking per user, tier caps (200/600/2000), `api/usage.php`

- [x] **Stripe billing** — checkout/portal/webhook at `api/stripe.php`, entitlements table
  - NOT yet configured with real Stripe products (needs dashboard setup)

- [x] **Per-user rate limiting** — sliding window, analyze (10/5min), chat (30/min)

- [x] **Admin panel** — `admin.html` with user list, tier/status dropdowns, comp, usage reset

- [x] **JWT SSO** — `api/_jwt.php` HS256 sign/verify, hub issues tokens, tools validate
  - JWT secret in config.php: `1d830d0441f0770fbd3cd4d48809d6936bdf0ca21439a26783f59795f06293ca`

- [x] **Credit deduction API** — `api/_hub_client.php` with local + remote modes, `api/credits.php`

- [x] **Entitlement middleware** — tools check user access before AI calls

- [x] **Branding cleanup** — all "Botson AI" → "Jasonai", emails → hello@jasonai.ca

- [x] **GitHub push** — https://github.com/scorponoc333/jasonai-base tagged v1.0
  - 36 files, 12,055 lines
  - Private repo under scorponoc333

- [x] **Skills created:**
  - `C:\Users\User\.claude\skills\landing-page-base\` — fork solidtech landing page
  - `C:\Users\User\.claude\skills\base-fork\` — fork entire tool base

- [x] **Master plan approved** — documented in memory `jasonai_master_plan.md`
  - Pricing: $149 (3 apps) / $349 (7 apps) / $699 (unlimited)
  - Credits: 200 / 600 / 2,000 per month
  - Affiliate: 25% recurring 12mo via Referly, 15% customer discount
  - Architecture: SiteGround (landing) + DO droplets (hub + tools) + Cloudflare DNS

### IN PROGRESS

- [ ] **Phase 2: Hub (app.jasonai.ca)** — building at `C:\xampp\htdocs\jasonai-hub\`
  - [x] API backend copied + adapted from base (auth, credits, usage, stripe, admin, settings)
  - [x] App catalog JSON at `api/apps.json` (8 tools defined)
  - [x] App catalog endpoint at `api/apps.php` (list, detail, select, add with onboarding_complete flag)
  - [x] Hub CSS at `assets/hub.css` (topbar, grid, tiles, detail page, accordion, carousel, sidebar, wizard)
  - [x] Dashboard HTML `index.html` (app grid, search, filters, topbar with tier/name/avatar)
  - [x] App detail page (cover with gradient, accordion 5-section, carousel placeholder, sidebar with access/plan/addons)
  - [x] Welcome wizard (4 steps: welcome → choose apps → branding → launch, skips step 2 for unlimited)
  - [x] Settings page (centralized branding: company name, color, logo)
  - [x] Hub JS `assets/hub.js` (boot, auth check, grid render, detail page, wizard flow, nav)
  - [x] Admin panel (copied from base)
  - [x] Auth pages (auth.html + reset.html copied from base)
  - [x] Full login → apps → entitlement pipeline tested via curl (8 apps, all ACCESS for owner)
  - [ ] **Session-resume skill** created at `.claude/skills/session-resume/` ← NEW
  - [ ] Visual polish + browser testing (need Chrome connected or user to verify) ← NEXT
  - [ ] Logo upload in wizard (file upload to settings endpoint)
  - [ ] Stripe product creation + real checkout flow

### NOT STARTED

- [ ] **Phase 3: Landing page** (jasonai.ca on SiteGround) — built at `C:\xampp\htdocs\jasonai-landing\`
  - [x] HTML structure: nav, hero, features (6 cards), app showcase (8 apps), pricing (3 tiers), FAQ (6 items), CTA, footer
  - [x] Dark premium CSS matching auth page aesthetic (glassmorphic, navy, blue accents)
  - [x] JS: neural network canvas, particles, IntersectionObserver reveals, count-up stats, FAQ accordion, pricing monthly/annual toggle
  - [x] AI-generated images: hero-bg2 (secretary), 8 tool images, 2 carousel images, features-bg (13 total via Fal.ai)
  - [x] Jason AI logos (white + black) in place
  - [x] Dark hero → carousel transition → white sections → dark CTA/footer
  - [x] 2.5s hero choreography (staggered left→right, badge flash, stat pop, count-up, CTA particle burst)
  - [x] IntersectionObserver stagger reveals per section (180ms per element)
  - [x] Parallax background breaks between sections
  - [x] Auto-scrolling image carousel (team + typing + tool images)
  - [x] 8 app cards with photorealistic images, centered titles, hover lift+zoom
  - [x] Nav glow on hover (JS hue rotation), frosted glass on scroll, light/dark mode swap
  - [x] View Plans button: larger, gradient shine, particle burst on appear + hover
  - [x] Feature cards: icon color→white gradient on hover, lift, subtle bg flash
  - [x] Pricing toggle monthly/annual with save badge
  - [x] FAQ accordion
  - [x] 100 hero particles (35% bright with glow)
  - [x] App card hover: lift+scale 130%, blur others, dark overlay (desktop)
  - [x] Pricing card hover: same lift+blur pattern (desktop)
  - [x] Feature cards: blue gradient background with white text/icons (centered)
  - [x] FAQ: grey bg with cover image, blue gradient accordion cards, smooth max-height transition
  - [x] Pricing: dark navy Starter/Agency, blue gradient Professional, gold badge + gold CTA
  - [x] Sections swapped: Apps first, then Features; parallax banners swapped accordingly
  - [x] Nav stays dark throughout (no more white/invert on light sections)
  - [x] Taller parallax images (1920x900) — no white bleed
  - [x] **Blog system built** at `C:\xampp\htdocs\jasonai-landing\blog\`:
    - [x] `api/blog.php` — full CRUD, AI generation (OpenRouter + Fal.ai), XML sitemap, categories
    - [x] `api/blog-schema.sql` — posts table with all SEO fields
    - [x] `blog/index.html` — listing with grid, search, filters, pagination, loading transition
    - [x] `blog/post.html` — single post with JSON-LD structured data, inline CTA, sidebar, related posts
    - [x] `blog/admin.html` — post management (publish/unpublish, feature, delete)
    - [x] `blog/blog.css` — full styles for listing, post, admin, loading transition, generate lightbox
    - [x] `blog/blog.js` — loading transition (zoom gradient in/out), post rendering, admin bar with AI generate
    - [x] AI generate: admin clicks "Generate Post" → enters keyword + title → OpenRouter writes SEO copy → Fal.ai generates cover image → auto-published
    - [x] SEO: JSON-LD Article schema, canonical URLs, meta description, og:image, XML sitemap
    - [x] GEO: Organization schema with Canadian address on blog index
    - [x] CTAs: inline after 3rd paragraph, sidebar pricing widget, related posts, bottom CTA banner
  - [x] **Apps listing page** at `C:\xampp\htdocs\jasonai-landing\apps\`:
    - apps/index.html — hero with neural network canvas + particles, search, category filters, grid with lift+blur hover
    - apps/apps.css + apps.js — full styling + interactions
    - api/tools.php — CRUD for tools catalog (reads/writes tools-data.json), admin auth check
    - Admin bar: "Add Tool" button opens editor lightbox (name, slug, tagline, category, icon, color, description, image, app URL, credits)
    - All tools sorted alphabetically, filterable by category, searchable
    - Gold category badges, blue icon accents, arrow hover effect on cards
    - "View All Apps" button on product pages links here
  - [x] **Product pages** rebuilt at `C:\xampp\htdocs\jasonai-landing\tools\`:
    - Animated hero (neural network canvas + 120 particles + generated bg image)
    - Gold category pill, description left + features sidebar right (light gray cards, blue icons)
    - Use case image tiles under description with lift+blur hover
    - CTA section (wider, bigger text, no em dashes)
    - "Looking for more tools?" section with random 4 tools + "View All Apps" button
    - Lift+blur hover on use cases, features sidebar, other tools tiles
    - Shared page transition loader with particles + rotating taglines
  - [x] **Blog system** fully built at `C:\xampp\htdocs\jasonai-landing\blog\`:
    - Blue geometric hero image (not grey), 100 white particles, neural network canvas
    - Blog cards with muted tags, blue category badges, uniform card heights
    - "Looking for more tools?" carousel at bottom linking to product pages
    - AI generation via admin (OpenRouter + Fal.ai), date range + tag filtering
    - Shared page transition loader on all blog pages
  - [x] **Shared loader system** (`css/loader.css` + `js/loader.js`):
    - Animated gradient background (shifting navy/blue colors)
    - 60 particle canvas with connections
    - Single large thin icon (180px), rotating taglines every 1.8s
    - Deployed on: tools pages, blog listing, blog posts, auth page
  - [x] **FAQ section** on landing page: dark background with generated blue geometric cover image, geometric shapes canvas animation, white text
  - [ ] Affiliate pages (/ref/{slug}) ← Phase 4
  - [ ] Checkout flow (custom upsell page → Stripe) ← Phase 4
  - [ ] FTP deploy to SiteGround ← Phase 6

- [ ] **Phase 4: Referly integration**
  - Bridge: Stripe webhook → POST /sales to Referly API
  - Affiliate creation, promo codes, personalized landing pages
  - API key is in `F:\home\jason\hoganagent-workspace\API_KEYS.env`

- [ ] **Phase 5: Fork Invoice Creator** (first tool fork)
  - Use `base-fork` skill
  - Custom AI prompt for invoices, output schema for line items
  - Build at `C:\xampp\htdocs\jasonai-invoice\`

- [ ] **Phase 6: Deploy**
  - DO droplet #1 (hub): 4GB/2CPU $24/mo
  - DO droplet #2 (light tools): 8GB/4CPU $48/mo
  - Cloudflare DNS: app.jasonai.ca → droplet #1, research/invoice.jasonai.ca → droplet #2
  - API keys in `F:\home\jason\hoganagent-workspace\API_KEYS.env`

- [ ] **Phase 7: Remaining tools** (trip planner, resume, cover page, newsletter, artwork, reports)

## Key file locations

| What | Where |
|---|---|
| Research tool (working base) | `C:\xampp\htdocs\transcribe\research\` |
| Clean base (GitHub copy) | `C:\xampp\htdocs\jasonai-base\` |
| Hub (in progress) | `C:\xampp\htdocs\jasonai-hub\` |
| Memory files | `C:\Users\User\.claude\projects\C--xampp-htdocs-transcribe\memory\` |
| Skills | `C:\Users\User\.claude\skills\` |
| API keys | `F:\home\jason\hoganagent-workspace\API_KEYS.env` |
| Solidtech landing page source | `C:\Users\User\.claude\skills\landing-page-base\source\` |
| GitHub repo | https://github.com/scorponoc333/jasonai-base (private, v1.0) |
| Local config (with secrets) | `C:\xampp\htdocs\transcribe\research\config.php` |
| Domain registrar | SiteGround (my.siteground.com) |
| DNS | Cloudflare (chan.ns + conrad.ns) |

## How to resume

If you're a new Claude Code session, do this:

1. Read this file (you just did)
2. Read `C:\Users\User\.claude\projects\C--xampp-htdocs-transcribe\memory\MEMORY.md` for all memory index
3. Read the specific memory file for whatever phase you're working on:
   - `jasonai_master_plan.md` — full approved plan
   - `jason_stack_preferences.md` — tech stack + security rules
   - `jasonai_domain_hosting.md` — domain/DNS/hosting topology + API key location
4. Check the "IN PROGRESS" section above to find exactly where to pick up
5. Continue building from there

## Jason's preferences (quick reference)

- Premium positioning, NOT consumer pricing
- Brand gradient: #2563eb → #152c4a
- Aesthetic: orbital animations, staggered reveals, branded modals, 2.5s intro choreography
- Stack: DigitalOcean + Cloudflare + Stripe + EmailIt + OpenRouter + Referly
- Security: rotate API keys, audit data, multiple AI models, Claude manages DNS via API
- Jason does NOT touch DNS dashboards — Claude manages everything via API
- Cost protection on every public-facing AI endpoint (cheap model, caps, stealth cutoff)

## Live Log
- [2026-04-19 14:00] [target=droplet] session-switch installed. Hooks wired. Pre-edit guard active on .html/.css/.js/.php/.assets/js/api. Active plan pinned. Ready for fresh session to pitch resumable threads.
- [2026-04-19 13:26] [target=droplet] Hey Jason — on **transcribe**, active target is the **droplet (165.22.237.23:/var/www/transcribe/)**. Session-switch briefing is now wiring correctly. Here's what's on deck: 1...
- [2026-04-19 13:27] [target=droplet] Two flags before touching that file: 1. **Target mismatch** — active target is the **droplet**, but `C:\xampp\htdocs\transcribe\index.html` is the local/stale copy. Editing he...
