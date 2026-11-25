# Changelog — TBT Swipe

## 1.18.0 — 25 Nov 2026

Card style v2, shared with TBT Flashcards 2.5.0: white at every width, light blue front, TBT blue back, phone card 8% smaller with the stack, smaller phone back text, long-text fit.

- **White table at every width.** The phone's blue field and the desktop's grey table are gone; phone zone labels, arrows and "1 / N" are mid-blue `#5C7DB5`, and the armed zone turns TBT blue.
- **Front `#EEF4FE`** with a `#D7E4F8` hairline; tag, IPA and hint re-tuned for contrast on it. **Back** is the TBT blue gradient at every width; the phone navy is gone.
- **Phone card ~8% smaller**, with the striped stack under it (same transforms as the table).
- **Phone back text smaller:** answer `clamp(1.4rem, 4.9vw, 1.65rem)`, example `clamp(1.08rem, 3.85vw, 1.28rem)`.
- **Long-text fit:** every live face font size is multiplied by `--fit`; JS steps it down 5% at a time to 70% until the content fits, after each card, on resize and once web fonts have loaded. Short cards are never enlarged.
- Summary on white at every width: blue heading, word list and summary tiles on the light blue front surface, blue **Go again**, blue medal. The desktop progress pill is light blue.
- Confetti uses the saturated palette without additive glow at every width, so it shows on white.

## 1.17.0 — 20 Nov 2026

Unified card style, shared with TBT Flashcards 2.3.0 (Card style contract v1).

- **The table card is now 720×405 (16:9 landscape)**, up from the 380×500 portrait. Below 900px the phone layout is unchanged.
- **Faces on the table** follow the contract, sized in `cqw` of the card: a WORD / MEANING tag top-left (it swaps with the deck's first face), the logo top-right (colour on the front, white on the back), the lead line in blue Roboto Slab, IPA in Roboto Mono, and "Click or press Space to flip" on both faces.
- **The back is solid blue with white type, no stripes**: the brand-blue gradient on the table, a deeper navy below 900px so it stands apart from the blue field. Stripes stay only on the stack.
- A thin rule separates the answer from the example, only when there is an example; the example is in curly quotes.
- Roboto Mono is now declared in `deck.css`. The bundled files are static 700 cuts, so only 700 is declared; the IPA's 500 renders with it.
- **Heap:** a "not yet" card never hangs more than ~70px off the side of the table, including the seeded scatter.
- **Summary deal:** at most 4 landscape tiles per row (8 cards deal 4 + 4, 7 deal 4 + 3), scale 0.40–0.65, tile padding a little wider than tall.
- New i18n strings: `faceWord` ("Word"), `faceMeaning` ("Meaning").
