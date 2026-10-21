# Sakura Dragon Cup 2027

Vanilla HTML / CSS / JavaScript site, implemented against `IMPLEMENTATION_SPEC_V2.md`.

## Routes

- `/`: existing Teaser artwork and animation retained; site selection added.
- `/en/`: overseas-team English site, with its own CSS and JavaScript.
- `/ja/`: domestic Japanese site based on the approved V14 reference; `/ja/news.html` is the news listing. The root Japanese CTA opens this site.

## Content and release controls

- `en/js/config.js`: the external entry URL is set in **one place**, `ENTRY_URL`. Leave empty until the approved HTTPS form URL is available.
- `en/js/updates-data.js`: approved news array. The latest three items are sorted by ISO date; an empty array hides the news strip.
- `en/js/quick-help-data.js`: all 10 categories and 43 fixed answers from the V2 specification. No AI, free-text input or API.
- Original image files are preserved alongside optimized WebP copies used by the English site.
- Hotel images are placeholders and are labelled as illustrative. Replace them with cleared final material before publication.

### Bulletin 01

The approved `SDC2027_Bulletin01TBA.pdf` is copied unchanged to `assets/pdf/SDC2027_Bulletin01.pdf`. `BULLETIN_READY = true` enables the download; the HTML also provides the link without JavaScript. Hotel rates remain TBA. The official hotel is optional; teams choosing it book through JTB.

### October 2026 site launch

The root links now lead to `/ja/` and `/en/`. This site launch does not open overseas entry: `ENTRY_URL` stays empty and server-side reception controls are unchanged. The application control shows “Entries open 1 November 2026”. The November opening switch, including entry-button wording and form submission tests, is a separate task. Confirm the date in `en/js/updates-data.js` against the actual publication date immediately before pushing; the local review uses 2026-10-22. Do not push the launch changes until the user approves publication.

### Before publication

Confirm the entry form URL, hotel rates and final images, updated Bulletin 01, organizer/partner wording, actual news articles, meta description / OG image, root CTA wording and Japanese site launch timing. Practice details and shuttle times correctly remain deferred to Bulletin 02; no schedules or hotel prices have been invented.

The implementation does not publish automatically from local edits. The existing GitHub workflow publishes pushes to `main` to Sakura Internet. Local source extraction and verification artifacts in `.implementation/` are ignored by Git and explicitly excluded from deployment.

## Local preview

Serve the repository with a static HTTP server, for example `python -m http.server 4173`, then open `http://localhost:4173/` or `http://localhost:4173/en/`. Use HTTP rather than opening HTML files directly, because the English site uses JavaScript modules.

## Verification performed

Headless Microsoft Edge / Playwright checked both routes at widths 320, 375, 390, 430, 768, 1200 and 1440 pixels: no horizontal overflow, all images loaded and no browser errors. Desktop and mobile screenshots were reviewed. Mobile menu opening, anchor navigation and Escape were exercised. Quick Help was checked for all 10 categories / 43 answers, Tab containment, closing and focus restoration. Empty updates and disabled pending CTAs were verified. The English site was also checked at 320px with JavaScript disabled; navigation and core content remain usable. Reduced motion is supported on both routes.
