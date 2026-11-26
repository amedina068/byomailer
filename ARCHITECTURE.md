# BYOMailer Architecture

## Overview

BYOMailer is a self-hosted email marketing app. Each account connects its own Amazon SES account, and BYOMailer sends all campaign email through that SES account.

An account manages contacts, lists, tags and segments. It sends newsletters (one-time campaigns) and sequences (drip campaigns). Automations and visual automations move contacts between lists, tags and sequences. The same data is available in the dashboard, in a REST API and in an MCP server for AI agents.

**Tech stack:**

- Laravel 13 with the streamlined skeleton (`bootstrap/app.php`) on PHP 8.4
- Inertia 3, Vue 3, Vite, Tailwind CSS 4 and shadcn-vue for the dashboard
- Fortify (web authentication), Sanctum (API tokens) and laravel/mcp (the MCP server)
- Horizon (Redis queues), Scramble (the OpenAPI document) and Spatie Media Library (uploads)
- `maatwebsite/excel` (imports and exports) and the AWS SDK for PHP

The one-container Docker image uses SQLite and the database queue. MySQL with Redis and Horizon is an option. The tests and local development use SQLite.

This file describes the code on `main`. When you change a table, a route group, a send path or a tenancy rule, change this file in the same pull request.

For the visual rules of the dashboard, read [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md). For the contribution rules, read [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Domain Concepts

| Concept | Description |
|---------|-------------|
| **Client** | The tenant (an account). It owns all contacts, campaigns and AWS settings. It has a time zone, a postal address and a sunset period (`sunset_inactive_days`). |
| **User** | Belongs to one client. The team role is `admin`, `editor`, `viewer` or `invited`. The `is_owner` flag marks a person who runs the instance. |
| **InstanceSetting** | A key and a value that the owner sets in **Admin > Settings**, for example the registration mode. |
| **EmailCredential** | The AWS access key, the SES region, the SNS topic, the SES tenant name and the SES quota of a client. A client has one SES credential. |
| **EmailIdentity** | A sending domain in SES. It has its own configuration set, open and click tracking flags, a reply-to address, a custom tracking domain and a custom link domain. |
| **EmailIdentityDnsRecord** | One DNS record that the domain owner must publish: DKIM, MAIL FROM or DMARC. |
| **Contact** | A person who gets email. It has custom attributes (`extra_attributes`), an unsubscribe state, a soft bounce count and a last engagement time. |
| **MailList** | A list of contacts (table `lists`). A list can need double opt-in (`opt_in_enabled`). |
| **Topic** | A subject inside a list. A contact can subscribe to a topic or leave it in the preference center. |
| **Tag** | A label on contacts. Tag names are unique in a client. |
| **ContactAttribute** | A custom attribute name, with a default value for `{{ variable }}` placeholders. |
| **Segment** | A saved audience filter. A newsletter or a sequence can refer to a segment, and then the segment filters apply. |
| **Project** | A folder for newsletters, sequences, automations and visual automations, through the polymorphic `projectables` table. |
| **Newsletter** | A one-time campaign. It has audience filters, content variables, a schedule and an optional A/B test. |
| **Sequence** | A drip campaign. A contact joins it and gets its steps one after the other. |
| **SequenceStep** | One email of a sequence, with a delay in days or hours and optional step filters. |
| **Message** | The subject, the sender and the content of a newsletter variant, a sequence step or a list opt-in email (polymorphic `messageable`). |
| **EmailTemplate** | An HTML shell with a `{{ content }}` slot. A message can use a template. |
| **Block** | A reusable HTML block that a message can insert. |
| **ContactMail** | One email to one contact. It records the status and the SES event times: sent, delivered, opened, clicked, bounced, complained and unsubscribed. |
| **ContactMailClick** | One link click in a contact mail. |
| **TestMail** | A test send of a newsletter or a step to the inbox of a user. |
| **Automation** | A rule: when a condition occurs, do one or more actions. |
| **VisualAutomation** | A workflow graph with trigger, action, delay and condition nodes. Each contact in it has a **VisualAutomationEnrollment** and one **VisualAutomationContactStep** for each node. |
| **BlacklistedEmail** | An address that BYOMailer does not send to, for example after a hard bounce or a complaint. |
| **ContactImport** | A CSV or spreadsheet import into a list, processed in a job batch. |
| **EmailLog** | A log row of one SES call or one SES event. |
| **WebhookEndpoint** | An outgoing webhook of a client. **WebhookDelivery** is one attempt to send an event to it. |
| **UserStorageSetting** | The S3 or R2 bucket of a user for uploaded images. |

---

## Database Schema

These are the application tables and their columns. Run `php artisan migrate` on an empty database to see the full schema. Most tables also have `created_at` and `updated_at`.

### Accounts and the instance

```
clients
├── id, uuid, name, slug, email, role (customer|admin), timezone
└── sunset_inactive_days, postal_address

users
├── id, uuid, client_id (FK), name, email, password, email_verified_at
├── role (admin|editor|viewer|invited), is_owner (bool)
├── invite_expires_at, archived_at, deactivated_at
└── two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at, remember_token

instance_settings
└── key (primary key), value
```

No route or policy reads `clients.role` at this time.

### AWS and sending identities

```
email_credentials
├── id, client_id (FK), user_id (FK), type, access_key, secret_key, region
├── aws_account_id, tenant_name, enforcement_status
├── sns_topic_arn, sns_subscription_arn, sns_subscription_confirmed_at
├── production_access_enabled, sending_enabled, max_24_hour_send, max_send_rate, sent_last_24_hours, quota_fetched_at
├── can_create_template, can_delete_template, can_send_bulk_email, can_send_email
├── permissions_checked_at, permissions_alert_count, permissions_alerted_at
└── configuration_set_name, open_tracking_enabled, click_tracking_enabled, custom_tracking_domain (older columns, now on email_identities)

email_identities
├── id, uuid, client_id (FK), user_id (FK), email, original_email, name, domain, region
├── mail_from_subdomain, identity_status (pending|verifying|verified|failed), verified_at, last_status_check_at
├── configuration_set_name, configuration_set_created_at, tenant_associated_at
├── open_tracking_enabled, click_tracking_enabled
├── custom_tracking_domain, custom_tracking_domain_verified_at
├── custom_link_domain, custom_link_domain_verified_at, cf_custom_hostname_id
└── reply_to_email, reply_to_name

email_identity_dns_records
└── id, email_identity_id (FK), type, name, value, priority, purpose, verified_at
```

BYOMailer encrypts the AWS keys with `APP_KEY`. If the key changes, the users must enter the AWS keys again.

### Contacts and audiences

```
contacts
├── id, uuid, client_id (FK), user_id (FK), name, email, extra_attributes (JSON)
├── timezone, country, unsubscribed_at, unsubscribed_by_contact
└── soft_bounce_count, last_engaged_at, sunset_at

lists
└── id, uuid, client_id (FK), user_id (FK), name, opt_in_enabled

contact_list (pivot): contact_id, list_id, opted_in_at
topics: id, uuid, client_id (FK), user_id (FK), list_id (FK), name, description
contact_topic (pivot): contact_id, topic_id, subscribed_at, unsubscribed_at
tags: id, uuid, client_id (FK), user_id (FK), name
contact_tag (pivot): contact_id, tag_id
contact_attributes: id, uuid, client_id (FK), user_id (FK), name, default_value
segments: id, client_id (FK), name, description, filters (JSON), archived_at
blacklisted_emails: id, client_id (FK), user_id (FK), email, reason
contact_imports: id, client_id (FK), user_id (FK), list_id, batch_id, status, original_filename,
                 total_count, processed_count, skipped_count, error_message
```

### Campaigns and content

```
newsletters
├── id, uuid, client_id (FK), user_id (FK), title
├── status (draft|scheduled|queued|ab_testing|sent|failed), scheduled_at, sent_at
├── filters (JSON), segment_id (FK), content_variables (JSON)
├── template_name, ab_test_enabled, ab_template_name, ab_test_winner_variant
└── total_contacts_reached, last_dispatched_contact_id

sequences
└── id, client_id (FK), user_id (FK), name, status (draft|live), filters (JSON), segment_id (FK), content_variables (JSON)

sequence_steps
└── id, sequence_id (FK), status (draft|live), delay_days, delay_type (days|hours), filters (JSON)

contact_sequence (pivot): contact_id, sequence_id, completed_at

messages
├── id, client_id (FK), user_id (FK), messageable_type, messageable_id, variant
├── email_identity_id (FK), email_template_id (FK), subject, from_email, from_name, preview_text
└── html_content, text_content, markdown_content, template_slot_content

email_templates: id, client_id (FK), user_id (FK), name, html_content, is_default, archived_at
blocks: id, uuid, client_id (FK), user_id (FK), name, html_content
projects: id, uuid, client_id (FK), user_id (FK), name
projectables (polymorphic pivot): project_id, projectable_type, projectable_id
```

### Sent mail and events

```
contact_mails
├── id, uuid, client_id (FK), contact_id (FK), mailable_type, mailable_id
├── subject, from_email, from_name, template_data (JSON), message_id (SES), status, failure_reason
├── scheduled_at, reserved_at, sent_at, delivered_at, opened_at, clicked_at
├── bounced_at, rejected_at, complained_at, unsubscribed_at
└── open_ip_address, open_user_agent, open_timezone, open_country, open_city

contact_mail_clicks: id, contact_mail_id (FK), contact_id (FK), link, clicked_at
test_mails: id, uuid, client_id (FK), user_id (FK), mailable_type, mailable_id, sent_at
email_logs: id, client_id (FK), provider, operation, recipient, message_id, status, error,
            request_payload, response_payload, sns_payload, logged_at
```

The `mailable` of a contact mail is a `Newsletter`, a `SequenceStep`, a `MailList` (the opt-in email) or a `TestMail`.

### Automations

```
automations: id, client_id (FK), user_id (FK), name, type, condition (JSON), actions (JSON), is_live
visual_automations: id, client_id (FK), user_id (FK), name, status, workflow (JSON)
visual_automation_enrollments: id, visual_automation_id (FK), contact_id (FK), status, enrolled_at, completed_at
visual_automation_contact_steps: id, enrollment_id (FK), node_id, status, wait_until, processed_at
```

### Integrations

```
webhook_endpoints
├── id, uuid, client_id (FK), user_id (FK), url, secret, description, events (JSON), active
└── disabled_reason (manual|auto_failures), consecutive_failures, last_triggered_at, disabled_at

webhook_deliveries
├── id, uuid, client_id (FK), webhook_endpoint_id (FK), event_type, payload, status, attempts
└── response_status, response_body, error, dispatched_at, delivered_at

user_storage_settings
├── id, client_id (FK), user_id (FK), provider (s3|r2), bucket, region, endpoint
└── access_key_id, secret_access_key, public_base_url, path_prefix, is_verified
```

### Framework and package tables

- `personal_access_tokens`: Laravel Sanctum
- `media`: Spatie Media Library
- `jobs`, `job_batches`, `failed_jobs`: the Laravel queue
- `cache`, `cache_locks`, `sessions`, `password_reset_tokens`: Laravel

---

## Model Relationships

```
Client
├── hasMany: Users, Contacts, mailingLists (MailList), Tags, Projects, Newsletters, Sequences, Segments,
│            Automations, BlacklistedEmails, EmailCredentials, EmailIdentities, ContactAttributes,
│            Messages, ContactMails, EmailLogs, ContactImports, storageSettings, EmailTemplates, Blocks
└── hasOne: sesCredential (EmailCredential)

User
├── belongsTo: Client
├── hasOne: sesCredential (EmailCredential), storageSetting (UserStorageSetting)
└── uses: HasApiTokens (Sanctum), TwoFactorAuthenticatable (Fortify), InteractsWithMedia (`gallery`)

Contact
├── belongsToMany: lists (MailList), Tags, Topics, Sequences
└── hasMany: mails (ContactMail), visualAutomationEnrollments

MailList:     belongsToMany Contacts, hasMany Topics, morphOne Message (the opt-in email)
Newsletter:   belongsTo Segment, morphOne message / messageA / messageB, morphMany ContactMails, morphToMany Projects
Sequence:     belongsTo Segment, hasMany steps (SequenceStep), belongsToMany Contacts, morphToMany Projects
SequenceStep: belongsTo Sequence, morphOne Message, morphMany ContactMails
Message:      belongsTo EmailIdentity, template (EmailTemplate), morphTo messageable
ContactMail:  belongsTo Contact, Message, morphTo mailable, hasMany clicks (ContactMailClick)
EmailIdentity:    hasMany dnsRecords (EmailIdentityDnsRecord)
VisualAutomation: hasMany enrollments → hasMany steps (VisualAutomationContactStep)
WebhookEndpoint:  hasMany deliveries (WebhookDelivery)
```

---

## Multi-Tenancy

The `Client` is the tenant. Each resource model has a `client_id` column and uses the `BelongsToClient` trait (`app/Models/Concerns/BelongsToClient.php`). The trait does two things:

- It adds the global scope `ClientScope`.
- When a record is created with no `client_id`, it sets `client_id` from `Tenant::id()`.

`App\Support\Tenant` holds the current tenant as a hidden Context value. `SetTenantFromAuthenticatedUser` sets the value when a user authenticates through the session guard or through a Sanctum token. Laravel copies the Context value into each job that the request dispatches.

`ClientScope` adds `where client_id = <tenant>` to each query. A query with no tenant matches no rows. Outside production, it also throws `MissingTenantException`, so a test finds the fault.

Code with no request uses one of two patterns:

- To work for one client in a command, a scheduled task or a job, wrap the work in `Tenant::run($clientId, ...)`.
- To scan all clients, start the query with `withoutGlobalScopes()` and filter by `client_id` in the code.

The unsubscribe, resubscribe, opt-in and preference links have no logged-in user. `SetTenantFromContactMailLink` reads the `{contactMail}` uuid in the path and runs the request as the client of that mail.

`tenancy:orphans` lists the rows whose `client_id` has no client. It changes no data.

---

## Application Layers

### Actions (`app/Actions`)

An action is a small class with one public method, for example `CreateMailList`, `SubscribeContact`, `UnsubscribeContact`, `UpsertContact`, `PickAbTestWinner` and `SaveSesCredential`. The dashboard controllers, the API controllers and the MCP tools share the actions.

`CreateAccount` creates a client, its first user with the team role `admin`, and the default email template. `Actions\Fortify\CreateNewUser` and `Actions\Instance\CreateInstanceOwner` call it.

### Services (`app/Services`)

| Service | Job |
|---------|-----|
| `SesClientManager` | Builds the SES clients and the mailer settings from the credential of a user. Reads the quota and the IAM permissions. |
| `UserMailerFactory` | `mailerFor($user)` gives a mailer with the SES credential of the user. |
| `EmailSendingGate` | `evaluate($user)` returns a `SendDecision`. It blocks a deactivated user and an AWS account where SES paused sending. |
| `SendableContentCheck` | `problems()` finds a blank subject or a blank body. `warnings()` finds content without `{{ unsubscribe_url }}` and an account without a postal address. |
| `NewsletterTransport` | Sends one chunk through SES bulk templates, or through the default mailer when `mail.use_ses_api` is false. Gives the chunk size from the SES send rate. |
| `SesBulkEmailService` | Creates and deletes the SES templates of a newsletter, and calls `SendBulkEmail` with the tenant, the configuration set and the `List-Unsubscribe` headers. |
| `SendNewsletterService`, `NewsletterScheduleService` | Send a newsletter in chunks. Schedule, unschedule and queue a newsletter. |
| `SequenceStepScheduler`, `SequenceStatusService` | Create the scheduled mails of sequence steps. Switch sequences and steps on and off. |
| `AutomationRunner`, `AutomationStatusService` | Run the actions of an automation for one contact. Switch automations on and off. |
| `VisualAutomationEngine` | Enrolls contacts and processes the nodes of a visual automation. |
| `ContactFilterService`, `FilterPreparationService` | Apply the stored audience filters to a contact query. An unknown filter value matches no contact. |
| `EmailIdentityProvisioningService` | Creates the SES domain identity, the MAIL FROM domain, the configuration set and the tenant link. Refreshes the status and changes the tracking flags. |
| `SesConfigurationSetService`, `SesDomainIdentityService`, `SesTenantService`, `SnsTopicService` | Wrap the SES and SNS API calls. |
| `SesPermissionChecker` | Finds the IAM permissions that the AWS key does not have. |
| `TrackingDomainVerifier`, `LinkDomainVerifier`, `CloudflareClient` | Activate the custom tracking domains and the custom link domains. |
| `DmarcVerifier`, `DnsResolver` | Read the DNS records that a sending domain publishes. |
| `WebhookDispatcher` | Creates the deliveries of an event for each active endpoint. |
| `SunsetPolicy` | Selects the contacts that the sunset sweep marks as inactive. |
| `UserFilesystemManager` | Gives the storage disk of a user. |
| `SsrfGuard` | Refuses a private or local host for webhooks and custom storage endpoints. |

### Jobs (`app/Jobs`)

| Queue | Jobs |
|-------|------|
| `newsletter` | `DispatchNewsletterBatchJob`, `SendNewsletterJob`, `SendNewsletterChunkJob` |
| `sequences` | `SendSequenceContactMailJob`, `SendOptInEmailJob` |
| `automations` | `RunAutomationForContactJob`, `ProcessVisualAutomationEnrollmentJob` |
| `ses-events` | `ProcessSesEventJob` |
| `tracking-domains` | `VerifyTrackingDomainJob` |
| `webhooks` | `DeliverWebhookJob` |

Other jobs are `ProcessContactImportBatchJob`, `BackfillTopicSubscribersJob` and `SyncContactToByomailerList`. The last job adds a new user to a list in another BYOMailer instance, only when `BYOMAILER_API_URL` and the related settings exist.

Horizon runs on Redis. `config/horizon.php` has one supervisor for each queue: `default`, `newsletter`, `sequences`, `automations`, `ses-events`, `tracking-domains` and `webhooks`. Only the owner can open `/horizon`.

### Events and listeners

The contact events are `ContactSubscribed`, `ContactUnsubscribed`, `ContactResubscribed`, `ContactListsSynced`, `ContactTagsSynced`, `ContactSequencesSynced`, `ContactSequenceFinished` and `ContactAttributesChanged`. The campaign events are `NewsletterSent` and `SequenceStepSent`.

Laravel finds the listeners in `app/Listeners`. The listeners run the automations and enroll contacts in visual automations. They also schedule the first sequence step, remove contacts that no longer match the sequence filters, and send the outgoing webhooks. `EventListenerRegistrationTest` counts the listeners of each event.

### Support (`app/Support`)

| Class | Job |
|-------|-----|
| `Tenant` | The current tenant. Read [Multi-Tenancy](#multi-tenancy). |
| `InstanceSetup`, `InstanceSettings`, `Registration` | The first-run state, the instance settings and the registration mode. |
| `AutomationDefinition` | The validation of the automation conditions and actions. The dashboard and the MCP tools share it. |
| `ContentVariables`, `DetectsTemplateVariables`, `ReplacesTemplateVariables`, `ReplacesBlockVariables` | The `{{ variable }}` placeholders and the blocks in email content. |
| `ContentSlot`, `DefaultTemplateContent` | The `{{ content }}` slot of an email template, and the default template. |
| `HtmlToText`, `Preheader`, `QueryStringJoiner` | The plain-text part, the preview text and the links that follow a placeholder. |
| `Filters\FilterSchema` | The operators of each audience filter category. |
| `AudienceUsage`, `TagUsage`, `EmailIdentityUsage` | Find the campaigns and automations that use a list, a topic, a tag or an identity. |
| `PreviousUrlSignature` | Accepts the signed URLs of a previous `APP_URL`. |
| `SpreadsheetCell` | Makes a value safe for one cell of a CSV export. |
| `Webhooks\WebhookSignature` | Signs the outgoing webhooks. |
| `OpenApi\DocumentsErrorResponses` | Adds the error responses that Scramble cannot read from the code. |
| `TenantOrphans` | Finds the rows with no client, for `tenancy:orphans`. |

---

## Instance Setup

A new instance has no user. The first person to open it creates the owner account.

- `App\Support\InstanceSetup` makes the setup token from `APP_KEY` with HMAC-SHA256. The instance stores no token.
- `RedirectToSetup` sends `GET /`, `GET /login` and each `/register` request to `/setup` while the `users` table is empty.
- `SetupController` shows the form only with the correct token. Without the token, the page shows how to find the setup URL: `docker logs byomailer`, `docker compose logs web` or `php artisan byomailer:setup-url`. The operator can paste the token or the full URL.
- After the first user exists, `GET /setup` redirects to `/login` with a message, and `POST /setup` returns 404.
- `CreateInstanceOwner` takes a cache lock and checks the `users` table again in the transaction. Two requests cannot create two owners.

`php artisan byomailer:install` creates the owner from the command line. `--prepare` only creates the `public/storage` link. `--promote=<email>` makes an existing user an owner.

The owner gets a new client, the team role `admin`, a verified email and `is_owner`.

---

## Sending Email

### SES setup of an account

The dashboard shows the SES steps first (`resources/js/components/dashboard/SesSetupSteps.vue`). The steps are in this order:

1. Create the SES event queue.
2. Connect the AWS account.
3. Verify a sending domain.
4. Leave the SES sandbox.

The queue step is first because the AWS connection subscribes the SNS topic of the account to the SQS queue of the instance. Without `SES_EVENTS_SQS_QUEUE_ARN`, the connection fails.

The `sesSetup` prop of `DashboardController` has three keys:

- `sandbox`: true while SES is in the sandbox. It is null before the AWS connection.
- `eventsConfigured`: true when `services.sqs_ses_events.queue_url` and `services.sqs_ses_events.queue_arn` both have a value.
- `canConfigureEvents`: true for the owner (`$user->isOwner()`).

The owner sees the queue step with a link to the SES events guide. While the queue is missing, a user who is not the owner sees a queue step that says "Ask the owner of this instance". When the queue exists, that user sees no queue step.

When a user connects AWS, `AwsOnboardingController` does these steps in the AWS account of the user:

1. It validates the key with `AwsCredentialValidator`.
2. It creates an SNS topic for the events of the account.
3. It subscribes the SQS queue of the instance (`SES_EVENTS_SQS_QUEUE_ARN`) to the topic.
4. It creates the SES tenant `byomailer` with a standard reputation policy.
5. It saves the credential with `SaveSesCredential` and reads the SES quota.

If step 3 or step 4 fails, the controller deletes the new topic.

`EmailIdentityProvisioningService` registers a sending domain. It creates the SES domain identity, the MAIL FROM domain and a configuration set for the identity. Then it links the identity and the configuration set to the tenant. The configuration set publishes the SES events to the SNS topic. Open and click events go to the topic only when tracking is on.

### Send rules

- Send each campaign email through `UserMailerFactory::mailerFor($user)` or `NewsletterTransport`. Do not use `Mail::to()` for campaign email.
- Gate each send path with `EmailSendingGate::evaluate()`.
- Run `SendableContentCheck::problems()` before a newsletter or a step goes live. Show `warnings()` to the user. The warnings never block a send.
- If `MAIL_USE_SES_API` is false, the campaign email goes through the default mailer of the instance.

Newsletter chunks and sequence mails go through the `ses-sending` rate limiter. It uses Redis (`RateLimitedWithRedis`) and follows the max send rate of the SES account of each user.

### Newsletters

1. `contact-mails:send-due-newsletters` runs each minute. It claims each due newsletter with a conditional update from `scheduled` to `queued`.
2. `DispatchNewsletterBatchJob` starts a job batch with one `SendNewsletterJob`.
3. `SendNewsletterJob` creates the SES templates and adds one `SendNewsletterChunkJob` for each group of contacts. A retry continues after `last_dispatched_contact_id`.
4. Each chunk writes its `contact_mails` rows and calls SES `SendBulkEmail`.
5. When all jobs ran, the batch sets the final status, fires `NewsletterSent` and deletes the SES templates.

An A/B test sends variant A and variant B to two halves of the audience. The status is then `ab_testing`. `PickAbTestWinner` records the winner, sends no email and fires `newsletter.sent`.

### Sequences

When a contact joins a sequence, `ScheduleFirstSequenceStep` creates the first contact mail with a `scheduled_at` time. `contact-mails:send-due-sequence-steps` runs each minute and claims the due rows. `SendSequenceContactMailJob` sends them. When SES reports the delivery, `ProcessSesEventJob` asks `SequenceStepScheduler` to schedule the next step.

A sequence sends only through its live steps. A contact who does not match the step filters skips that step. A contact who no longer matches the sequence filters leaves the sequence.

### Opt-in

If a list has `opt_in_enabled`, `SendOptInEmailJob` sends the opt-in email of the list. The contact confirms with a POST to `/opt-in/{contactMail}`. Mail scanners open each GET link, so the GET only shows the page.

---

## SES Events

SES sends each event (send, delivery, open, click, bounce, complaint, reject, rendering failure) to the SNS topic of the account. All topics send to one SQS queue of the instance.

1. `ses-events:poll` is a long-running process. It reads the queue with long polling and dispatches `ProcessSesEventJob` for each message. Then it deletes the message.
2. `ProcessSesEventJob` runs on the `ses-events` queue. It finds the clients that own the topic, and looks for the contact mail in those clients only.
3. The job updates the contact mail. A status never moves back, and a terminal event always wins.

The job also does these steps:

- A hard bounce or a complaint unsubscribes the contact and adds a `BlacklistedEmail` row.
- Three soft bounces in a row (`SOFT_BOUNCE_LIMIT`) suppress the contact. A delivery resets the count.
- A click adds a `ContactMailClick` row and moves `last_engaged_at` forward. An open is not engagement, because Apple Mail Privacy Protection fakes opens.
- An open stores the IP address, the user agent and the location from `GeoIpService`.

The poll command needs `PLATFORM_AWS_ACCESS_KEY_ID`, `PLATFORM_AWS_SECRET_ACCESS_KEY`, `PLATFORM_AWS_REGION` and `SES_EVENTS_SQS_QUEUE_URL` (`config/services.php`, key `sqs_ses_events`).

An older HTTP path also exists. SNS can post to `POST /webhooks/ses` (`SesWebhookController`). The controller accepts a message only one time. AWS must sign the message, it must come from a known topic, and it must be less than 24 hours old. `ses-events:migrate-to-sqs` moves old HTTP subscriptions to SQS.

---

## Tracking and Custom Domains

Each email identity has its own open and click tracking flags. SES does the tracking through the configuration set of the identity.

A **custom tracking domain** replaces the SES tracking host in links and in the open pixel:

1. The domain owner points an A record to `TRACKING_PROXY_IP`.
2. `email-identities:check-tracking-domain-dns` runs every 30 minutes. It dispatches `VerifyTrackingDomainJob` for each domain that is not verified.
3. `TrackingDomainVerifier` makes sure that the A record is correct. Then it sets the domain in the SES tracking options.
4. The TLS proxy (Caddy with on-demand TLS) calls `GET /tls/ask` with `TRACKING_PROXY_ASK_TOKEN`. The endpoint answers 200 only for a verified domain.

A **custom link domain** serves the unsubscribe and preference links of an identity, for example `links.example.com`:

1. The domain owner points a CNAME to the Cloudflare for SaaS target (`CLOUDFLARE_CNAME_TARGET`).
2. `CloudflareClient` creates a custom hostname and stores `cf_custom_hostname_id`.
3. `email-identities:check-link-domain` runs every 30 minutes. `LinkDomainVerifier` marks the domain verified when Cloudflare issued the certificate.

`RestrictHostToLinkRoutes` runs first on each request. On the host of `APP_URL`, it allows all routes. On a verified link domain, it allows only `unsubscribe/*`, `one-click-unsubscribe/*`, `resubscribe/*`, `preferences/*`, `opt-in/*` and `build/*`. On any other host, it returns 404. `/up` is open on each host.

If you add a route that a contact opens from an email, add its path to `RestrictHostToLinkRoutes::ALLOWED_PATHS`. Build its URL with the host from `ContactMail::linkDomainHost()`.

---

## Automations

An **automation** has one condition and a list of actions (`App\Support\AutomationDefinition`):

- Conditions: `contact_added_to_list`, `removed_from_list`, `tag_added`, `removed_tag`, `added_to_sequence`, `sequence_finished`, `attribute_changed`.
- Actions: `add_tag`, `add_list`, `remove_tag`, `remove_list`, `add_sequence`.

The `RunAutomationsFor*` listeners dispatch `RunAutomationForContactJob` for the automations that match the event. A new automation is paused. An automation can go live only when its target sequences are live.

A **visual automation** stores a workflow graph in `workflow` (JSON). Its nodes are triggers, actions, delays and conditions. The `EnrollVisualAutomationsFor*` listeners enroll contacts. `visual-automations:process` runs each minute and dispatches `ProcessVisualAutomationEnrollmentJob` for each enrollment with due steps. `VisualAutomationEngine` processes each step.

---

## Routing Architecture

### Web routes (`routes/web.php`, `routes/settings.php`)

| Area | Routes | Controller |
|------|--------|------------|
| Setup | `GET/POST /setup` | SetupController |
| Dashboard | `GET /dashboard`, `GET /reputation` | DashboardController, ReputationController |
| Contacts | CRUD, import, export, tags, lists, attributes, sequence enrollment | ContactController and its helpers |
| Audiences | `/lists`, `/segments`, `/tags`, `/attributes`, list topics | MailListController, SegmentController, TagController, TopicController |
| Content | `/email-templates`, `/blocks`, `/gallery/images`, `/editor/images` | EmailTemplateController, BlockController, GalleryImageController, EditorImageController |
| Newsletters | CRUD, content, variables, filters, preview, schedule, test mail, A/B winner, export | NewsletterController and its helpers |
| Sequences | CRUD, steps, status, filters, variables, test mail, contacts, export | SequenceController, SequenceStepController and their helpers |
| Automations | `/automations`, `/visual-automations` | AutomationController, VisualAutomationController |
| Projects | `/projects` | ProjectController |
| Logs | `/email-logs`, `/contact-mails/{contactMail}` | EmailLogController, ContactMailController |
| Onboarding | `/onboarding/aws` | Onboarding\AwsOnboardingController |
| Settings | profile, password, appearance, two-factor, identities, AWS credentials, API tokens, storage, webhooks, team | `app/Http/Controllers/Settings` |
| Invitations | `GET/POST /invites/{user:uuid}` | InviteController |
| Admin (owner only) | `/admin/users`, `/admin/settings` | Admin\UserController, Admin\UserDeactivationController, Admin\SettingsController |
| Documentation | `/documentation`, `/documentation/api/*`, `/documentation/guides/*`, `/documentation/postman`, `/documentation/openapi`, `/byomailer-docs.md` | Documentation*Controller |

The dashboard pages are Inertia pages in `resources/js/pages`. Wayfinder makes the route helpers in `resources/js/actions`, `resources/js/routes` and `resources/js/wayfinder`. Git ignores these folders.

### REST API (`routes/api.php`, under `/api/v1`)

Each route takes a Sanctum token (`auth:sanctum`), `EnforceAccessLevel` and the `api` rate limiter (`API_RATE_LIMIT` requests each minute, default 300). The resources are contacts, tags, lists, list contacts, topics, blocks, email identities (read only), email templates (read only), newsletters, email logs, projects, sequences (read, stats, variables, step filters) and webhook endpoints.

A request to `/api/<path>` without `v1` gets a 308 redirect to `/api/v1/<path>`. The API and the MCP server always answer errors with JSON. A missing record gets a 404 that names the record, for example "No list with ID 5 exists in this account."

Scramble makes the OpenAPI document from the controllers, the FormRequests and the resources. `composer api-docs` writes `docs/api/openapi.yaml`, `public/docs/openapi.json` and the Postman files. `/docs/api` shows the Scramble UI. CI fails when the committed files are not current. Read the "API changes" section of `CONTRIBUTING.md`.

### MCP server (`routes/ai.php`)

`/mcp` serves `App\Mcp\Servers\ByoMailerServer` with the tools in `app/Mcp/Tools`. It takes a Sanctum token and the `mcp` rate limiter (60 requests each minute for each user).

`ByoMailerServer::createContext()` lists only the tools that the user and the token can use. A tool without the `IsReadOnly` annotation needs write access. A tool that changes a live record (a scheduled newsletter, a live sequence, a live step or a live automation) needs a second call with `confirm_live` set to true. The traits in `app/Mcp/Concerns` hold the shared tool logic.

### Self-hosted runtime

`README.md` describes the one-container Docker image and the operator commands: `byomailer:status`, `byomailer:doctor`, `byomailer:token:create`, `byomailer:token:list`, `byomailer:token:revoke`, `byomailer:mcp:connect`, and the `InstanceAdminServer` MCP server over stdio. This file does not describe their internals.

### Public routes

- `/` sends a guest to `/login` and a logged-in user to `/dashboard`.
- The contact links: `/unsubscribe/{contactMail}`, `/one-click-unsubscribe/{contactMail}` (RFC 8058, no CSRF token), `/resubscribe/{contactMail}`, `/opt-in/{contactMail}` and `/preferences/{contactMail}` (signed). A GET only shows a page. A POST, PATCH or DELETE changes the data.
- The SES webhook: `POST /webhooks/ses`.
- The TLS ask endpoint: `GET /tls/ask`.
- The health check: `/up`.

### Error pages

`resources/views/errors/` has branded pages for 401, 403, 404, 419, 429, 500 and 503. `errors/layout.blade.php` loads no build assets and no outside fonts. The pages show also when the frontend build is missing.

---

## Middleware

| Middleware | Purpose |
|-----------|---------|
| `RestrictHostToLinkRoutes` | Runs first. Limits a custom link domain to the contact link routes. |
| `TrustProxies` | Reads the trusted proxies from `TRUSTED_PROXIES`. The default is the Cloudflare ranges. |
| `AddSecurityHeaders` | Refuses frames from other sites and MIME sniffing on each response. |
| `HandleAppearance`, `HandleInertiaRequests` | Share the appearance cookie and the Inertia props. |
| `RedirectToSetup` | Sends the entry pages to `/setup` while the instance has no user. |
| `LogoutDeactivatedUser` | Ends the session of a deactivated user. |
| `EnsureUserIsActive` | Refuses an archived user and a user with an open invitation. |
| `EnsureUserIsOwner` | Opens the admin area to the owner only. Everyone else gets 404. |
| `EnforceAccessLevel` | Refuses a write request from a Viewer or from a token without `write`. Refuses each request from a token without `read`. |
| `EnforceReadAccess` | The read check for a POST route that only reads data, for example the audience preview. |
| `SetTenantFromContactMailLink` | Runs a contact link route as the client of the mail in the link. |
| `ValidateSignatureWithPreviousUrls` (`signed`) | Also accepts signatures made on a URL in `app.previous_urls`. |

The web group also has `AuthenticateSession`, so a password change ends the other sessions. `AppServiceProvider` registers the rate limiters: `ses-sending`, `newsletter-test-mail`, `newsletter-preview`, `tls-ask`, `mcp`, `api` and `webhook-test-ping`.

---

## Authentication & Authorization

- **Fortify** handles registration, login, password reset, email verification and two-factor authentication.
- **Registration** follows `App\Support\Registration`. The mode is `open` or `invite_only`. The owner sets it in **Admin > Settings**. Without a stored value, `REGISTRATION_ENABLED` gives the mode. In invite-only mode, a registration returns 404.
- An open registration creates a new client. The new user has the team role `admin`.
- **Team roles** (`users.role`): an `admin` manages the team and the webhook endpoints. An `editor` can change data. A `viewer` can only read. An `invited` user has an open invitation (`invite_expires_at`).
- A team admin invites a person with `UserInviteMail`. The person accepts at `/invites/{user:uuid}`. A team admin can archive a member (`archived_at`).
- **The owner** (`users.is_owner`) runs the instance. Only the owner opens `/admin` and `/horizon`, and deactivates or reactivates users (`deactivated_at`). The owner and the team role `admin` are two different roles.
- **Inactive users**: the `active-eloquent` user provider (`ActiveUserProvider`) does not return a deactivated user. A Sanctum token of a deactivated, archived or invited user does not authenticate.
- **Sanctum** gives API tokens with the abilities `read` and `write`. Tokens made before the abilities existed have `*`.
- **Sending**: `EmailSendingGate` blocks all campaign email of a deactivated user.

---

## Webhooks

A client can register webhook endpoints. The events are `contact.subscribed`, `contact.unsubscribed`, `contact.resubscribed`, `newsletter.sent`, `sequence_step.sent` and `sequence.completed` (`App\Enums\WebhookEvent`).

- `WebhookEventListener` sends each event to `WebhookDispatcher`. The dispatcher creates a `WebhookDelivery` and dispatches `DeliverWebhookJob` on the `webhooks` queue.
- The job signs the body with the secret of the endpoint. The `X-Byomailer-Signature` header is `sha256=` plus the HMAC-SHA256 of `{timestamp}.{body}`. The timestamp is in `X-Byomailer-Timestamp`.
- The job makes 5 attempts, with waits of 60, 300, 900, 3600 and 7200 seconds. `SsrfGuard` refuses a private or local host.
- After `WEBHOOKS_DISABLE_AFTER_FAILURES` failures in a row (default 15), the endpoint becomes inactive with the reason `auto_failures`.
- `model:prune` removes deliveries that are older than `WEBHOOKS_DELIVERY_RETENTION_DAYS` (default 30).

---

## Media Storage

Spatie Media Library stores the uploaded images. The default disk is `MEDIA_DISK` (default `public`).

- **User**: the `gallery` collection (JPEG, PNG, GIF and WebP).
- **Editor images**: the email editor stores an image in `editor-images/` on the disk of the user.

A user can connect an S3 or R2 bucket in **Settings > Storage**. When the connection test passes (`is_verified`), `UserFilesystemManager` registers a disk for that user and the uploads go to the bucket. `Support\Media\UserPathGenerator` and `UserUrlGenerator` make the paths and the URLs.

---

## Scheduled Commands

`routes/console.php` has the schedule. The server runs `php artisan schedule:run` each minute, or `schedule:work`.

| Command | When |
|---------|------|
| `email-identities:refresh-dns-status` | Every 15 minutes |
| `email-identities:check-tracking-domain-dns` | Every 30 minutes |
| `email-identities:check-link-domain` | Every 30 minutes |
| `visual-automations:process` | Every minute |
| `contact-mails:send-due-newsletters` | Every minute |
| `contact-mails:send-due-sequence-steps` | Every minute |
| `ses:fetch-quota` | Hourly |
| `ses:sync-configuration-sets` | Hourly |
| `ses:enable-event-destinations` | Hourly |
| `ses:send-permission-alerts` | Hourly |
| `ses:send-sequence-blocked-digest` | Daily at 09:00 |
| `geoip:download` | Monday and Thursday at 03:00, only with `MAXMIND_LICENSE_KEY` |
| `contacts:apply-sunset` | Daily at 04:00 |
| `model:prune` (`WebhookDelivery`) | Daily |
| `summary:send-weekly` | Hourly on Thursday, Friday and Saturday. It sends on Friday at 17:00 in the time zone of each account. |

`ses-events:poll` is not in the schedule. It is a long-running process, the same as a queue worker.

Other commands:

| Command | Job |
|---------|-----|
| `byomailer:install`, `byomailer:setup-url` | Create the owner, or print the setup URL. |
| `byomailer:user:list`, `byomailer:user:password` | List the users, or set a new password without email. |
| `byomailer:openapi`, `byomailer:postman`, `scramble:export` | Write the API documents. |
| `ses-events:migrate-to-sqs`, `ses:migrate-configuration-sets`, `ses:backfill-tenants` | Move old AWS setups to the current model. |
| `contacts:backfill-last-engaged`, `messages:resync-templates` | Backfill and rebuild data. |
| `email-identities:sync-hostname-origins` | Pin the Cloudflare custom hostnames to the origin server. |
| `tenancy:orphans` | List the rows with no client. |

---

## Tests

| Suite | Folder | Command |
|-------|--------|---------|
| PHPUnit (feature and unit) | `tests/Feature`, `tests/Unit` | `php artisan test --parallel` |
| Vitest (Vue and TypeScript) | `resources/js/**/*.test.ts` | `npm test` |
| Playwright (end to end) | `tests/e2e` | `npm run test:e2e` |
| SSR smoke test | `tests/Ssr` | `npm run test:ssr` |
| Load tests (k6) | `tests/load` | Read `tests/load/README.md` |

`phpunit.xml` uses SQLite, the `sync` queue and the `array` cache. `tests/Concerns` has helpers for SES notifications and the SES send queue. Vitest uses `happy-dom`.

`.github/workflows/ci.yml` runs Pint, Larastan, `composer audit`, the PHP tests and the JavaScript tests. The JavaScript job also runs `npm run types` and `npm run lint:changed`.

---

## Key Patterns & Conventions

- **BelongsToClient trait**: Each resource model uses it. Read [Multi-Tenancy](#multi-tenancy).
- **HasUuid trait**: Creates the UUID of a model on creation. Public links use the UUID, for example `/unsubscribe/{contactMail}`.
- **HasProjects trait**: Gives `projects()` and `syncProjects()` to newsletters, sequences, automations and visual automations.
- **ResolvesSegmentFilters trait**: A newsletter or a sequence reads its audience from its segment, or from its own filters.
- **Guarded = []**: Models allow mass assignment. FormRequests validate the input.
- **Enums for statuses and roles**: `app/Enums`, for example `NewsletterStatus`, `SequenceStatus`, `UserRole` and `SesEventType`.
- **One service for each status change**: The dashboard, the API and the MCP tools call the same service, for example `NewsletterScheduleService` and `SequenceStatusService`. They run the same checks.
- **Invokable controllers**: A non-CRUD action has its own invokable controller.
- **AWS calls in services**: Logic that calls AWS or Cloudflare stays in `app/Services`, not in a model.
