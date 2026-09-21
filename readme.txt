=== Dreamax Affiliates ===
Contributors: dreamaxsoft
Tags: affiliate, referral tracking, woocommerce, commission, payouts
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run a self-hosted WooCommerce affiliate program with referral tracking, coupons, commissions, reports, creatives, and manual payouts.

== Description ==

Dreamax Affiliates helps WooCommerce stores manage an affiliate program inside WordPress. Program data remains in the site's database; there is no required external account, telemetry service, or automatic payout provider.

Affiliates can apply, create referral links, use assigned coupons and creatives, view performance, maintain payout details, request eligible payouts, and review payout history. Administrators can manage affiliates, global or affiliate commission rates, basic reports, refunds, diagnostics, and manual payout batches.

= Key features =

* Guided setup, beginner-friendly Overview, and launch checklist
* Affiliate applications, approval states, configurable fields, throttling, and anti-spam extension points
* Opaque referral tokens with first-click or last-click attribution
* Classic checkout and Cart/Checkout Blocks attribution
* WooCommerce coupon attribution with configurable priority
* Referral links, campaign labels, user-triggered sharing, and locally generated QR downloads
* Standalone affiliate portal with sticky desktop navigation, responsive results tables, profile/settings, optional WooCommerce My Account shortcut, creatives, assigned coupons, and payout history
* Basic click, unique-visitor, conversion, referral, and commission summaries with detailed logs
* Global percentage or flat-per-order commission, with optional per-affiliate overrides
* Full and partial refund recalculation with duplicate-event protection
* Manual referral creation, qualifying order statuses, refund synchronization, and payout eligibility
* Affiliate payout requests and locked manual payout batches by affiliate and currency
* Self-referral prevention and extension-ready fraud policy hooks
* Bounded CSV export for click and referral records
* Optional IP anonymization, retention cleanup, and WordPress privacy export/erase integration
* WooCommerce HPOS and Cart/Checkout Blocks compatibility declarations
* Translation-ready strings, RTL styles, keyboard support, and accessible feedback states
* Local System Status diagnostics and a removable real-path attribution test
* Restrained opt-in Pro information page with no dashboard nags or feature locks

Dreamax Affiliates records payout activity but does not send money automatically. It does not connect to PayPal Payouts, Stripe Connect, or banking APIs. Administrators pay affiliates outside the plugin and then record the payment reference and status.

= Requirements =

* WordPress 6.4 or later
* PHP 8.0 or later
* WooCommerce 8.2 or later for order attribution, commissions, coupons, and refunds

= Privacy and external services =

Dreamax Affiliates does not automatically send affiliate, referral, payout, analytics, or diagnostic data to a Dreamax Soft-owned service.

User-triggered social sharing runs only after the affiliate chooses a destination; a share request occurs only after that action. The generated referral URL and optional share text are passed to Facebook, LinkedIn, X, WhatsApp, the browser's native share interface, or the configured email application. Each destination applies its own terms and privacy policy. Dreamax Affiliates does not receive the share result.

A store manager may configure a creative with an external image URL. The affiliate's browser then requests that image from the remote host, which may receive ordinary connection data. Using the WordPress Media Library avoids that external request.

QR codes are generated locally in the browser with a bundled GPL-compatible dependency. Email notifications use the site's configured WordPress email system. Dreamax Affiliates does not download or execute remote plugin code.

== Developer extension API ==

Dreamax Affiliates Core API 1.3.0 adds four additive Free-owned extension boundaries. Existing Core API 1.2 extensions remain compatible.

* `affilio_diagnostics_system_checks` filters the final local diagnostics collection. Entries are normalized to `status`, `label`, `detail`, `action_url`, and `action_label`; allowed statuses are `good`, `warning`, and `critical`.
* `affilio_commission_initial_state` filters the default `unpaid`/`null` state and receives a privacy-minimized scalar qualification context. Free accepts only `unpaid`/`null` or `pending` with a strictly valid future site-local MySQL datetime.
* The `application.commission_approval` service exposes bounded `release_eligible( $limit )`, plus explicit `request_handoff()`, `withdraw_handoff()`, and `is_handoff_requested()` operations. Handoff is site-local, inactive by default, preserves stored eligibility dates, and schedules only while explicitly requested.
* `affilio_visit_identifiers_anonymized` fires after a successful visit-identifier mutation with one minimal context array: retention cleanup supplies `scope` and `cutoff`; affiliate erasure supplies `scope` and `affiliate_id`.

Extensions should use the commission-approval service rather than updating referral status directly. Free retains transition validation, conditional database writes, auditing, and `affilio_referral_status_changed` dispatch.

== Installation ==

1. Install the ZIP through **Plugins → Add New → Upload Plugin**, or upload the `dreamax-affiliates` folder to `/wp-content/plugins/`.
2. Activate Dreamax Affiliates.
3. Complete **Dreamax Affiliates → Setup**.
4. Review attribution, commission, privacy, payout, and uninstall settings.
5. Test checkout attribution, refunds, reports, and manual payouts on a staging site.

== Frequently Asked Questions ==

= Does Dreamax Affiliates work without WooCommerce? =

Registration, standalone dashboards, payout profiles, creatives, and some reporting remain available. Order attribution, coupon integration, commissions, and refunds require WooCommerce.

= How is a sale attributed? =

A valid referral visit creates a visit record and opaque browser token. During checkout, Dreamax Affiliates stores the matched attribution on the WooCommerce order. A referral is created when the order reaches a configured qualifying status.

= Can a coupon credit an affiliate without a referral cookie? =

Yes. Assign an existing WooCommerce coupon to an active affiliate. If coupons assigned to different affiliates appear on one order, the order remains unattributed to avoid ambiguous credit.

= How are commissions calculated? =

A site default percentage or flat-per-order rate can be overridden for an individual affiliate. WooCommerce commissions are calculated from eligible order line values and synchronized with full or partial refunds. Product, category, variation, and holding-period automation require a separately distributed add-on.

= What happens after a refund? =

Unpaid and processing referrals are recalculated from the remaining commissionable value. Paid referrals remain immutable and the order is flagged for manual reconciliation.

= How do payouts work? =

Affiliates may request an eligible unpaid balance after reaching the configured threshold. Administrators create or approve a manual batch, pay outside Dreamax Affiliates, record a reference, and mark the batch paid.

= Can data be removed when the plugin is deleted? =

Program data is preserved by default. Enable **Delete data on uninstall** before deleting the plugin to remove Dreamax Affiliates-owned tables, options, creatives, transients, and metadata. WordPress users and WooCommerce orders are never deleted.

= How do affiliates receive login access? =

An applicant who is not already signed in receives a lowest-privilege WordPress Subscriber account. Their email address is the sign-in username, and WordPress sends a secure link for choosing a password; Dreamax Affiliates never displays or emails a generated password. Existing WordPress or WooCommerce customers keep their current credentials. Registration and status screens provide dashboard, login, and password-recovery guidance.

= When do affiliates add payout details? =

The public application collects only account and promotion information. After approval, an affiliate adds or changes their payout method and destination from the authenticated affiliate dashboard. A payout request cannot proceed without a valid destination.

= Where can I get support? =

After publication, use the WordPress.org support forum for non-sensitive questions. Include versions and reproducible steps, but never post credentials, payout details, customer data, or vulnerability details publicly.

== Screenshots ==

1. Dreamax Affiliates Overview with program metrics, launch checklist, readiness guidance, and quick actions.
2. Affiliates management with searchable accounts, referral codes, commission information, payout methods, account states, and registration dates.
3. Referrals with attributed orders, commissionable amounts, commissions, referral sources, payout status, and dates.
4. Affiliate Reports with filters, clicks, unique visitors, conversions, conversion rate, referrals, recorded commission, detailed click logs, and CSV exports.
5. Payout Requests with affiliate withdrawal requests, payout destinations, request states, notes, dates, and review workflow.
6. Payouts with manual payout batches, affiliates, amounts, payout methods, destinations, payout states, creation dates, and paid dates.
7. Diagnostics with privacy-safe local system-readiness checks and the removable Test Attribution workflow.
8. Settings with affiliate-page integration, WooCommerce My Account shortcut, approval behavior, referral duration, attribution, coupon priority, privacy, retention, commissions, payout requests, email notifications, and uninstall controls.

== Changelog ==

= 2.1.6 =
* Replaced the signed-out dashboard notice with a responsive affiliate access card and clear account actions.
* Connected dashboard sign-in and password recovery to the branded affiliate account experience.
* Recognized safe internal dashboard redirects so older or cached login links also receive affiliate branding.
* Added affected referral IDs to payout completion, failure, and cancellation hooks for compatible extension synchronization.
* Kept ordinary WordPress login screens, authentication behavior, Core API 1.3.0, and database schema 1.8 unchanged.

= 2.1.5 =
* Corrected the logged-in single-step application layout so its heading and fields retain the intended full-width hierarchy.
* Refined the completed-application screen and removed redundant progress controls after submission.
* Rebuilt pending and restricted affiliate status cards with clearer review progress, sign-in context, account-security access, and responsive actions.
* Added a Dreamax-branded login and password-reset experience scoped to affiliate-generated access links, including branded WordPress password emails.
* Preserved WordPress authentication, secure reset keys, Subscriber roles, Core API 1.3.0, and database schema 1.8.

= 2.1.4 =
* Rebuilt registration as a compact, responsive account and application flow with clearer content, balanced fields, explicit optional labels, and signed-in account context.
* Moved payout setup to the authenticated affiliate dashboard so applicants provide payment details only after joining; payout requests still require a valid destination.
* Added a clear post-registration account-access card with the affiliate's sign-in email, current-session guidance, affiliate-area link, and secure password recovery action.
* Added direct Login and Set/Reset Password actions when an applicant's email already belongs to a WordPress account.
* Expanded affiliate status emails with sign-in, dashboard, and password recovery details while continuing to use WordPress's secure password setup flow.
* Kept generated passwords private and preserved the existing Subscriber role, approval workflow, Core API 1.3.0, and database schema 1.8.

= 2.1.3 =
* Rebuilt affiliate registration as a compact, accessible three-step application wizard.
* Added per-step required-field validation, progress feedback, Back/Continue navigation, and a no-JavaScript fallback.
* Refined desktop and mobile registration layouts without changing stored affiliate data, Core API 1.3.0, or database schema 1.8.

= 2.1.2 =
* Added normalized diagnostics and commission initial-state extension filters.
* Added hold-aware WooCommerce qualification and Free-owned bounded release/handoff operations that preserve future eligibility dates.
* Added privacy-safe visit-anonymization notifications after successful mutations.
* Increased Core API to 1.3.0 while preserving database schema 1.8 and Core API 1.2 extension compatibility.

= 2.1.1 =
* Added WordPress.org plugin banners, icons, and eight updated product screenshots.
* Refreshed public plugin documentation and screenshot captions.
* No database migration or functional workflow changes.

= 2.1.0 =
* Finalized WordPress.org public metadata, refreshed Free-tier directory illustrations, clean submission ZIP, assets package, and dynamic SVN layout.
* Added exact package, manifest, checksum, screenshot-source, and Go/No-Go evidence for the final productization milestone.
* Preserved database schema 1.8, Core API 1.2.0, existing program data, and all validated Free workflows.

= 2.0.9 =
* Added automated security, metadata, package, CI, and Plugin Check compatibility preflight evidence.
* Hardened request sanitization and corrected release automation without changing stored data.

= 2.0.8 =
* Added privacy-safe System Status, removable Test Attribution, and a restrained Pro information page.

= 2.0.7 =
* Added a beginner-friendly Overview, ordered navigation, setup wizard, launch checklist, and affiliate start guide.

= 2.0.6 =
* Added Free workflow regression coverage and corrected stale integration-test metadata.

= 2.0.5 =
* Added privacy-safe Free-tier data-preservation status and cleanup without changing stored program data.

= 2.0.4 =
* Separated advanced fraud, import, and editable email-template implementation while preserving legacy data.

= 2.0.3 =
* Replaced advanced visual analytics with bounded Free summaries while preserving stored campaign and referral data.

= 2.0.2 =
* Separated advanced commission automation while retaining Free commission and payout workflows.

= 2.0.1 =
* Added the fixed Free/Shared feature gate and stopped bootstrapping Pro-only migration tools.

= 2.0.0 =
* Prepared the original stable packaging baseline and retained database schema 1.8.

For older changes, see `CHANGELOG.md` in the development source package.

== Upgrade Notice ==

= 2.1.6 =
Adds a polished signed-out affiliate entry, branded dashboard login handoff, and richer payout lifecycle hooks. Authentication and stored data are unchanged.

= 2.1.5 =
Polishes logged-in registration, affiliate status, and password-reset screens. Authentication behavior and stored data are unchanged.

= 2.1.4 =
Introduces a refined two-stage application, moves payout setup to the authenticated dashboard, and clarifies secure account access. No database migration or stored-data rewrite is required.

= 2.1.3 =
Adds a compact progressive registration wizard with no database migration or stored-data rewrite.

= 2.1.2 =
Adds dormant public extension boundaries and date-preserving commission-handoff safeguards. Database schema remains 1.8.

= 2.1.1 =
WordPress.org directory assets and documentation refresh. No database migration or stored-data rewrite is introduced.
