# Changelog — TBT Swipe

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
