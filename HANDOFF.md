# Jasonai Platform — Session Handoff

> **For Claude Code:** If you're a new session picking this up, read this file FIRST,
> then read the memory files at `C:\Users\User\.claude\projects\C--xampp-htdocs-transcribe\memory\MEMORY.md`
> for full context. This file tells you WHERE WE ARE. Memory tells you WHO Jason is and WHAT we're building.

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

Jason Hogan (me@jasonhogan.ca) is building **Jasonai** — a suite of AI-powered productivity tools sold as a SaaS bundle at **jasonai.ca**. Each tool is a fork of a shared base template. The master plan is documented in memory at `jasonai_master_plan.md`.

## Current status

### COMPLETED

- [x] **Research tool base** — fully functional AI research report generator at `C:\xampp\htdocs\transcribe\research\`
  - Split-pane chat + live preview, PDF export, public share, email delivery
  - Analysis lightbox with orbital animations, rotating taglines, celebratory completion
  - 2.5s intro choreography, AppModal, mobile responsive
  - Cost-protected public AI chat (Haiku, $2.50 lifetime / $1.50 rolling caps)

- [x] **Multi-tenancy** — user_id on every table, tenant-scoped queries on all 17 endpoints

- [x] **User auth** — register/login/verify/reset at `api/auth.php`, session cookies + JWT SSO
  - Owner account: me@jasonhogan.ca (id=1, enterprise tier)
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
