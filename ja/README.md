# Japanese site

`/ja/` is the domestic Japanese site based on the approved SDC2027-JA-Rough-V14 layout and SDC2027-JA-Codex-Prompt requirements.

- `index.html`: domestic event information, fees, access, hotel and native FAQ accordions.
- `news.html`: separate news listing; approved overseas entry news links directly to the English site.
- `css/style.css`: V14 layout and explicit foreground colors, including responsive styles.
- `js/site.js`: mobile navigation and optional video link activation.
- `js/config.js`: approved video URLs only. Empty values keep both YouTube controls disabled. Domestic entry notices are text with no link.
- `fonts/noto-sans-jp.otf`: font extracted from the supplied reference to preserve its Japanese typography.

Images use the existing shared `assets/` files, including the same hotel images used by the overseas site. Hotel alt text identifies those as provisional imagery.

Local preview: serve the repository over HTTP and open `/ja/` or `/ja/news.html`. No build step or external API is required.

Verification (2026-10-04): headless Edge checked both Japanese pages at 320, 375, 390, 430, 768, 1024, 1200 and 1440 pixels. No horizontal overflow, broken images or browser errors. Mobile navigation, Escape/focus restoration, anchor navigation, all five FAQ accordions, news links, disabled entry/video controls, foreground colors and mobile CTA order passed. Desktop and mobile screenshots were visually reviewed. Core navigation and FAQ also work without JavaScript.
