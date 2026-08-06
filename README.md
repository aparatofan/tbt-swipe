# TBT Swipe

Swipeable mobile vocabulary flashcards for live English lessons. Replaces printed, cut-out paper flashcards with a phone deck students swipe through during class.

- **WordPress** 6.x, **PHP** 8.0+, Divi theme, self-hosted
- Pure PHP + vanilla JS. No jQuery, no build tools, no CDN, everything self-hosted.

## How it works

1. The teacher creates a set of English items — in **wp-admin → TBT → TBT Swipe**, or on a public page carrying the `[tbt_swipe_generator]` shortcode, which needs no wp-admin access at all.
2. One AI call fills in IPA, Polish translation, and a B1 example sentence for each item.
3. The teacher reviews/edits the generated cards and publishes the set.
4. The plugin generates a QR code (client-side, self-hosted library) that links to the deck page.
5. During the lesson, each student scans the QR on their own phone and works through the deck:
   - **Tap** a card to flip it and reveal the answer (unlimited, no penalty).
   - **Swipe up / "I know it"** (or tap the top zone) → the card disintegrates.
   - **Swipe down / "Not yet"** (or tap the bottom zone) → the card slides away to the unknown pile.
6. The end screen lists the words the student didn't know for verbal follow-up, with a **Go again** button that reshuffles just those cards.

This is a *learning* tool, not a test. Nothing is scored, judged, or persisted — the frontend is stateless (JS memory only; refreshing restarts the deck).

## Setup

1. Install and activate the plugin. Activation creates two tables (`{prefix}tbts_sets`, `{prefix}tbts_cards`).
2. Go to **TBT → TBT Swipe Settings** and enter:
   - Your **OpenAI API key** (stored server-side, never exposed to the browser).
   - The **model** string (default `gpt-4o-mini`, editable).
   - The **deck page** — a published page containing the `[tbt_swipe]` shortcode. This is used to build the deck URL and QR code.
3. Create a page with the `[tbt_swipe]` shortcode and select it as the deck page.
4. Build a set, generate cards, publish, and paste the QR into your lesson plan.

TBT Swipe lives under the **TBT** hub menu (**TBT → TBT Swipe** and **TBT → TBT Swipe Settings**). If the TBT Hub plugin is deactivated, it falls back to its own top-level **TBT Swipe** menu with a **Settings** child, so it is never unreachable.

## Shortcodes

### `[tbt_swipe]` — the student deck

```
[tbt_swipe]
```

The deck is chosen by the `?deck={slug}` query parameter, which the QR code sets automatically. (We deliberately avoid `?s=` because `s` is WordPress's reserved site-search parameter — using it makes WordPress run a search instead of loading the page.) Assets load only on pages that contain the shortcode.

### `[tbt_swipe_generator]` and `[tbt_swipe_sets]` — the teacher page

```
[tbt_swipe_generator]
[tbt_swipe_sets]
```

Put both on one page (`/swipe/` is the intended home) so a teacher can build decks and manage them without wp-admin access. They are two shortcodes rather than one so they can be split across separate pages later without a code change.

Both render **nothing at all** — not even a "no access" notice — for a visitor without the `tbts_manage` capability. Students land on this page too, and there is no reason to advertise a tool they cannot use.

The page opens with the same blue gradient hero the [TBT Matching Game](https://github.com/aparatofan/tbt_matching_game) uses — same gradient, radius, padding and logo — so the two tools read as one product rather than two designs. Those values live in `tbt-tokens.css` as `--tbt-hero-*`, and are copied from the Matching Game rather than re-picked.

The generator runs as three visible stages — Admin, Content, Save and share — all present from first load, with the stage the teacher is on lit up and finished stages marked green. Content runs generate → **review and edit** → save. The review step is the point of the whole page: frontend teachers cannot reach wp-admin, so it is their only chance to fix AI output (grammar, doubled IPA characters) before students see it. There is no card editing after save in this version.

`[tbt_swipe_sets]` is server-rendered rather than fetched, so it paints in one pass inside Divi with no loading flash. It is scoped to the current user's own decks **without exception**, administrators included; wp-admin remains the place to see everyone's decks. Decks are grouped by class, with `No class` last — as an ordinary group, because an unattached deck is a supported state, not an orphan.

The player and the management UI are separate surfaces: `deck.css` / `deck.js` load only where `[tbt_swipe]` itself is, never on a page holding only the two management shortcodes.

> **Exclude the teacher page from page caching.** Writes go through the REST API with a `wp_rest` nonce. If the page is served from a cache, the nonce is the one minted for whoever warmed the cache, and every save comes back 403. The page shows `Your session has expired. Refresh the page.` when it detects this, but the real fix is to exclude the URL in your caching plugin (LiteSpeed, WP Rocket, Cloudflare page rules, and so on).

## Who can build decks

Deck and card management hangs off a single capability, `tbts_manage`, granted to the roles ticked under **TBT → TBT Swipe Settings → Who can build decks**. Administrators always have it.

Settings themselves — API key, model, deck page, role grants, AI limits — stay on `manage_options` whatever is ticked. A teacher can build decks; only an administrator can change how the plugin is configured.

## Classes and lessons

If [TBT Notes](https://github.com/aparatofan/tbt_notes) is active, a set can be attached to one of the teacher's own classes and optionally to a lesson within it. Attaching only adds a grouping — it never restricts the slug link, and an attached set works exactly like an unattached one.

Swipe never queries the Notes tables directly. Everything goes through `TBT_Notes_DB`'s public static methods so ownership logic lives in one place. Deactivating Notes leaves the generator fully functional with the class selector simply absent.

## AI usage limits

Configured under **TBT → TBT Swipe Settings → AI usage limits**:

- **Cards per generation** — default 30, hard maximum 100.
- **Generations per user per day** — default 20; set to 0 for no limit.

A single user can be given a different daily limit with the user meta key `tbt_swipe_max_generations_per_day`, which wins over the global setting. The daily counter lives in user meta keyed `tbt_swipe_gen_count_YYYY-MM-DD` and moves **only after a successful generation** — a failed API call costs no quota.

All limits are enforced server side before the API call. The browser's line-count check is UX only and is never the gate.

## Security

- The OpenAI API key is stored in `wp_options` and never printed to the frontend or returned by any REST/AJAX response.
- Every admin AJAX handler checks both a nonce (`check_ajax_referer`) and the `tbts_manage` capability; every frontend write endpoint checks a `wp_rest` nonce and the same capability.
- **Owner scoping on every set query.** Capability alone is not enough: a user holding `tbts_manage` cannot reach another user's set by guessing an ID. List, single fetch, edit and delete all filter by `owner_id`. Administrators oversee every set from wp-admin; the frontend list is owner-scoped for everyone.
- Attaching a set to a class verifies that the user owns that class, so editing the form value cannot drop a set into someone else's class. A lesson must belong to the class it is submitted with.
- All input is sanitised on save; all output is escaped on render.
- All database access uses `$wpdb->prepare()`.
- The public REST route (`GET /wp-json/tbt-swipe/v1/set/{slug}`) is read-only, returns published sets only, exposes no IDs or user data, and sends no-cache headers so a shared cache (e.g. LiteSpeed) can't leak one set's data at another slug.
- Set slugs are 12-character unguessable strings so students can't browse to a set before the lesson.

**Public repo:** no API keys, site secrets, `.env`, or example config with real values are ever committed. See `.gitignore`.

## File structure

```
tbt-swipe/
├── tbt-swipe.php              # bootstrap, constants, activation hook
├── includes/
│   ├── class-tbts-capabilities.php  # tbts_manage, role grants
│   ├── class-tbts-classes.php       # read-only bridge to TBT Notes
│   ├── class-tbts-db.php      # tables, dbDelta, all queries
│   ├── class-tbts-admin.php   # menu, list screen, editor screen
│   ├── class-tbts-ajax.php    # admin AJAX (nonce + cap checked)
│   ├── class-tbts-api.php     # OpenAI proxy, server side only
│   ├── class-tbts-generator.php     # the one generation path + AI limits
│   ├── class-tbts-rest.php    # public read endpoint for the deck
│   ├── class-tbts-manage-rest.php   # authenticated writes for the teacher page
│   ├── class-tbts-shortcode.php     # [tbt_swipe] + asset routing
│   ├── class-tbts-frontend.php      # [tbt_swipe_generator], [tbt_swipe_sets]
│   └── class-tbts-settings.php
├── assets/
│   ├── css/admin.css
│   ├── css/deck.css
│   ├── css/frontend.css       # frontend components, needs tbt-tokens.css
│   ├── css/tbt-tokens.css     # shared TBT variables + reset
│   ├── fonts/roboto-slab-v36-latin.woff2      # self-hosted (base Latin)
│   ├── fonts/roboto-slab-v36-latin-ext.woff2  # self-hosted (Polish diacritics)
│   ├── fonts/roboto-mono-v31-latin.woff2      # self-hosted, hero title only
│   ├── fonts/roboto-mono-v31-latin-ext.woff2  # self-hosted, hero title only
│   ├── img/tbt-logo.png       # TBT mark on each card face — see note below
│   ├── js/admin.js
│   ├── js/deck.js
│   ├── js/frontend.js
│   └── js/lib/qrcode.min.js   # self-hosted QR library (MIT)
├── README.md
└── .gitignore
```

**Branding assets.** Roboto Slab is self-hosted (two weights, `latin` + `latin-ext`
subsets) rather than hotlinked from Google Fonts, so no visitor IP is sent to Google.
Roboto Mono 700 — the face of the hero title — is self-hosted for the same reason,
even though the Matching Game loads that weight from Google.
`assets/img/tbt-logo.png` is currently a **placeholder mark** — replace it with the
official TBT logo PNG (same path/filename, roughly 28–32px tall when displayed,
transparent background) and it will appear on both card faces automatically.

## Deployment

Deployment is via GitHub Actions FTP, consistent with the other TBT plugins — see `.github/workflows/deploy.yml`. FTP host/user/password are supplied as repository secrets (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`); the target directory is set with `FTP_SERVER_DIR`. No credentials are stored in the repository.
