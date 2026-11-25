# BYOMailer

BYOMailer is an email marketing app that sends through your own Amazon SES account. You keep your contacts, your sending reputation, and your AWS bill. BYOMailer adds the lists, the editor, the campaigns, and the tracking on top of SES.

BYOMailer is a Laravel 13 app with an Inertia and Vue 3 dashboard. You can run it with Docker on one server.

![BYOMailer dashboard](docs/screenshots/dashboard.png)

> The screenshots are not in the repository yet.

## Features

- **Newsletters.** Write in a block editor, select the audience with filters or saved segments, then send now or schedule. Do A/B tests on the subject or the content.
- **Sequences.** Build drip campaigns with a delay between steps. Each step can have its own audience filters.
- **Automations.** Start actions when a contact joins a list, gets a tag, finishes a sequence, or has an attribute change. A visual builder is also available.
- **Contacts.** Lists, topics, tags, and custom attributes. Import and export CSV files. Each contact has a preference center and a one-click unsubscribe link (RFC 8058).
- **Templates and blocks.** Reusable email templates and content blocks with `{{ variable }}` placeholders.
- **Deliverability.** BYOMailer records deliveries, bounces, complaints, opens, and clicks from SES. It suppresses addresses that bounced or complained. A reputation page shows the rates that AWS monitors.
- **Custom domains.** Optional custom tracking domains and custom link domains for each sending identity.
- **Teams.** Invite users with the admin, editor, or viewer role. Two-factor authentication is available.
- **API, webhooks, and MCP.** A REST API (`/api/v1`) with OpenAPI docs, outgoing webhooks, and an MCP server (`/mcp`) for AI agents. The OpenAPI document is in [`docs/api/openapi.yaml`](docs/api/openapi.yaml). The Postman collection and environment are in the same folder.
- **Compliance warnings.** Laws such as CAN-SPAM and GDPR ask for an unsubscribe link and a postal address in marketing email. Each sender is responsible for them. BYOMailer does not block a send without them. It shows a warning in the editor and before a newsletter or a sequence goes live. The MCP tools return the same warnings. BYOMailer adds the one-click `List-Unsubscribe` header to each campaign email.

## How it works

Each account in BYOMailer connects its own AWS access keys. BYOMailer uses them to verify domains, create SES configuration sets, and send email through SES.

SES events come back through Amazon SNS and Amazon SQS. When an account connects, BYOMailer creates an SNS topic in that AWS account. The topic sends the events to one SQS queue that you own. The `ses-events:poll` worker reads the queue and updates the contacts.

## Self-host with Docker

The Docker setup uses the [serversideup/php](https://serversideup.net/open-source/docker-php/) image (PHP 8.4, PHP-FPM, and NGINX). `docker-compose.yml` starts these services:

| Service | Purpose |
|---|---|
| `web` | The dashboard, the API, and the unsubscribe pages. It runs the database migrations each time it starts. |
| `horizon` | The queue workers (Laravel Horizon). |
| `scheduler` | The Laravel scheduler. It sends the scheduled newsletters and the sequence steps. |
| `ses-events` | Reads the SES events from SQS. It starts only with the `ses` profile. |
| `mysql` | MySQL 8.4. |
| `redis` | Redis for the queues, the cache, and the sessions. |

### Requirements

- Docker with Docker Compose 2.24 or later.
- A domain name and a reverse proxy with TLS in front of port 8080. Open and click tracking and the unsubscribe links need HTTPS.
- An AWS account with SES for each BYOMailer account, and one SQS queue for the SES events.

### Install

1. Clone the repository and go into its directory.
2. Copy the example settings:

   ```bash
   cp .env.example .env
   ```

3. Generate an application key:

   ```bash
   openssl rand -base64 32
   ```

4. In `.env`, set `APP_KEY=base64:` followed by the key from step 3.
5. In `.env`, set these values:

   ```dotenv
   APP_URL=https://mail.example.com
   DB_PASSWORD=change-me
   DB_ROOT_PASSWORD=change-me-too
   ```

6. Start the stack:

   ```bash
   docker compose up -d --build
   ```

7. Wait until `docker compose ps` shows the `web` service as healthy. The first start runs all the migrations, so it can take some minutes.
8. Read the setup URL in the log of the `web` container:

   ```bash
   docker compose logs web
   ```

9. Open the setup URL and create the owner account. Then BYOMailer signs you in.
10. In Settings, Profile, enter the postal address of your company. Put `{{ postal_address }}` in the footer of your templates. The field is optional, but BYOMailer shows a warning while it is empty.

The setup page is open only while the instance has no account. The URL holds a token that comes from `APP_KEY`. If you lose the URL, run `docker compose exec web php artisan byomailer:setup-url`.

To create the owner from the command line instead of the setup page, run `docker compose exec web php artisan byomailer:install`.

> **CAUTION: Back up `APP_KEY` now.** BYOMailer encrypts the AWS credentials of each account with this key. If you lose the key, every account must enter its AWS credentials again. Keep a copy outside the server.

### Use the published image

Each release publishes a Docker image to the GitHub Container Registry as `ghcr.io/amedina068/byomailer`, for `linux/amd64` and `linux/arm64`. With the image, you do not need to clone the repository or build:

1. Put `docker-compose.image.yml` and `.env.example` in an empty folder.
2. Do steps 2 to 5 above.
3. Start the stack:

   ```bash
   docker compose -f docker-compose.image.yml up -d
   ```

4. Do steps 7 to 10 above. Add `-f docker-compose.image.yml` to each `docker compose` command.

The default tag is `latest`. To stay on one version, set `BYOMAILER_VERSION` in `.env`, for example `BYOMAILER_VERSION=0.1.0`.

### Environment variables

`.env.example` lists each variable with a comment. These are the most important ones:

| Variable | Purpose |
|---|---|
| `APP_KEY` | Encrypts the sessions and the stored AWS credentials. Back it up. |
| `APP_URL` | The public HTTPS URL. The emails and the API examples use it. |
| `APP_PORT` | The host port of the `web` service. The default is `8080`. |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | The MySQL passwords. Docker Compose needs both. |
| `REGISTRATION_ENABLED` | The registration mode until the owner sets one in Admin, Settings. `false` (invite only) by default. See [Accounts and registration](#accounts-and-registration). |
| `ADMIN_EMAILS` | Only for the update of an older install. The update makes the users in this comma-separated list owners, if their email is verified. Only the owner can open Horizon at `/horizon`. |
| `MAIL_MAILER`, `MAIL_*` | The mailer for invites and password resets. Campaigns do not use it. |
| `PLATFORM_AWS_*`, `SES_EVENTS_SQS_QUEUE_*` | The SQS queue for the SES events. See [SES events](#ses-events-sqs). |

The integrations below are optional. When their variables are empty, the feature is off and BYOMailer makes no network call for it.

| Variables | Feature |
|---|---|
| `MAXMIND_LICENSE_KEY` | The city and the country of opens (MaxMind GeoLite2). The database is not in the repository, because its license does not allow redistribution. |
| `ROLLBAR_TOKEN` | Error reports to Rollbar. Also add `rollbar` to `LOG_STACK`. |
| `CLOUDFLARE_*` | Custom link domains through Cloudflare for SaaS. |
| `TRACKING_PROXY_*` | Custom tracking domains through a Caddy proxy with on-demand TLS. |
| `BYOMAILER_API_*` | Adds each new account to a list on another BYOMailer server. |
| `SNAPSBREW_*` | Open Graph images for the documentation pages. |

### The owner

The owner runs the instance. The owner can open the admin area at `/admin` and Horizon at `/horizon`. The setup page at `/setup` or the `byomailer:install` command creates the owner.

The owner is not the same as the admin role of a team. A team admin manages one account. The owner manages all the users of the instance.

To make an existing user an owner, run:

```bash
docker compose exec web php artisan byomailer:install --promote=person@example.com
```

When you update an older install, the migration makes each user in `ADMIN_EMAILS` an owner. The user must have a verified email. The owner must have a verified email to open Horizon.

### Accounts and registration

The instance has two registration modes:

- **Invite only.** The sign-up page at `/register` is closed. A team admin invites each new user to an account in Settings, Team.
- **Open.** Anyone can sign up at `/register`. Each new user gets a new account.

In both modes, team invites work. `REGISTRATION_ENABLED` gives the mode until the owner sets one. To change the mode, open **Admin > Settings**. The mode that the owner sets stays in the database, and it overrides `REGISTRATION_ENABLED`.

### Deactivate a user

The owner can deactivate a user in **Admin > Users**. A deactivated user keeps all data, but BYOMailer refuses the user everywhere:

- The login form refuses the user and shows the reason.
- An open web session and a "Remember me" login end at the next request.
- The API tokens of the user stop working on the API (`/api/v1`) and on the MCP server (`/mcp`).
- The invitation link of an invited user stops working.
- BYOMailer does not send email as the user. A newsletter of the user does not start, and the queued parts of a newsletter that is sending stop. A sequence email to a contact of the user fails with the reason `user_deactivated`.

To restore access, click **Reactivate**. The API tokens of the user work again. The sequence emails that failed are not sent again. The owner cannot deactivate an owner.

Deactivation is not the same as the archive action of a team admin. A team admin archives a member of one account. The owner deactivates a user of the whole instance.

### Recover access

The "forgot password" form sends an email through `MAIL_MAILER`. If your instance has no working mailer, use these commands to recover an account.

Find the user:

```bash
docker compose exec web php artisan byomailer:user:list --search=person
```

Set a new password. The command asks for the password two times:

```bash
docker compose exec web php artisan byomailer:user:password person@example.com
```

- To set the password from a script, add `--password=<new password>`.
- To get a random password, add `--generate`. The command shows the password one time.
- If the user lost the two-factor device, add `--reset-two-factor`. This option turns off two-factor authentication for the user.

The new password must obey the same rules as the web form. The command also ends the "Remember me" logins of the user. With the `database` session driver, it removes the web sessions of the user. With other drivers, for example `redis` in the Docker stack, each web session ends at its next request. The command does not reactivate a deactivated user.

### AWS SES setup

Each account connects its own AWS account in the dashboard. The guides in the app, at `/documentation/guides/getting-started`, show each step with screenshots. In short:

1. In the AWS account that sends the email, create an IAM user for BYOMailer.
2. Attach the IAM policy that the BYOMailer AWS onboarding screen shows. It allows the SES, SNS, and SES tenant calls that BYOMailer makes.
3. Create an access key for the IAM user.
4. In BYOMailer, open the AWS onboarding. Enter the access key, the secret key, and the region.
5. In Settings, Identities, add a sending domain. Publish the DNS records that BYOMailer shows.
6. If the SES account is in the sandbox, request production access in the SES console.

### SES events (SQS)

SES events reach BYOMailer through one SQS queue. You create the queue one time for the full install.

1. In the AWS account that runs BYOMailer, create a standard SQS queue, for example `byomailer-ses-events`.
2. Give the queue an access policy that lets the SNS topics of the sending accounts send to it:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Principal": { "Service": "sns.amazonaws.com" },
         "Action": "sqs:SendMessage",
         "Resource": "arn:aws:sqs:us-east-1:111111111111:byomailer-ses-events",
         "Condition": {
           "ArnLike": { "aws:SourceArn": "arn:aws:sns:*:222222222222:mailautomation-*" }
         }
       }
     ]
   }
   ```

   `111111111111` is the account of the queue. `222222222222` is an account that sends email. Add one `aws:SourceArn` value for each sending account.

3. Create an IAM user for the worker. Give it `sqs:ReceiveMessage`, `sqs:DeleteMessage`, and `sqs:GetQueueAttributes` on the queue.
4. In `.env`, set these values:

   ```dotenv
   PLATFORM_AWS_ACCESS_KEY_ID=...
   PLATFORM_AWS_SECRET_ACCESS_KEY=...
   PLATFORM_AWS_REGION=us-east-1
   SES_EVENTS_SQS_QUEUE_URL=https://sqs.us-east-1.amazonaws.com/111111111111/byomailer-ses-events
   SES_EVENTS_SQS_QUEUE_ARN=arn:aws:sqs:us-east-1:111111111111:byomailer-ses-events
   ```

5. Start the worker:

   ```bash
   docker compose --profile ses up -d
   ```

If the worker does not run, BYOMailer does not see bounces and complaints. It then continues to send to addresses that SES rejects, and AWS can pause your SES account.

### Update

1. Back up the `mysql` volume.
2. Pull the new code.
3. Rebuild and restart the stack:

   ```bash
   docker compose up -d --build
   ```

The `web` service runs the new migrations before it serves requests.

With the published image, run `docker compose -f docker-compose.image.yml pull`, then `docker compose -f docker-compose.image.yml up -d`. [CHANGELOG.md](CHANGELOG.md) lists the changes of each release.

### Backups

Back up these items:

- `APP_KEY` and the rest of `.env`.
- The `mysql` volume. For example, run `mysqldump` with `docker compose exec mysql`.
- The `storage` volume. It holds the images that users upload.

## Install without Docker

The [`deploy/`](deploy) folder has example files for a server without Docker:

| File | Purpose |
|---|---|
| `deploy/nginx/byomailer.conf` | An NGINX server block with PHP-FPM and TLS. |
| `deploy/systemd/*.service` | systemd units for Horizon, the scheduler, and the SES events worker. |
| `deploy/supervisor/byomailer.conf` | The same three processes for Supervisor. Use systemd or Supervisor, not both. |
| `deploy/tracking-proxy/Caddyfile` | The Caddy proxy for custom tracking domains. |

## Development

Read [CONTRIBUTING.md](CONTRIBUTING.md) and the [code of conduct](CODE_OF_CONDUCT.md).

## Security

Do not open a public issue for a security problem. [SECURITY.md](SECURITY.md) tells you how to report it in private.

## Support

One person maintains this project. An answer to an issue or a pull request can take some days.

## License

BYOMailer is free software under the [GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0). If you change BYOMailer and let other people use it over a network, you must offer them the source code of your changed version.
