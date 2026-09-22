# Changelog

# 2.1.7 - 2026-09-22

- Connected the existing Pro information page to the published Gumroad checkout when the Pro add-on is not installed.
- Preserved the `affilio_pro_upgrade_url` filter so site owners and compatible add-ons can replace the commercial destination.
- Changed no affiliate data, attribution, commission, payout, database schema, or Core API behavior.

# 2.1.6 - 2026-09-16

- Replaced the plain signed-out dashboard notice with a polished, responsive affiliate access card.
- Routed dashboard sign-in and password recovery through the affiliate-scoped branded WordPress account flow.
- Added safe internal redirect recognition so existing dashboard login links receive the same branded experience.
- Included the affected referral IDs in paid, failed, and cancelled payout lifecycle hooks so compatible extensions can synchronize derived data immediately.
- Left ordinary WordPress login pages, authentication, Core API 1.3.0, and database schema 1.8 unchanged.

# 2.1.5 - 2026-09-16

- Corrected the logged-in single-step registration grid so hidden step markers cannot collapse the heading into a narrow column.
- Removed redundant progress navigation after successful submission and retained a focused confirmation with account actions.
- Rebuilt pending and restricted affiliate status cards with a branded header, review timeline, sign-in email, password/security action, and responsive account controls.
- Added an affiliate-scoped Dreamax login and password-reset experience, including branded reset links in WordPress new-user and recovery emails.
- Kept core WordPress authentication, reset-key validation, Subscriber roles, Core API 1.3.0, and database schema 1.8 unchanged.

# 2.1.4 - 2026-09-16

- Rebuilt affiliate registration as a compact, brand-aligned account and application flow with clearer hierarchy, balanced fields, explicit optional labels, responsive mobile styling, and signed-in account context.
- Moved payout setup out of the public application. Approved affiliates now complete or change payout preferences securely from their authenticated dashboard before requesting payment.
- Added a polished post-registration account-access card with the sign-in email, password setup guidance, active-session confirmation, affiliate-area link, and secure password recovery action.
- Added Login and Set/Reset Password actions when a registration email already belongs to an existing WordPress account.
- Added login and password-recovery URLs to Free's email runtime context and included complete access instructions in affiliate status notifications.
- Preserved WordPress-generated passwords, Subscriber roles, automatic post-registration sign-in, approval behavior, Core API 1.3.0, and database schema 1.8.

# 2.1.3 - 2026-09-02

- Corrected tracked landing URLs on WordPress subdirectory installs so the site path is not duplicated when a referral click is recorded.
- Added a consistent indigo chevron to every single-choice Dreamax admin, customer-dashboard, payout, and registration dropdown, with RTL, disabled, and forced-colors handling.
- Prevented browser/password-manager autofill from populating the anti-spam honeypot and rejecting valid applications; cached legacy honeypot submissions remain protected.
- Replaced the browser-default black focus rectangle on portal headings with a clean pointer state and a branded keyboard-focus indicator.
- Fixed affiliate portal navigation overflow on tablet/mobile while preserving horizontal navigation within the sidebar.
- Prevented duplicate registration requests while submitting and replaced the completed form with a focused confirmation and affiliate-area link.
- Matched profile payout requirements to server validation and made registration approval guidance reflect the configured approval mode.
- Corrected failed-copy feedback and coupon/HTML copy labels; rejected non-web referral destinations and handled malformed dashboard URL fragments safely.
- Kept an already-generated affiliate link synchronized when its destination or campaign label changes.
- Kept dashboard panels reachable before JavaScript enhancement.


- Standardized admin hero typography across Free and Pro, fixed the Add Affiliate form overflow on narrow screens, and simplified Settings and Reports guidance.

- Redesigned the Dreamax Affiliates Pro information page with six current feature cards, a responsive Free/Pro comparison, installation status, and context-aware next steps.
- Added page-scoped Upgrade styling, aligned icons and button labels, accessible comparison headings, and guarded Pro navigation without changing program data.

- Redesigned the four-step admin Setup wizard with a premium header, progress cards, contextual guidance, responsive forms, and a clear final review.
- Aligned icons, button labels, checkbox text, and commission controls across desktop/mobile layouts while preserving setup actions, nonces, validation, page creation, and saved settings.


- Rebuilt the public affiliate registration experience as a compact progressive three-step wizard.
- Added accessible application progress, completed-step navigation, per-step native validation, Back/Continue controls, focus management, and polite status announcements.
- Kept the complete registration form visible before enhancement; step navigation and AJAX submission require JavaScript.
- Refined the registration hero, trust indicators, card density, field sizing, responsive behavior, focus states, and reduced-motion handling.
- Preserved registration field names, nonce protection, honeypot behavior, server validation, AJAX submission, stored affiliate data, Core API 1.3.0, and database schema 1.8.

# 2.1.2 — 2026-08-23

- Added the normalized `affilio_diagnostics_system_checks` filter while retaining Free capability checks, rendering, escaping, and deterministic collection order.
- Added the validated `affilio_commission_initial_state` filter with a privacy-minimized scalar context and an exact `unpaid`/null or `pending`/future-datetime state allowlist.
- Made WooCommerce qualification synchronization preserve active future holds and made restoration request a fresh initial-state decision from restoration time.
- Added Free-owned bounded held-referral release with payout exclusion, guarded `pending` to `unpaid` transition validation, conditional concurrency safety, one audit record, and one status-change event per changed row.
- Added explicit, site-local, non-autoloaded commission handoff state with an idempotent bounded single-event worker, date-preserving future scheduling, retries, and explicit withdrawal.
- Added privacy-minimized `affilio_visit_identifiers_anonymized` signals after successful retention and affiliate-erasure visit mutations.
- Increased Dreamax Affiliates Free to 2.1.2 and Core API to 1.3.0. Database schema remains 1.8; no migration is introduced, and Core API 1.2 extensions remain compatible.
- Polished affiliate referral-link validation: invalid/external destinations now clear the stale generated link, mark the destination invalid, disable Copy/Share/QR actions, close and clear any QR output, and re-enable those actions only after a valid same-site link is generated.
- Fixed manual referral validation so Commissionable amount is required in the admin form and missing/invalid amount or commission values cannot be silently converted to zero by the server-side handler.
- Polished the Manual Referral edit form to display commissionable amount and commission with two decimal places while preserving the database's existing higher-precision storage.
- Expanded Admin → Payouts search to consistently cover the visible Batch, Affiliate, Amount, Method, Destination, Status, Created and Paid columns; retained payment-reference search and fixed numeric amount false positives caused by batch-key date fragments.
- Fixed the desktop affiliate-portal sidebar sticky position so it remains below a sticky site header while scrolling long Payout Request/Payout History content, preventing the sidebar from sliding underneath the header.
- Widened only the page main container that hosts the affiliate portal to a controlled 1240px desktop frame, improving payout-table readability without affecting other pages.
- Added spacing above the Payout Request History heading and prevented payout status pills from wrapping onto multiple lines.
- Final admin Payouts polish: multi-line bank destination/account details now preserve line breaks in the payout list instead of collapsing into a single ellipsized line.
- Fixed payout-request Cancel button wrapping by enforcing a single-line compact control and reserving sufficient width for the Action column.
- Polished the affiliate payout-request Cancel control with a compact subtle border/background, clearer hover/focus states, preserved pointer cursor, and no underline.
- Clarified payout destination terminology across affiliate profile, registration, and admin edit screens without changing stored payout data.
- Payout Requests now preserves line breaks in multi-line bank destination/account details for easier administrator review.
- Refined the affiliate payout-request Cancel hover state: pointer cursor remains, while the temporary underline treatment has been removed.
- Improved affiliate payout-request Cancel affordance: clickable Cancel controls now use the pointer cursor and a subtle hover/focus underline without changing cancellation behavior.
- Polished affiliate Payouts guidance: open-request notices now use a restrained blue information box and bank-details follow-up guidance uses an amber warning/help box.
- Added payout-specific notice spacing and readability while preserving the existing red validation error and all payout-request behavior.
- Finalized affiliate payout-request UX: invalid bank-transfer details now visibly block and hide the request form until corrected.
- Existing open payout requests now suppress duplicate request controls for the same currency and show a clear cancel-or-wait message.
- Multi-currency payout requests now offer only currencies that are eligible and do not already have an open request.
- Affiliate payout-profile readiness now uses the same validated destination rules as payout request creation, so placeholder bank details no longer appear ready.
- Added server-side bank-transfer payout validation: empty account details, known placeholder copy, and a contact-email-only destination can no longer create a payout request.
- Added a clear affiliate-facing validation message directing users to Profile & Settings when bank transfer account details are missing or invalid.
- Normalized legacy bank-transfer payout-request display so a destination that duplicates the saved contact email is shown as “Not provided” while the contact email remains visible separately; stored financial records are not modified.
- Payout Requests now shows the affiliate payout/contact email separately from the stored payment destination, including existing bank-transfer requests.
- Hardened Payout Requests action controls so Approve/Reject remain compact on desktop even when global admin styles try to stretch submit inputs.
- Admin stylesheet versioning now uses the CSS file modification time on Dreamax Affiliates screens so testing/build updates do not remain hidden behind a stale browser cache.
- Polished the Payout Requests admin table with compact Approve/Reject controls, constrained action fields, balanced column widths, and improved row alignment.
- Added production-safe payout destination display fallback so empty/development placeholder destination text is shown as “Not provided”.
- Added a restrained danger treatment to the Reject action while preserving native WordPress admin button behavior and responsive full-width controls on small screens.
- Fixed affiliate payout-request UX so successful/error requests return directly to the Payouts tab instead of Overview.
- Made affiliate frontend notices panel-scoped and ephemeral so payout success feedback appears once and does not persist across tab navigation.
- Rebuilt the enhanced affiliate portal into a sidebar + workspace layout, removing the 100-row grid span that caused excessive blank scrolling on shorter tabs.
- Portal tab switches now preserve accessibility focus without browser jump and reset the viewport to the top of the portal workspace.
- Hardened the affiliate Logout button hover/focus styles against theme-level link hover overrides.
- Made Referrals status search exact: `paid` no longer matches `unpaid`; pending, unpaid, processing, paid, cancelled (and canceled alias) are treated as exact financial-status filters while other search terms retain partial matching.
- Fixed a critical error in Referrals search caused by the search query builder using `$wpdb->esc_like()` without importing the global `$wpdb` instance.
- Fixed Referrals admin search so the search field submits as a GET filter and now supports order/referral IDs (including #60 style), affiliate name/email/referral code, exact commissionable/commission amounts, campaign, coupon, currency, source, status, and manual references.
- Fixed Reports conversion-filter consistency: Not converted now reports zero referrals and zero recorded commission, referral CSV exports honor campaign/conversion filters, and report cache keys were revised so the correction is visible immediately after an in-place update.
- Enforced configured WooCommerce qualifying statuses bidirectionally: open referrals now become non-payout-eligible Pending when an order moves to an unselected pending/processing/on-hold status, and the same referral is reactivated as Unpaid when the order returns to a selected qualifying status.

## 2.1.0 — FT-4.2 Final WordPress.org Packaging
- Fixed WooCommerce lifecycle restoration: a cancelled/failed referral now returns to its recalculated open commission state when the same attributed order returns to a qualifying status, without creating a duplicate referral.
- Polished affiliate payout history: approved requests linked to paid payout batches now display Paid to affiliates, and payout history tables fit the desktop portal without unnecessary horizontal scrolling.
- Finalized the affiliate portal UI by removing the duplicate Overview referral code and making Recent Clicks/Referrals tables fit the desktop workspace while retaining deliberate mobile overflow.
- Refined the standalone Profile & Settings workspace with full-width controls, balanced two-column cards, improved field help, and scoped suppression of the redundant WordPress dashboard page title.
- Rebuilt the standalone affiliate dashboard as a premium sticky-sidebar portal with accessible, no-reload panel switching for Overview, Referral Links, Results, Creatives, Payouts, and Profile & Settings.
- Replaced the dashboard's WooCommerce Account Settings link with a fully standalone Profile & Settings section for affiliate profile and payout preferences.
- Polished the standalone affiliate registration and dashboard UI with clearer hierarchy, account/logout actions, premium responsive cards, forms, stats, tables, payout states, and accessible mobile behavior.
- Changed WooCommerce My Account integration to an optional shortcut to the standalone affiliate portal instead of embedding the dashboard in the account content area.

- Finalized public Stable tag, package metadata, translation header, changelog, upgrade notice, reviewer notes, submission checklist, and SVN deployment instructions.
- Refreshed reproducible directory illustrations to match the Free package: four-step setup, dashboard start guidance, Free commission scope, and local diagnostics/test-attribution workflows.
- Added a dedicated FT-4.2 source gate and included it in consolidated regression and release QA.
- Generated reproducible clean plugin, directory-assets, and SVN-ready packages with SHA-256 checksums and per-file manifests.
- Preserved database schema 1.8, Core API 1.2.0, all stored program data, and all validated Free workflows; no migration is introduced.
- Official Plugin Check, PHPCS/WPCS, PHPStan, PHPUnit, Composer audit, real WordPress/WooCommerce/MySQL, browser accessibility, performance, owner-account/slug review, actual submission, and SVN commit remain external or Not Run unless retained evidence exists.

## 2.0.9 — FT-4.1 Automated Validation

- Added a dependency-free automated validation runner with retained JSON evidence for security boundaries, metadata consistency, PHP/JavaScript syntax, package exclusions, CI readiness, and WordPress Plugin Check compatibility preflight.
- Added focused source contracts and PHPUnit coverage for the FT-4.1 validation boundary.
- Sanitized the registration honeypot, setup checkboxes, and diagnostic-cookie presence check before use.
- Removed the duplicate payout-batch state-changing fallback from the referrals render callback; the capability- and nonce-protected `admin_init` handler is now the single owner.
- Repaired final-release automation so artifact names, SVN tags, and exact-package verification derive from plugin metadata; `npm install` is used when no npm lock file is shipped, and Composer audit does not require a missing lock file.
- Made consolidated regression evidence derive plugin and database versions from source instead of stale hardcoded values.
- Kept database schema version 1.8 and Core API version 1.2.0 unchanged; no stored affiliate, referral, commission, payout, coupon, diagnostic, or preserved advanced setting data is migrated or rewritten.
- PHPCS/WPCS, PHPStan, PHPUnit, official Plugin Check, WordPress/WooCommerce/MySQL integration, browser accessibility, and performance execution remain `Not Run` when their required external environment is unavailable.

## 2.0.8 — FT-3.2 Diagnostics & Upgrade Path

- Added a local System Status screen covering WordPress/PHP compatibility, WooCommerce, bounded migrations, schema/tables, program pages, privacy cleanup, HTTPS, capabilities, and the Free/Pro catalog boundary.
- Added a privacy-safe support report that deliberately excludes URLs, administrator emails, filesystem paths, database credentials, table prefixes, cookies, and visitor records.
- Added a real referral-attribution test with capability and nonce protection, user-scoped temporary state, a clearly marked diagnostic campaign, server-side cookie verification, and exact test-row removal.
- Added an opt-in Dreamax Affiliates Pro submenu that reads the audited Pro catalog without bundling Pro implementation, hardcoding an unverified sales URL, or adding dashboard notices, modals, feature locks, or telemetry.
- Added diagnostics and upgrade-path responsive/RTL styles, translation strings, source-level PHPUnit contracts, dependency-free regression coverage, and consolidated evidence integration.
- Updated the feature catalog so System Status, attribution testing, and the restrained upgrade path are owned by their implemented Free administrator services.
- Kept database schema version 1.8 and Core API version 1.2.0 unchanged; no stored affiliate, referral, commission, payout, coupon, or preserved advanced setting data is migrated or rewritten.
- Real WordPress/WooCommerce/MySQL, cookie/browser, PHPCS/WPCS, PHPStan, accessibility, and official Plugin Check execution remain environment-dependent where tooling is unavailable.

## 2.0.7 — FT-3.1 Free UX & Onboarding

- Added an administrator Overview screen with program metrics, a launch checklist, pending-work visibility, an adaptive recommended action, and quick navigation.
- Reordered the Dreamax Affiliates menu around beginner tasks: Overview, Setup, Affiliates, Referrals, Reports, Coupons, Payout Requests, Payouts, and Settings.
- Preserved former `page=affilio&view=add|edit` affiliate-management URLs through a legacy route delegate.
- Improved the setup wizard with clearer progress labels, plain-language guidance, page publication/shortcode health, formatted settings summary, and a four-step launch test.
- Added an affiliate dashboard welcome panel, start checklist, simplified navigation labels, and contextual empty states.
- Added responsive and RTL styling plus focused dependency-free and PHPUnit regression coverage for the new UX contracts.
- Kept database schema version 1.8 and Core API version 1.2.0 unchanged; no stored affiliate, referral, commission, payout, coupon, or advanced setting data is migrated or rewritten.
- Real browser accessibility, WordPress/WooCommerce/MySQL, PHPCS/WPCS, PHPStan, and official Plugin Check execution remain environment-dependent where the required tooling is unavailable.

## 2.0.6 — FT-2D.2 Free Package Regression

- Added a dependency-free Free workflow regression suite covering registration configuration and validation, refund-aware commission math, coupon attribution conflicts, idempotent referral creation, manual payout grouping/locking/completion, and dashboard access boundaries.
- Added retained-source PHPUnit coverage for future Composer-enabled CI runs.
- Added FT-2D.2 evidence to the consolidated regression runner and release source QA.
- Corrected the stale real WordPress/WooCommerce integration assertion from version 2.0.0 to 2.0.6.
- Kept database schema version 1.8 and Core API version 1.2.0 unchanged.
- Real WordPress/WooCommerce/MySQL, HPOS, Checkout Blocks, browser, PHPCS, PHPStan, and official Plugin Check execution remain explicitly Not Run where the required environment is unavailable.

## 2.0.5 — FT-2D.1 Code Cleanup & Data Preservation

- Added a non-autoloaded, versioned Free-tier productization marker that records only preserved option presence and known metadata-key names.
- Added a discoverable Core data-preservation service and privacy-safe public status helper for future diagnostics.
- Added an idempotent final FT-2 migration that does not copy, normalize, delete, or rewrite advanced values.
- Removed the unreachable migration/import submenu branch from the Free administrator bootstrap.
- Corrected existing-site reactivation so pending versioned migrations run before the installed version is advanced.
- Marked bounded basic CSV exports as fully retained in Free and added Shared Core data preservation to the feature catalog.
- Added dependency-free and PHPUnit coverage proving advanced settings remain unchanged and private template content is not copied into the marker.
- Kept database schema version 1.8 and Core API version 1.2.0 unchanged.

## 2.0.4 — FT-2C.2 Fraud, Import & Email Separation

- Kept basic self-referral prevention in Free and moved blocked-domain/velocity enforcement behind typed external fraud-policy providers.
- Removed the legacy bulk affiliate and historical-referral import implementation from the Free package.
- Kept essential application, referral, and payout emails with fixed translatable content and per-event enable/disable controls.
- Added a versioned migration that copies legacy notification enablement without deleting custom templates or advanced fraud settings.
- Preserved legacy advanced data for compatible separately distributed add-ons.

## FT-2C.1 — Analytics Separation

- Increased the plugin version to 2.0.3. Database schema remains 1.8.
- Replaced the former mixed advanced analytics service with `Affilio_Report_Summary`, a bounded cached Free contract for clicks, unique visitors, conversions, referrals, and currency-separated earnings totals.
- Removed the advanced chart JavaScript, chart-specific CSS, period comparisons, and campaign/coupon/product/landing-page ranking queries from the Free package.
- Preserved raw visit campaign and attribution records; this milestone does not delete or rewrite existing data.
- Added immutable core summary keys, typed analytics-provider augmentation, provider error isolation, and administrator/affiliate extension hooks for a separately distributed add-on.
- Updated regression tooling, public claims, screenshots, governance documents, and performance benchmark naming to match the Free boundary.

## FT-2B — Commission & Payout Automation Separation

- Increased the plugin version to 2.0.2. Database schema remains 1.8.
- Removed the product/category/variation commission implementation from the Free package and activated the typed external commission-provider boundary.
- Kept global percentage, flat-per-order, and per-affiliate commission rates in Free.
- Removed holding-period settings, scheduled release hooks, and manual release controls.
- Added a one-time upgrade that releases legacy held referrals while preserving historical eligibility dates and advanced metadata.
- Kept affiliate payout requests, thresholds, manual batches, refund reconciliation, and payout status history in Free.

## FT-2A — Feature Gating & Bootstrap Separation

- Increased the plugin version to 2.0.1 and Core API to 1.2.0.
- Added a fixed Free/Shared runtime feature gate; it is product-boundary metadata, not licensing or remote entitlement logic.
- Added typed provider registries and public registration helpers for future separately distributed commission, fraud, analytics, and email-template providers.
- Stopped bootstrapping the Pro-only bulk migration service and removed its Tools submenu from the Free runtime.
- Preserved all legacy advanced options, metadata, affiliate, referral, payout, order, user, and audit data.
- Deferred mixed-module extraction for commission rules, analytics, fraud policies, and email templates to FT-2B and FT-2C.
- Database schema remains 1.8.

## FT-1 — Free/Pro Feature Audit & Architecture Plan

- Added a machine-readable Free/Pro/Shared/Deferred feature catalog.
- Added Core API 1.1 developer helpers and a discoverable feature-catalog service.
- Defined the target Free tier, Pro extraction scope, data-preservation rules, and downgrade behavior.
- Added a FeatureProvider metadata contract, audit documentation, export tooling, and regression checks.
- No user-facing feature, plugin version, or database schema changed in this planning-only milestone.

# Changelog

## 2.0.0 - 2026-08-02 (Step 13.3)

- Prepared the stable WordPress.org submission candidate and synchronized plugin header, runtime constant, public readme, Node metadata, POT metadata, tests, workflows, and SVN tag at `2.0.0`.
- Removed internal release-candidate messaging from the public WordPress.org readme.
- Added final exact-ZIP, directory-assets, screenshot-manifest, submission-metadata, and SVN-layout validation.
- Added a reproducible SVN package builder with `trunk/`, `tags/2.0.0/`, and top-level `assets/`.
- Added final beta, official-validation, reviewer-notes, SVN deployment, and Go/No-Go documentation.
- Added a final GitHub Actions workflow that gates packaging on source, integration, browser, Plugin Check, and exact-ZIP beta jobs.
- Kept database schema version `1.8`; this release adds no database migration.
- Official WordPress.org approval and environment-dependent checks are not claimed unless their retained evidence is present.

## 1.9.9 - 2026-08-02 (Step 13.2)

- Added self-created GPL-compatible WordPress.org icon, banner, and screenshot assets.
- Added reproducible asset generation and validation tooling.
- Added separate directory-assets and clean production packaging outputs with SHA-256 manifests.
- Reduced the public readme below the directory guidance threshold while retaining verified features and privacy disclosures.
- Excluded WordPress.org assets and asset-source tooling from the installable runtime ZIP.
- Kept database schema version 1.8.

## 1.9.8-rc.1 - 2026-08-02 (Step 13.1)

- Reworked `readme.txt` into a WordPress.org-oriented public document with clearer requirements, workflows, manual-payout boundaries, privacy behavior, support guidance, and concise feature claims.
- Added explicit external-service disclosure for user-triggered Facebook, LinkedIn, X/Twitter, WhatsApp, and email sharing, plus administrator-configured external creative images. QR generation remains local.
- Added public support documentation and detailed source-package documents for dependency licensing, source/build reproduction, external services, compatibility/deprecation, and WordPress.org compliance.
- Added a translated administrator warning beneath external creative-image URLs so site owners understand the browser request and privacy boundary.
- Added Step 13.1 readme/compliance smoke checks and release-candidate workflow metadata.
- Removed the generic Plugin URI instead of inventing an unverified product-specific URL. Database schema remains `1.8`.
- This is still a release candidate. Final account/contact decisions, real screenshots/assets, official Plugin Check evidence, beta execution, and SVN publication remain later Step 13 gates.

## 1.9.7-rc.1 - 2026-08-02 (Step 12C.4)

- Added one evidence-producing final regression runner that executes every dependency-free milestone suite, syntax check, QR fixture, package check, and release-candidate source invariant in a fixed order.
- Added explicit Passed, Failed, Skipped, and Not Run reporting with command, duration, environment, and exit-code evidence; unavailable Composer, WordPress, WooCommerce, MySQL, browser, and official-tool gates are never reported as passed.
- Added a release-candidate source audit covering hook wiring, HPOS/Checkout Blocks declarations, WooCommerce CRUD order access, ownership protection, restartable upgrades, multisite isolation, cache invalidation, bounded exports, packaging exclusions, and documentation consistency.
- Added real WordPress/WooCommerce full-workflow integration-test source plus browser regression specifications for registration validation, affiliate dashboard controls, console errors, responsive overflow, and core administrator screens.
- Added a consolidated release-candidate CI workflow and a real-environment execution matrix for PHP 8.0/8.4, WordPress, WooCommerce, HPOS, Checkout Blocks, multisite, object cache, Plugin Check, PHPCS, PHPStan, accessibility, and performance evidence.
- Updated version parsing and package metadata to support semantic pre-release versions such as `1.9.7-rc.1`; database schema remains `1.8`.
- This is a release candidate, not the final WordPress.org submission package. Step 13 remains responsible for final public documentation, directory assets, official-tool evidence, beta sign-off, and SVN preparation.

## 1.9.6 - 2026-08-02 (Step 12C.3)

- Added a site-scoped, generation-based performance cache with persistent object-cache support, transient fallback, deterministic keys, bounded TTLs, and constant-time invalidation.
- Avoided per-visit option writes by using short visit/analytics cache expiry while invalidating on lower-frequency affiliate, referral, payout, coupon, and settings changes.
- Added query-specific composite indexes for affiliate status, referral history/release/report filters, visit reporting, payouts, payout requests, and audit events; increased the database schema marker to `1.8`.
- Added keyset pagination for affiliate, referral, and visit export batches, batched affiliate display-name loading, and joined bounded selectors to reduce growing OFFSET scans and N+1 queries.
- Normalized potentially large configuration options to non-autoloaded storage and added a migration for existing sites.
- Added deterministic local-only fixtures for 1,000 affiliates, 50,000 referrals, 100,000 visits, 5,000 payouts, and 10,000 audit events.
- Expanded the JSON benchmark harness with cold/warm measurements, query/memory evidence, dataset counts, autoload diagnostics, required-index inventory, cache statistics, and error capture.
- Added dependency-free Step 12C.3 runtime/source checks and real WordPress/MySQL integration-test source. Real environment benchmarks are not claimed as executed in this package.

## 1.9.5 - 2026-08-02 (Step 12C.2)

- Added bounded network synchronization for existing sites without synchronous full-network activation loops.
- Added modern `wp_initialize_site` and `wp_uninitialize_site` lifecycle handling for new and deleted sites.
- Added per-network version/progress state, main-site cron continuation, manual Network Admin batch processing, and safe retry state.
- Added site-context restoration with `try/finally`, per-site table/option isolation, and network-aware WooCommerce activation detection.
- Added resumable network uninstall cleanup with per-site data-retention preferences and deletion blocking when another safe batch is required.
- Added dependency-free multisite runtime/source checks and real multisite integration-test coverage.

## 1.9.4 - 2026-08-02 (Step 12C.1)

- Added a bounded, versioned migration runner that persists completed steps, resumes interrupted upgrades, and records safe failure metadata without storing exception messages or server paths.
- Delayed the installed-version update until all schema, onboarding, and lifecycle migration steps complete.
- Replaced the legacy timestamp upgrade lock with owner-aware atomic locking and explicit stale-lock cleanup.
- Centralized current-site lifecycle ownership for cron hooks, temporary options, transients, mutexes, settings, metadata, creatives, and custom tables.
- Kept deactivation non-destructive while preserving resumable migration state and clearing scheduled jobs, caches, notices, and stale locks.
- Kept uninstall preserve-by-default, added explicit destructive cleanup, and changed creative deletion from an unbounded query to bounded batches.
- Added dependency-free migration interruption/recovery tests plus real WordPress upgrade, deactivation, preserve-data, and destructive-uninstall integration coverage.
- Kept database schema version 1.7.

## 1.9.3 - 2026-08-02 (Step 12B)

- Made Classic and Store API attribution idempotent so duplicate checkout hooks cannot overwrite the first completed attribution decision.
- Added stale-safe atomic locks and WooCommerce refund completion markers for cumulative, retryable, duplicate-safe partial and full refund handling.
- Locked processing referrals during payout completion, synchronized the final payout amount from its ledger rows, bounded batch size, and added auditable failed-payment handling that safely releases referrals.
- Added a database-backed mutex around commission-hold cron releases and conditional rollback helpers for non-transactional hosts.
- Expanded WordPress/WooCommerce integration coverage for commission base policy, cumulative refunds, payout completion/failure, cron concurrency, HPOS, and Checkout Blocks hook wiring.
- Kept database schema version 1.7.

## 1.9.2 - 2026-08-02 (Step 12A)

- Closed refund and cancellation race windows with conditional open-referral writes, locked payout recalculation, duplicate-event handling, and immutable paid-ledger reconciliation signals.
- Added one shared unsigned-BIGINT identifier normalizer and applied it to manual referrals, referral lookup, report search, and CSV imports without platform-sized integer casts.
- Replaced unbounded affiliate and privacy export paths with bounded batches; privacy callbacks now page referrals, events, payouts, and payout requests, and affiliate CSV export avoids per-row user lookups.
- Rejected binary/control-character CSV uploads while retaining file-size, row-count, header, width, and spreadsheet-formula protections.
- Added WPCS/PHPCompatibility, PHPStan, PHPUnit, real WordPress/WooCommerce integration, HPOS/multisite matrices, official Plugin Check, seeded Playwright/axe accessibility, and seeded query/time/memory benchmark automation.
- Added a fail-closed clean production ZIP builder, dependency-free security/package checks, and explicit evidence manifests. No database schema migration was required; the schema marker remains `1.7`.

## 1.9.1 - 2026-08-02

- Finalized the Step 11 affiliate-dashboard information architecture with section navigation, semantic task sections, description-list summaries, responsive tables, and localized display values.
- Made registration shortcodes repeatable on the same page through unique IDs, exact field-level AJAX error association, dynamic payout-detail requirements, native validity checks, busy states, and restored submit labels.
- Improved administrator registration-field, coupon, onboarding, report, payout, and audit interfaces with table captions, row scopes, explicit labels, and responsive wrappers.
- Added an official-documentation competitor UX benchmark covering AffiliateWP, SliceWP, and Easy Affiliate without copying proprietary code or interface assets.
- Expanded dependency-free Step 11 regression checks. The database schema remains at `1.7`.

## 1.9.0 - 2026-08-02

- Added translated labels for stored affiliate, referral, payout, request, source, and payment keys.
- Added frontend and admin RTL stylesheets with conditional WordPress RTL loading.
- Improved keyboard focus, live-region announcements, form busy states, QR disclosure behavior, and accessible error handling.
- Added semantic dashboard landmarks, explicit labels, table captions, column scopes, chart descriptions/data relationships, and required-field guidance.
- Added labelled and responsive administrator payout-request actions plus accessible audit-history tables.
- Added a privacy-conscious system-information report and contextual support checklist; diagnostic data is never transmitted automatically.
- Added high-contrast, forced-colors, reduced-motion, and responsive layout support.
- Added Step 11 accessibility, localization, and manual QA documentation.

All notable changes to Dreamax Affiliates are documented here.

## 1.8.3 — 2026-08-02

### Added

- Native and network-specific sharing actions for generated affiliate links.
- Local QR-code matrix generation, browser SVG rendering, and SVG download; referral URLs are not sent to a remote QR service.
- Responsive SVG trend charts for clicks, conversions, referrals, and up to three commission currencies.
- Current-versus-previous-period comparison cards and accessible daily data tables.
- Top-product analytics through WooCommerce CRUD APIs and top-coupon analytics from referral records.
- Bounded analytics windows, cached and limited product aggregation, QR fixture verification, Step 10C smoke tests, and third-party notices.

### Changed

- Registered a reusable analytics service for the affiliate dashboard and administrator Reports screen.
- Frontend dashboard assets now load QR and analytics scripts only where the affiliate dashboard is present.
- Kept database schema version `1.7`; Step 10C has no database migration.

### Verification scope

- PHP and JavaScript syntax, prior smoke suites, Step 10C source/logic checks, deterministic QR matrix fixtures, translation generation, and package integrity are executed for this milestone.
- Real browser sharing, physical-device QR scanning, WordPress/WooCommerce database queries, accessibility tools, PHPCS/WPCS, Plugin Check, and production performance remain staging/manual gates.

## 1.8.2 — 2026-08-01

### Added

- Manual referral creation, editing, controlled status changes, descriptions, reasons, and activity history.
- Configurable WooCommerce qualifying order statuses and a 0–365 day commission holding period.
- Bounded hourly and protected manual release of eligible pending commissions.
- Affiliate payout requests with minimum threshold, per-currency balances, payout-detail validation, history, and ownership-safe cancellation.
- Administrator payout-request approval/rejection workflow that creates existing manual payout batches.
- Referral-status and payout-request email notification templates.
- Payout-request privacy export/anonymization and uninstall lifecycle coverage.

### Changed

- Increased the database schema marker to `1.7` for manual-referral fields and the payout-request table.
- WooCommerce referral creation now follows configured qualifying statuses and initial holding state.
- Manual order IDs are validated as numeric strings to avoid unsafe `BIGINT` integer comparisons.
- Payout batches continue to accept only eligible `unpaid` referrals.

### Verification scope

- PHP/JavaScript syntax, metadata, dependency-free release/runtime/architecture/Step 10B smoke checks, translation generation, package integrity, and extracted-package checks are included.
- Real WordPress/WooCommerce database, cron, email, concurrency, accessibility, Plugin Check, and WPCS acceptance tests remain pending.

## 1.8.1 — 2026-08-01

### Added

- Complete administrator affiliate creation for existing or new WordPress users.
- Affiliate profile editing, validated approval/rejection/suspension/ban/reactivation transitions, bulk status actions, reasons, activity history, and protected record removal.
- Configurable registration fields for website, promotion method, social profile, and application message.
- Field enable, required, and ordering controls plus the `affilio_registration_antispam_validate` extension point.
- Configurable plain-text notifications for applications, status changes, referrals, payout batches, and paid payouts.
- Privacy-conscious affiliate audit events and new application-profile export/erase coverage.

### Changed

- Extended affiliate statuses with `suspended` and `banned`.
- Increased the database schema marker to `1.6.1` for affiliate metadata and the audit-events table.
- Preserved existing WordPress users when an affiliate record is removed.

### Verification scope

- PHP syntax, JavaScript syntax, metadata, dependency-free smoke tests, and package integrity are checked in this milestone.
- Real WordPress/WooCommerce, email delivery, accessibility, PHPCS, and official Plugin Check remain pending.

## 1.8.0 — 2026-08-01

### Added
- Versioned Core API `1.0.0` for Free/Pro compatibility checks.
- Dependency-free runtime autoloader and Composer PSR-4 mapping for new `Affilio\` classes.
- Lightweight service registry and stable `affilio_service_registered` and `affilio_core_ready` hooks.
- Execution-context detection for frontend, human admin, AJAX, cron, REST, and CLI requests.
- Central affiliate, referral, and payout status models with documented normal and recovery transitions.
- Database-backed upgrade lock and pending-upgrade orchestration around existing idempotent migrations.
- Architecture documents for ledger migration, audit events, lifecycle, and deprecation.
- Dependency-free Step 9 architecture smoke checks and PHPUnit test definitions.

### Changed
- Human admin-screen services are no longer constructed for AJAX, cron, REST, or CLI contexts.
- Key database/admin status allowlists now use the centralized state models.
- The legacy singleton remains available while also exposing registered services through `Affilio::service()`.

### Database
- No schema changes. Database version remains `1.6`.
- The proposed multi-beneficiary commission ledger and audit-event table remain gated by explicit human approval.

## 1.7.0 — 2026-08-01

### Added
- Graceful minimum WordPress and PHP compatibility checks.
- WooCommerce Cart/Checkout Blocks compatibility declaration alongside HPOS.
- SQL-level affiliate search, sorting, filtering, and pagination.
- Affiliate registration-date database index.
- Explicit coupon-editor nonce verification.
- Configurable click-velocity threshold and rolling time window.
- Explicit preserve-or-delete uninstall setting.
- Translation POT file, security policy, privacy notes, hook reference, release QA, manual QA, and WordPress.org submission documentation.

### Changed
- New affiliate accounts receive the password email and login cookie only after the affiliate application is successfully stored.
- Plugin data is preserved by default during deletion; destructive cleanup is opt-in, while temporary Dreamax Affiliates transients are cleared.
- Version, readme, compatibility, privacy, and packaging metadata updated for the 1.7.0 release candidate.

### Fixed
- Prevented a failed affiliate database insert from leaving a newly created user logged in or sending a misleading account email.
- Removed PHP-side full-table loading from the affiliate administration list.

## 1.6.0
- Added affiliate coupons, attribution priority, conflict protection, creatives, campaign/source reporting, backup exports, and CSV migration.

## 1.5.0
- Added affiliate/product/variation/category commission rules and partial-refund adjustment.

## 1.4.0
- Added onboarding, automatic pages, and WooCommerce My Account integration.

## 1.3.0
- Added reporting, link generation, campaign tracking, unique-visitor measurement, and CSV exports.

## 1.2.0
- Added manual payout management and payout history.

## 1.1.0
- Added registration hardening, secure attribution, privacy controls, and exporter/eraser support.

## 1.0.1
- Fixed integration initialization and strengthened duplicate-attribution protection.

## 1.0.0
- Initial development release.
