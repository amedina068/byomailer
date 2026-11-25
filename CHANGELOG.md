# Changelog

All notable changes to this project are in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file lists the changes to the code for each release. The release notes for users are on the [release notes page](https://byomailer.com/docs/getting-started/release-notes) of the docs.

## [Unreleased]

## [0.1.0] - 2026-11-25

The first public release.

### Added

- The OpenAPI document in `docs/api/openapi.yaml`, with a Postman collection and a Postman environment. Each API endpoint has a summary, a description and its error responses.
- Example files for an install without Docker in `deploy/`: an NGINX server block, systemd units, a Supervisor file, and the Caddyfile of the tracking proxy.
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
- BYOMailer is licensed under the AGPL-3.0.
- API and MCP: each error on `/api/*` and `/mcp` is JSON, also when the request has no `Accept: application/json` header. Before, a missing token gave a redirect to the login page, and a validation error gave a redirect. A missing record gives `404` with the message `No contact with ID 5 exists in this account.`. A team member who is not an admin gets a `403` message on the webhook endpoints.
- The API schedules and sends a newsletter with the same checks as the dashboard and the MCP server, and returns the compliance warnings in `meta.warnings`. All three require a verified sender identity to schedule or send. When the send started first, each channel reports an error.
- A new contact with a blacklisted email is saved as unsubscribed in the dashboard, the MCP server and the import. The MCP server no longer refuses it. The API still refuses it with 422.
- The API subscribe endpoint now uses the suppression rule of the dashboard. It accepts `remove_suppression` to remove a bounce suppression of your client. A second unsubscribe in the dashboard keeps the first date and sends one webhook.
- The API deletes a scheduled newsletter, the same as the dashboard. A delete never removes a newsletter that started to send.
- A new newsletter gets the title "Untitled newsletter" in the dashboard, the API and the MCP server. The API changes the title and the projects of a newsletter in any status, the same as the dashboard. Saved content variables keep every key that you send, and each variable that the content uses gets an empty default.
- The newsletter list, the newsletter page and the dashboard show the same label for each newsletter status. An A/B test shows "A/B testing" everywhere.
- The sequence list, the sequence page, the step page and the dashboard read the sequence status labels from the server. They show the same label for each status.
- The MCP sequence tools return the stored status of a sequence and a step: "draft" or "live". set-sequence-status and set-sequence-step-status accept "draft", and they still accept "paused" as the same value.
- The dashboard keeps each sequence variable default that you save, also for a variable that no step uses yet. The API and the MCP tools already did this.

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
- API reference: the sequence and sequence step stats responses show `id`, `sequence_id` and `delay_days` as integers. The newsletter `filters` items show as objects.
- The API reference shows the 422 error fields of `POST /contacts/{contactId}/subscribe` and `DELETE /newsletters/{newsletterId}`, and the 422 response of `POST /newsletters/{newsletterId}/test-mail`. Before, the test mail endpoint did not show a 422 response.
- A saved audience filter with an unknown category or match mode matches no contact. Before, it could send to more contacts than the filter selects.
- An automation that you save in the dashboard stores its list, tag and sequence ids as numbers, the same as the MCP tools.
- The dashboard saves an edit of a paused automation that adds contacts to a draft sequence. Before, it refused every edit of such an automation.
- The MCP guide gives Claude Desktop an `mcp-remote` entry. Claude Desktop did not accept the old HTTP entry.
- A custom attribute change in the dashboard no longer overwrites an attribute that another request saved at the same time.
- When a contact unsubscribes while an admin subscribes it again, the unsubscribe of the contact stays.
- A list removal through the API or the MCP server now runs the list automations and the sequence list filters. An add to a list never changes the subscription. An unsubscribed contact stays unsubscribed. Before, the API list upsert and MCP create-contact subscribed a contact again after an admin unsubscribe.
- Email identities: you cannot remove an email identity while a paused sequence has a live step that sends from it. Before, the check missed paused sequences, and the step failed to send when the sequence went live again.
- A saved date, engagement, attribute or location filter with an unknown operator, time window or field matches no contact. Before, it could match every contact and send to more contacts than the filter selects.
- When a scheduled newsletter starts to send before you unschedule it, the dashboard, the API and the MCP server now report an error. The API `status: draft` also clears `scheduled_at`.
- A newsletter cannot be scheduled or sent from an email identity that is being removed at the same time.
- Lists: a duplicate list name gives a validation error on the dashboard and in the API. Before, it gave a server error. The API refuses to delete a list when a newsletter that is not sent, a sequence, a segment or an automation uses the list or one of its topics. The `422` error names each item.
- The MCP tools refuse an invalid audience filter. Before, they removed it without a message, and the audience became larger.
- The dashboard checks the newsletter body for unsafe HTML and a 500 KB size limit, the same as the API and the MCP server. Changes to a newsletter that started to send now fail with an error. A change to a scheduled newsletter fails when it leaves a blank subject or body.
- The API and the MCP server refuse a change that leaves a scheduled newsletter with a blank subject or body. Setting filters removes a saved segment. The MCP server applies the email template at send time, and it refuses an HTML body that it cannot convert to plain text.
- A newsletter starts one send only. When the scheduler, the dashboard and the API try to send or change one newsletter at the same time, only the first change applies. A send job does not send a newsletter that went back to draft.
- The opt-in email no longer goes to a suppressed address, or from an account that the sending gate blocks.
- On MySQL, a schedule change that kept the same values reported that the send had started. For example, the API `status: draft` on a draft, or a schedule saved again at the same time. These changes now succeed.
- The API and MCP sequence list filter `status=paused` returns the paused sequences. A paused sequence has the status `draft`.
- The dashboard refuses to save a live sequence step without a subject or content. The step editor also refuses unsafe HTML and content over 500 KB.
- A sequence step uses the name of the sequence author as the from name when the sender identity has no name.
- SES events start again on their own after AWS disables an event destination. SES disables the SNS destination of a configuration set when it cannot publish to the topic, for example while AWS suspends the account. A new hourly command, `ses:enable-event-destinations`, enables it again with the same event types and topic.
- A mail that SES cannot render now shows the status failed with the SES error message. Before, the app ignored the SES "Rendering Failure" event, and the mail stayed pending.
- Docker starts the `ses-events` poller again after each exit. The service now uses `restart: always` in `docker-compose.yml` and `docker-compose.image.yml`. When the poller does not run, the SES events stay in SQS and the mails stay in the status sent.
- A "none of these" filter on a deleted or renamed tag, list or topic matches no contact. Before, it excluded nobody, and the audience became larger.
- The step contacts page shows a failed mail as "Failed" with its failure reason. Before, it showed "Pending".
- A complaint or a bounce suppresses the contact when another team member created the suppression row of the address. Before, the SES event failed and the contact stayed subscribed.
- The API refuses to rename or delete a tag that a saved filter of a newsletter, a sequence, a sequence step or a segment uses. A tag delete now runs the removed-tag automations for each contact.
- A failed newsletter no longer blocks the rename or the delete of a tag that its filters use.
- Topics: the dashboard and the API refuse to delete a topic when a newsletter that is not sent, a sequence, a segment or a visual automation uses the topic. The dashboard shows the reason in an error message. The API returns `422` and names each item.
- A link whose address comes from a `{{ variable }}` now gets the UTM parameters in bulk newsletters sent through SES. A signed link (with a `signature` parameter) and the one-click unsubscribe link never get UTM parameters.
- Lists: the double opt-in switch on the edit page turns on and shows its fields. **View contacts** on a list opens the contacts of that list. The list pages say "list", not "segment".

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
- Dependency update for known advisories: Laravel 13.43, Guzzle 7.16, the Symfony 8.1 components, CommonMark 2.10, Laravel Excel 3.1.70, Vue 3.5.45, and the `npm audit fix` set. The audience preview routes use the new `EnforceReadAccess` middleware.
- Each response sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`. Another site cannot show the app in a frame.
- The WebAuthn libraries that Fortify loads (`web-auth/webauthn-lib`, `web-auth/cose-lib`, `spomky-labs/pki-framework`, `spomky-labs/cbor-php`) are updated to versions that fix 40 known security advisories.

[Unreleased]: https://github.com/amedina068/byomailer/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/amedina068/byomailer/releases/tag/v0.1.0
