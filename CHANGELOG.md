# Changelog

Entries begin at 1.9.0. Earlier releases are recorded in the git history.

## 1.9.0

- **Library rail.** `[tbt_swipe_sets]` renders a left rail listing its class
  groups, with an **All decks** item first. Clicking an item filters the list to
  that group and retitles the heading; clicking **All decks** restores every
  group and the "Your decks" heading. It filters rather than navigates — no
  scrolling, no URL change, no history entry — because a later release adds a
  destination whose decks are not in the page at all.
- The rail is presentation only: no database, REST or query change, and
  `TBTS_DB_VERSION` is unchanged. Every group is already server-rendered.
- **Requires TBT Hub ≥ 1.3.0** for the `tbt-rail` style handle. Hub owns the
  rail's appearance; Swipe adds only its placement. With Hub deactivated the
  handle is not registered, no rail markup is emitted and the library renders as
  the stacked list it has always been.
- No rail is rendered when the library holds a single group, and the generator
  page never loads `tbt-rail.css`.
- The selected filter is not persisted; the library opens on **All decks**.
