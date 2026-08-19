# TBT Swipe — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- First inspect `git status`, then read only the files named in the task or located by targeted search.
- Use search to find symbols, selectors, routes, or hooks before opening large files.
- Read only the relevant section of a large file when possible.
- Do not read `README.md` by default. Use it only when the task needs deeper product or architecture context.
- Do not repeatedly reread files already understood; inspect the diff after edits instead.
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+.
- Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, or npm dependency is required by the plugin.
- Main bootstrap: `tbt-swipe.php`.
- Server-side logic: `includes/`.
- Frontend/admin assets: `assets/`.
- Standalone logic tests: `tests/`.
- Existing code and `assets/css/tbt-tokens.css` are the source of truth for current visual conventions. Do not redesign or restyle unless the task asks for it.

## Architecture to preserve

- The student deck and the teacher management UI are separate surfaces; keep their asset loading separate.
- `[tbt_swipe]` is the student deck. `[tbt_swipe_generator]` and `[tbt_swipe_sets]` are teacher-management shortcodes.
- TBT Notes and TBT Students are optional integrations. Swipe must still work when either is absent.
- Never query TBT Notes tables directly; use its public API / bridge methods.
- Frontend set lists and mutations are owner-scoped, including for administrators. wp-admin is the cross-user oversight surface.
- The public deck REST endpoint is read-only and exposes published deck data only.
- Do not invent migrations or backfill historical values unless the task explicitly requires it.

## Security rules

- Never expose or commit API keys, credentials, secrets, `.env` data, or real configuration values.
- Keep the OpenAI API key server-side only.
- Authenticated writes must keep nonce, capability, and ownership checks.
- Validate class/lesson ownership when attaching sets.
- Sanitize input and escape rendered output.
- Use `$wpdb->prepare()` for database queries containing values.
- Do not weaken an existing security check merely to make a feature work.

## Coding style

- Follow the surrounding WordPress/PHP style rather than reformatting whole files.
- Preserve existing `TBTS_` class/constant naming and `tbts_` function naming.
- Prefer small, local changes over new abstractions for one-off behavior.
- Reuse existing helpers and public integration methods before adding parallel logic.
- Do not add a dependency when the same result can be achieved with the existing stack.
- Keep comments for non-obvious decisions and constraints; avoid narrating obvious code.

## Validation

Run the smallest relevant check while working, then all standalone tests before finishing a PHP logic change:

```bash
php tests/test-attachment-validation.php
php tests/test-levels.php
php tests/test-notes-bridge.php
```

For changed PHP files, also run `php -l <file>`.

If a change affects WordPress/Divi behavior that the standalone tests cannot cover, state clearly what still needs browser or live-site verification.

## Git and deployment

- Do not commit directly to `main`; use a focused feature branch unless the user explicitly requests otherwise.
- Keep commits task-focused and descriptive.
- Before finishing, inspect the final diff for accidental unrelated changes.
- Code pushed to `main` is deployed automatically by GitHub Actions over FTPS.
- Markdown-only changes are excluded from automatic deployment.
- Never alter deployment secrets or FTP paths unless the task is specifically about deployment.

## Context discipline

- Prefer targeted search + narrow reads over broad exploration.
- Do not paste large file contents, logs, or test output into the conversation when a short summary is enough.
- If a task is complete, summarize what changed, what was tested, and any remaining manual verification in a few bullets.
- For a new unrelated task, favor a fresh Claude Code session rather than carrying a long old conversation forward.
