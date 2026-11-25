# Changelog

All notable changes to this project are in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file lists the changes to the code for each release. The release notes for users are on the [release notes page](https://byomailer.com/docs/getting-started/release-notes) of the docs.

## [Unreleased]

### Added

- The OpenAPI document in `docs/api/openapi.yaml`, with a Postman collection and a Postman environment. Each API endpoint has a summary, a description and its error responses.

### Changed

- MCP: the server instructions, the tool descriptions and the error messages give the tool names that `tools/list` returns. Before, many texts named tools without the `-tool` suffix, for example `create-newsletter` for `create-newsletter-tool`. The tool names did not change.

- API and MCP: an update of a contact never changes its subscription. Only an add to a list or the new `POST /api/v1/contacts/{contactId}/subscribe` subscribes a contact again that an admin unsubscribed.

- Double opt-in: a list filter skips a contact of an opt-in list until the contact confirms. The dashboard, the automations, the API, MCP and the imports all send the opt-in email when they add a contact to an opt-in list. The topics of an opt-in list wait for the confirmation.

- A paused sequence or step keeps its due emails pending. When it goes live again, the emails that came due send within minutes. Before, the sender deleted them, and the contact stopped in the sequence.

- Contacts: for an address that bounced, **Subscribe** asks for a confirmation and removes the address from the suppression list. For an address that complained, the page hides **Subscribe**. Before, the contact looked subscribed but got no email.

- An image that you upload in the editor goes to your own S3 storage when you set one up, the same as the gallery. Before, it always went to the `public` disk.

- The scheduler sends the weekly summary email again, on Friday at 17:00 in the time zone of the account. It goes through the system mailer to each active team member.

- Visual automations enroll contacts. A list, tag or sequence change, or a finished sequence, enrolls the contact in each live visual automation with a matching trigger. Before, no code enrolled a contact.

- The MCP guide says that Claude.ai is not supported, because its connectors need OAuth and the server accepts only an API token.

- Newsletters: the wizard asks before a step change drops unsaved edits. "Send now" and "Schedule send" show the number of contacts, the email identity and the send time before they start. "Pick winner" asks for a confirmation. "Schedule send" and "Save draft" show a message. The newsletter list scrolls sideways on a phone.

### Removed

- Plausible analytics. The app loads no analytics script.
- The in-app release notes page and `resources/release-notes.json`. `/documentation/release-notes` now redirects (301) to the release notes page of the docs on byomailer.com.

### Fixed

- Contact import: after the upload, the list page opens with a message that gives the number of contacts and of skipped rows. The Imports table updates while the import runs and shows the skipped rows. A file with no email column or no valid email shows an error under the file field. The import also accepts the column names `E-mail` and `Email address`.
- Automations and sequences: **Go live** on a visual automation saves the unsaved changes first. When the server blocks a status change, the page shows the reason. The activate dialog of a sequence or step counts the contacts and the due emails. The status switches, the step buttons and the selects have accessible names, and the automation rows show a focus ring.
- Team members share the contacts, lists, tags, topics and contact attributes of the client.
- Sign-up and onboarding: the login page links to the sign-up page when registration is open. The AWS credentials step shows each validation error under its field, and tells the user to reload the page when the session expired. The two-factor code inputs have labels for screen readers.
- Settings: the delete of an API token, the disable of two-factor authentication, new recovery codes and the archive of a team member ask for a confirmation first. Viewers see the Storage and AWS credentials actions disabled, with a note. Only admins see Webhooks in the settings menu. A failed quota refresh shows its error.
- The preference center shows a contact who unsubscribed from all emails as unsubscribed, with a "Subscribe again" button. Before, it showed each topic as subscribed. The profile page puts the time zone, the postal address and the sunset rule under "Team settings". The instance owner sees why the account cannot be deleted.
- Team members share the AWS credential, the sequences and the automations of the client. An automation runs for each contact of the client, also for a contact that another member added.
- The unsubscribe links work for an email that a team member sent and for an opt-in email.
- New SES keys keep the SNS topic, the tenant and the permission checks of the credential.
- A new sender identity sends to SES only the tracking events that the wizard chose.
- The variables inside a block get their values. An array attribute value renders as JSON.
- The variable detection reserves `postal_address`.
- The automation validation refuses the type `multistep`, which never ran.
- The Docker health check passes when `APP_URL` has a public domain.
- The `ses-events` worker confirms the SNS subscription when the SQS queue is in another AWS account.
- The AWS onboarding no longer asks for `SNS_WEBHOOK_ENDPOINT`. The contacts page and the blocked digest email have correct text.
- An A/B test fires the `newsletter.sent` webhook event one time, when the dashboard or the API picks the winner.
- API: `PUT /contacts/{contactId}` accepts the current email. A draft newsletter keeps its `email_identity_id`. The sequences count completed contacts.
- OpenAPI: each operation has a unique `operationId`. The lists show `links` and `meta`. The sequence `status` filter shows its values. The sequence list and the sequence record have one schema each. `current_only` of the step mail export is deprecated, because the export ignores it.
- Email logs: the table scrolls on a phone, the filters and page buttons have names for screen readers, and a filter with no results says so. Screen readers announce toasts. The sidebar keeps a section active on its detail pages. The reputation rates name the review and pause levels in text.
- Filters: if a filter does not save, its error shows under the filter and the editor stays open. The API and the dashboard give plain errors for an empty tag, list, topic or attribute filter. The Enter key on the Archive button no longer opens the segment. The segment tables scroll on a phone. A double click in the topic dialog creates one topic.
- Contacts: **Unsubscribe** and the removal of an attribute ask for a confirmation. The remove buttons on tags and lists, the copy button and the attribute buttons work with the keyboard and on phones. The contacts table marks unsubscribed contacts, and an empty filter result offers **Clear filters**.
- The two light text colors (`--bm-text-3` and `--bm-text-4`) are darker, so small labels, dates and counts reach the WCAG AA contrast of 4.5:1.
- Email identities: you cannot remove an email identity while a scheduled or sending newsletter, a live sequence step or a list opt-in email uses it. The remove dialog lists these items. The add wizard shows why a local part or a MAIL FROM domain is not valid. The pages work on a phone, and the removal of a custom tracking or link domain asks for a confirmation.

### Security

- The Viewer role has read-only access in the dashboard, the API and the MCP server.
- A Viewer cannot change the time zone, the sunset policy or the postal address of the account on the profile page.
- API tokens have a read or a read-and-write access level and an optional expiration.
- The contact mail page shows the email HTML in a sandboxed frame.
- The app escapes contact values in the HTML part of an email.
- The contact CSV export escapes cells that start with a formula character.
- The REST API has a rate limit of 300 requests a minute for each user (`API_RATE_LIMIT`). The test email buttons in the dashboard have the same limit as the API test email: 5 a minute.
- A contact import file can be 20 MB at most. The app uses PhpSpreadsheet 1.30.8.
- The editor uses prosemirror-view 1.42.9. This version fixes a paste XSS.
- The app refuses a link domain that another client already uses.
- The double opt-in sender of a list must be an email identity of the same client.
- The demo seeder (`php artisan db:seed`) refuses to run when `APP_ENV` is `production`.
- New `TRUSTED_PROXIES` and `SESSION_SECURE_COOKIE` settings.

## [0.1.0] - Unreleased

The first public release.

### Added

- Newsletters with a block editor, audience filters, saved segments, scheduled sends and A/B tests on the subject or the content.
- Sequences: drip campaigns with a delay between steps and audience filters for each step.
- Automations that start when a contact joins a list, gets a tag, finishes a sequence or has an attribute change. A visual builder is also available.
- Contacts with lists, topics, tags and custom attributes. CSV import and export. A preference center and a one-click unsubscribe link (RFC 8058) for each contact.
- Reusable email templates and content blocks with `{{ variable }}` placeholders.
- Sends through the Amazon SES account of each account. SES events come back through SNS and one SQS queue, and the `ses-events:poll` worker reads them.
- Suppression of addresses that bounced or complained, and a reputation page with the rates that AWS monitors.
- Optional custom tracking domains and custom link domains for each sending identity.
- Teams with the admin, editor and viewer roles, and two-factor authentication.
- A REST API at `/api/v1` with OpenAPI docs, outgoing webhooks, and an MCP server at `/mcp` for AI agents.
- Warnings in the editor and before a send when the content has no unsubscribe link or the account has no postal address.
- Self-hosting with Docker Compose: web, Horizon, scheduler, the SES events worker, MySQL and Redis.
- A Docker image on GHCR for each release, for `linux/amd64` and `linux/arm64`, and `docker-compose.image.yml` to run it without a build.
- A first-run setup page at `/setup` and the `byomailer:install` command to create the owner of the instance.
- Invite-only and open registration modes, an owner role, user deactivation and account recovery commands.
- Optional MaxMind GeoIP, Rollbar error reports and S3-compatible media storage.

[Unreleased]: https://github.com/amedina068/byomailer/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/amedina068/byomailer/releases/tag/v0.1.0
