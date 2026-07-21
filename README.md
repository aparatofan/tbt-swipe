# TBT Swipe

Swipeable mobile vocabulary flashcards for live English lessons. Replaces printed, cut-out paper flashcards with a phone deck students swipe through during class.

- **WordPress** 6.x, **PHP** 8.0+, Divi theme, self-hosted
- Pure PHP + vanilla JS. No jQuery, no build tools, no CDN, everything self-hosted.

## How it works

1. The teacher creates a set of 5–20 English items in **wp-admin → TBT Swipe**.
2. One AI call fills in IPA, Polish translation, and a B1 example sentence for each item.
3. The teacher reviews/edits the generated cards and publishes the set.
4. The plugin generates a QR code (client-side, self-hosted library) that links to the deck page.
5. During the lesson, each student scans the QR on their own phone and works through the deck:
   - **Tap** a card to flip it and reveal the answer (unlimited, no penalty).
   - **Swipe right / "Know it"** → the card burns immediately.
   - **Swipe left / "Not yet"** → the card goes to the unknown pile.
6. The end screen lists the words the student didn't know for verbal follow-up, with a **Go again** button that reshuffles just those cards.

This is a *learning* tool, not a test. Nothing is scored, judged, or persisted — the frontend is stateless (JS memory only; refreshing restarts the deck).

## Setup

1. Install and activate the plugin. Activation creates two tables (`{prefix}tbts_sets`, `{prefix}tbts_cards`).
2. Go to **TBT Swipe → Settings** and enter:
   - Your **OpenAI API key** (stored server-side, never exposed to the browser).
   - The **model** string (default `gpt-4o-mini`, editable).
   - The **deck page** — a published page containing the `[tbt_swipe]` shortcode. This is used to build the deck URL and QR code.
3. Create a page with the `[tbt_swipe]` shortcode and select it as the deck page.
4. Build a set, generate cards, publish, and paste the QR into your lesson plan.

## Shortcode

```
[tbt_swipe]
```

The deck is chosen by the `?deck={slug}` query parameter, which the QR code sets automatically. (We deliberately avoid `?s=` because `s` is WordPress's reserved site-search parameter — using it makes WordPress run a search instead of loading the page.) Assets load only on pages that contain the shortcode.

## Security

- The OpenAI API key is stored in `wp_options` and never printed to the frontend or returned by any REST/AJAX response.
- Every admin AJAX handler checks both a nonce (`check_ajax_referer`) and `current_user_can( 'manage_options' )`.
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
│   ├── class-tbts-db.php      # tables, dbDelta, all queries
│   ├── class-tbts-admin.php   # menu, list screen, editor screen
│   ├── class-tbts-ajax.php    # admin AJAX (nonce + cap checked)
│   ├── class-tbts-api.php     # OpenAI proxy, server side only
│   ├── class-tbts-rest.php    # public read endpoint for the deck
│   ├── class-tbts-shortcode.php
│   └── class-tbts-settings.php
├── assets/
│   ├── css/admin.css
│   ├── css/deck.css
│   ├── js/admin.js
│   ├── js/deck.js
│   └── js/lib/qrcode.min.js   # self-hosted QR library (MIT)
├── README.md
└── .gitignore
```

## Deployment

Deployment is via GitHub Actions FTP, consistent with the other TBT plugins — see `.github/workflows/deploy.yml`. FTP host/user/password are supplied as repository secrets (`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`); the target directory is set with `FTP_SERVER_DIR`. No credentials are stored in the repository.
