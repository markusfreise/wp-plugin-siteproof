=== Siteproof – AI Content Audit ===
Contributors: markusfreise
Tags: content audit, seo, accessibility, broken links, proofreading
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit every page: SEO, load time, headings, AI proofreading, images, links and accessibility. One report, fixes applied inline.

== Description ==

Siteproof checks every published page of your site and collects the results in one report in the WordPress admin. It is built for the last weeks before a launch and for regular check-ups: what is broken, what is missing, what slows the site down, and where the text has typos.

**What is checked per page**

* **SEO** – indexing (noindex), meta title and description (custom, from a template or missing, length), og:title, og:description, og:image (missing or the site-wide default image), canonical. Works with Rank Math and Yoast SEO to tell custom fields from generated ones.
* **Load time** – Largest Contentful Paint, First Contentful Paint, CLS, Total Blocking Time and page weight by type (HTML, JavaScript, CSS, fonts, images, third parties) via Google PageSpeed Insights, including real-user field data when available. Without a PageSpeed key, a local measurement in the browser is used and clearly labelled.
* **Content** – heading structure with the block each heading comes from (missing H1, additional H1, jumps such as H2 to H4) and AI proofreading: spelling, punctuation, grammar and placeholder texts.
* **Images** – delivered vs. needed size (rendered width on mobile, retina), file size, KB per megapixel, format, lazy loading and missing alt texts.
* **Links** – internal and external, redirects, broken links, soft 404s (redirects to the front page), missing anchors. Social networks that block bots are marked as "not checkable" instead of "broken".
* **Accessibility** – automated WCAG 2.2 AA checks with the bundled axe-core, in the language of your admin.

**The report**

* An overall assessment on top lists site-wide patterns, e.g. "the H1 is missing on 17 of 31 pages", "hero.jpg loads on every page (1.5 MB)" or accessibility rules that fail on almost every page (a template issue).
* Every finding is sorted by severity. A sticky filter bar switches between "Red only", "Red and yellow" and "All" and remembers your choice.
* Header and footer are checked once under "Global" instead of on every page.
* Each run is compared with the previous one: new findings are marked, fixed ones are listed.
* Fix directly from the report: enter a missing alt text and save it to the media library and the page, or apply a proofreading correction to the page content (a revision is created). Ambiguous text is never replaced – you get a link to the editor instead.
* Export any report as a standalone HTML file.

**AI proofreading with your own provider**

Choose Anthropic (Claude), OpenAI, OpenRouter or – on WordPress 7.0 and later – the AI connections of your site (Settings › Connectors), pick the model from the provider's live model list and set an optional fallback provider for errors and rate limits. Before proofreading starts, Siteproof estimates the tokens and costs for each configured provider and asks for confirmation. The report states which provider and model proofread each page.

OpenRouter can be connected with one click (OAuth PKCE) – no key to copy. Anthropic and OpenAI need an API key from their developer consoles; signing in with a Claude Pro/Max or ChatGPT subscription is not possible, because these subscriptions cannot be used by third-party applications.

**Built for real sites**

* The audit runs step by step from your browser tab, so there are no PHP timeouts on large sites. It can be stopped and resumed.
* Selective re-check: per section (SEO, load time, content, images, links, accessibility), check only the pages that were critical, to check or OK in the last audit. All other results are carried over, which saves time and AI costs.
* API keys are encrypted (libsodium) and never shown in full, never sent to the browser and never included in reports or exports. Keys can also be defined as constants in wp-config.php.
* Reports are visible to administrators only. A dashboard notice reminds you to delete stored runs before going live.
* No external CDNs; all scripts and styles are part of the plugin and load only on the Siteproof screens.
* Works with any theme and any blocks: block names come from the block registry.

**Languages:** English and German (de_DE, de_DE_formal).

== External services ==

Siteproof only connects to external services that you configure and only when you start an audit, test a connection or load a model list in the settings.

**Anthropic API (Claude)** – used for AI proofreading if Anthropic is selected as provider or fallback. For each page, the visible text segments of the content area, the page title and URL, the current meta title, meta description and og:image URL, a list of image URLs, a short load-time summary and your word list are sent to `api.anthropic.com` when the proofreading step runs. The model list is loaded from the same API. [Commercial Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy Policy](https://www.anthropic.com/legal/privacy).

**OpenAI API** – used the same way if OpenAI is selected; the same data is sent to `api.openai.com`. [Terms of Use](https://openai.com/policies/terms-of-use), [Privacy Policy](https://openai.com/policies/privacy-policy).

**OpenRouter** – used the same way if OpenRouter is selected; the same data is sent to `openrouter.ai`, which forwards it to the chosen model provider. When you click "Connect with OpenRouter", you are redirected to `openrouter.ai` to authorize; a one-time code is then exchanged for a key. The public model list with prices is loaded from `openrouter.ai`. [Terms of Service](https://openrouter.ai/terms), [Privacy Policy](https://openrouter.ai/privacy).

**WordPress AI Client (WordPress 7.0+)** – if "WordPress AI (site connections)" is selected, the same data is passed to the AI Client built into WordPress, which sends it to the provider you connected under Settings › Connectors. The terms and privacy policy of that provider apply.

**Google PageSpeed Insights API** – used for load-time measurement if a PageSpeed key is stored. The URL of each audited page (or the same path on the live URL you configured) is sent to `www.googleapis.com`; Google then loads that page. [Google APIs Terms of Service](https://developers.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy).

**Link check** – to check links, Siteproof sends HEAD/GET requests to every URL linked on the audited pages, like a browser following the link. No data other than the request itself is transmitted.

== Installation ==

1. Install the plugin from the plugin directory or upload the `siteproof` folder to `/wp-content/plugins/`.
2. Activate it.
3. Optional: go to **Siteproof › Settings** and connect an AI provider (for proofreading) and store a PageSpeed Insights key (for realistic load times).
4. Go to **Siteproof › Audits** and click **Start audit**. Keep the tab open until the report opens.

Keys can also be defined in `wp-config.php`; they then take precedence over stored keys:

`define( 'SITEPROOF_ANTHROPIC_KEY', '...' );`
`define( 'SITEPROOF_OPENAI_KEY', '...' );`
`define( 'SITEPROOF_OPENROUTER_KEY', '...' );`
`define( 'SITEPROOF_PAGESPEED_KEY', '...' );`

== Frequently Asked Questions ==

= Do I need an AI provider? =

No. Without one, everything except proofreading and the SEO text suggestions works. The heading, image, link, SEO and accessibility checks run entirely on your site.

= What does proofreading cost? =

It depends on the model and the amount of text. Siteproof shows an estimate for every configured provider before the step starts, based on the collected text and the prices in the settings (OpenRouter prices are loaded automatically). A typical page uses a few thousand tokens.

= Can I use my ChatGPT Plus or Claude Pro subscription? =

No. These subscriptions cannot be used by third-party applications. Use an API key from the provider's developer console, or connect OpenRouter.

= Why do load times differ from what I see? =

Without a PageSpeed Insights key, the page is measured in your own browser: unthrottled and possibly cached, so the values are optimistic. With a key, Google measures a simulated mid-range phone on a 4G connection, like the "mobile" tab of PageSpeed Insights. For staging or local sites, set a live URL so that Google can reach the pages.

= Will the browser measurement count as visits? =

Pages are loaded in a small frame in your admin tab, so analytics scripts on the pages may count these visits. Exclude logged-in administrators in your analytics tool if that matters.

= Does Siteproof change my content? =

Only when you click "Save" or "Apply" next to a finding. Alt texts are saved to the media library and to images on that page that have an empty alt attribute; text corrections replace exactly one unambiguous passage and create a revision.

= Is axe-core included? =

Yes. axe-core by Deque Systems is bundled unmodified under the Mozilla Public License 2.0 (see `assets/vendor/axe-core/LICENSE`); source code: https://github.com/dequelabs/axe-core

= Where are the stylesheet sources? =

In `resources/scss`. Build them with `npm install && npm run build`.

== Screenshots ==

1. Overall assessment, sticky filter and overview per page and section.
2. A page in the report: heading structure, proofreading with "Apply", images with inline alt text editing.
3. The running audit with progress per step.
4. Cost estimate before AI proofreading.
5. Settings: AI provider, models, PageSpeed Insights, scope and thresholds.

== Changelog ==

= 1.1.0 =
* New: selective re-check. Per section, only pages that reached the selected levels in the last audit are checked again; all other results are carried over and marked as such in the report.
* The cost estimate before AI proofreading only counts pages that are still to be proofread.

= 1.0.1 =
* Image sizes: same-origin images are measured with a cache-bypassing request, so an outdated copy in the browser cache no longer inflates the reported size.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
Re-check only the pages that need it: select levels per section, all other results are carried over.

= 1.0.1 =
More accurate image sizes when files were replaced under the same URL.

= 1.0.0 =
First release.
