# Security Policy

## Supported version

Security fixes are provided for the latest maintained Dreamax Affiliates release. Site owners should keep WordPress, PHP, WooCommerce, Dreamax Affiliates, and related plugins updated.

## Reporting a vulnerability

Report suspected vulnerabilities privately through the security contact published by Dreamax Soft. If the dedicated security contact is unavailable, contact Dreamax Soft through its official website and clearly mark the message as a private Dreamax Affiliates security report.

Do not publish vulnerability details in the WordPress.org support forum, a public issue tracker, social media, or a review before coordinated disclosure.

Include:

- Dreamax Affiliates version
- WordPress, PHP, and WooCommerce versions
- HPOS, Checkout Blocks, and multisite status where relevant
- Reproduction steps
- Required user role or capability
- Expected and actual behavior
- A minimal proof of concept with sensitive information removed
- Whether the issue was reproduced on a clean staging installation

Do not include live customer data, credentials, API keys, payout details, destructive payloads, or a database copy containing personal data.

## Scope

Useful reports include authentication or authorization bypass, insecure direct object access, CSRF, XSS, SQL injection, unsafe file handling, sensitive-data exposure, privilege escalation, payout-state manipulation, attribution tampering, replay/idempotency defects, and reliable denial-of-service conditions caused by Dreamax Affiliates.

The following are normally outside scope unless they demonstrate a concrete Dreamax Affiliates vulnerability:

- Findings that require an already compromised administrator account
- Missing security headers controlled by the web server
- WordPress, WooCommerce, PHP, browser, or hosting vulnerabilities not introduced by Dreamax Affiliates
- Social engineering, spam, or denial-of-service traffic against infrastructure not operated by Dreamax Affiliates
- Claims based only on automated scanner output without reproduction

## Coordinated disclosure

Allow reasonable time to reproduce, fix, test, and distribute a correction. Do not access data that is not yours, disrupt a live service, or retain personal information. No bounty or payment is promised unless a separate written program explicitly states otherwise.

## Security update policy

Confirmed high-impact vulnerabilities are handled as release-blocking defects. A corrective release may limit public changelog detail until users have had a reasonable opportunity to update. Supported public APIs are preserved where safely possible, but security fixes may require behavior changes.

## Extension-boundary guarantees

Public diagnostics contributions are normalized and rendered only through the existing capability-protected, escaped System Status screen. Commission extensions cannot request arbitrary states or past eligibility dates; held-referral release remains bounded, payout-safe, conditionally written, and owned by Free's transition and audit service. Visit-anonymization notifications contain only a retention cutoff or affiliate row ID and never expose raw IP addresses, visitor hashes, email addresses, or browsing payloads.
