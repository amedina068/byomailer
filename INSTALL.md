# Install BYOMailer: instructions for an AI agent

You are an AI agent with a shell on a Linux server. Do the steps in this order. Each step gives the command, the expected result and the action for a failure.

## Rules

- Run each `byomailer:` command with `--json --no-interaction`.
- With `--json`, stdout holds exactly one JSON object. Messages go to stderr. Parse stdout only.
- The JSON object has this shape: `{"ok":true,"command":"byomailer:status","schema":1,"data":{},"errors":[]}`.
- Each item in `errors` has `code`, `message` and `fix`. If `ok` is `false`, read `fix` first.
- Exit codes: `0` success or no change, `1` a check or an action failed, `2` bad input, `3` conflict.
- Never delete the `byomailer` volume. It holds the database, the uploads and `APP_KEY`. `APP_KEY` encrypts the AWS keys of each account.
- Never edit files in the container. Use environment variables and `byomailer:` commands.
- Never print the API token or an AWS key in a public place. Give the token only to the human.
- Never send a real email to test the install. The human sends the first email after the SES setup.
- If a command fails two times with the same error, stop. Tell the human the `message` and the `fix`.

## 1. Ask the human

Ask for these values in one message. Do not guess them.

| Value | Example | Note |
|---|---|---|
| Domain | `mail.example.com` | Empty means `localhost`. |
| Owner name | `Ada Lovelace` | |
| Owner email | `ada@example.com` | |

If the domain is not `localhost`, tell the human to add a DNS A record for the domain to the IP address of the server.

## 2. Check the server

```bash
uname -m
docker info --format '{{.ServerVersion}}'
```

- `uname -m` must print `x86_64` or `aarch64`. If not, stop. The image supports only amd64 and arm64.
- If `docker` is not found, ask the human for approval. Then install Docker with `curl -fsSL https://get.docker.com | sh`.
- If `docker info` prints `permission denied`, run the next commands with `sudo`.
- If `docker info` cannot connect to the daemon, run `sudo systemctl start docker`.

## 3. Start the container

For a domain:

```bash
curl -fsSL https://raw.githubusercontent.com/amedina068/byomailer/main/install.sh \
  | APP_DOMAIN=mail.example.com bash -s -- --non-interactive
```

For localhost:

```bash
curl -fsSL https://raw.githubusercontent.com/amedina068/byomailer/main/install.sh \
  | bash -s -- --non-interactive
```

- Exit code `0`: the app answers on `/up`. Go to step 4.
- Exit code `1`: stderr has an `Error:` line and a `Fix:` line. Do the fix, then run the command again.
- The installer writes a log to `/tmp/byomailer-install.log`. Read it if the `Fix:` line is not enough.
- To see each command before it runs, add `--dry-run`.

You can run the installer again. It replaces the container and keeps the volume.

### MySQL mode (only if the human asks for it)

The default database is SQLite, and the default queue is the database queue. Use MySQL 8.4, Redis and Horizon only if the human asks for them. Do these steps instead of the installer:

```bash
git clone https://github.com/amedina068/byomailer.git
cd byomailer
cp .env.example .env
```

- In `.env`, set `APP_DOMAIN`. Set `DB_PASSWORD` and `DB_ROOT_PASSWORD` to two new values from `openssl rand -hex 24`.
- In `.env`, remove the `#` from the lines of the "MySQL and Redis" block. The block sets `COMPOSE_PROFILES=mysql`.
- Start the stack with `docker compose up -d --build`.
- Run `docker compose ps` every 10 seconds. Continue when the `web` service shows `healthy`.
- If `web` is not `healthy` after 5 minutes, run `docker compose logs --tail 50 web`. Report the last error line.
- In the next steps, replace `docker exec byomailer` with `docker compose exec -T web`. Run it in the `byomailer` folder.
- Never run `docker compose down -v`. It deletes the database volume.

## 4. Create the owner and an API token

```bash
docker exec byomailer php artisan byomailer:install \
  --name="Ada Lovelace" --email=ada@example.com \
  --with-token=agent --json --no-interaction
```

Read these fields from `data`:

| Field | Use |
|---|---|
| `status` | `created` for a new owner. `exists` if the instance has an owner. Both exit with `0`. |
| `login_url` | A one-time login link for the human. It works for 15 minutes. |
| `app_url` | The URL of the dashboard. |
| `next_steps.ses_credentials_url` | The page where the human connects the AWS account. |
| `token.value` | The API token for the MCP server and the REST API. |
| `connect.env` | The `export BYOMAILER_TOKEN=...` line. It is the only line that holds the token. |
| `connect.claude` | The `claude mcp add` command. It reads the token from `BYOMAILER_TOKEN`. |
| `connect.codex` | The `codex mcp add` command. It reads the token from `BYOMAILER_TOKEN`. |

- If `status` is `exists`, the command makes no new owner. It returns a new `login_url`. With `--with-token`, it makes a new token for the existing owner.
- If the exit code is `3` with the error code `users_without_owner`, the instance has users but no owner. Tell the human the `fix`. It uses `--promote`.
- The command needs no password. After the first login, the owner sets a password on the profile page.

## 5. Check the instance

```bash
docker exec byomailer php artisan byomailer:doctor --json --no-interaction
```

`data.checks` is a list. Each check has `id`, `status`, `message` and `fix`. `status` is `ok`, `warn` or `fail`.

1. If a check has `fail`, run `byomailer:doctor --fix --json --no-interaction` one time. This applies only safe fixes.
2. Run `byomailer:doctor --json --no-interaction` again.
3. If a check still has `fail`, tell the human its `message` and its `fix`.
4. Tell the human about each `warn`. A `warn` does not stop the install.

On a new instance, the SES checks are `warn`: `ses_credentials`, `ses_region`, `ses_quota`, `ses_identity` and `ses_events`. This is correct. The human connects SES after the first login. Give the human the `fix` of each SES check. The `ses_events` fix comes first, because the AWS connection needs the SQS queue.

For a short summary of the instance, run `byomailer:status --json --no-interaction`.

## 6. Connect the MCP server

Run these commands on the computer where the agent runs, not in the container. First run the `connect.env` line. Then run the `connect.claude` line. For Codex, run `connect.codex` instead of `connect.claude`.

```bash
export BYOMAILER_TOKEN='<token>'
claude mcp add --transport http byomailer https://mail.example.com/mcp \
  --header "Authorization: Bearer ${BYOMAILER_TOKEN}"
```

Codex reads `BYOMAILER_TOKEN` each time that it starts. Keep the variable in the environment of Codex.

To show the command again, or to make a new token:

```bash
docker exec byomailer php artisan byomailer:mcp:connect --client=claude --json
docker exec byomailer php artisan byomailer:mcp:connect --client=claude --token=new --json
```

`--client` also accepts `codex`, `cursor` and `json`.

To test the connection, call the `list-newsletters-tool` tool. An empty list is a success. Do not call a tool that sends email.

### The instance-admin MCP server

A second MCP server operates the instance: status, doctor, users, tokens, the queue, the SES status and the accounts. It runs over SSH and `docker exec`, with no token and no open port:

```bash
claude mcp add byomailer-admin -- ssh <host> docker exec -i byomailer php artisan mcp:start byomailer-admin
```

A destructive tool returns a preview first. It changes data only after a second call with `confirm: true`. Creating the owner, promoting a user, rotating keys and deleting data stay CLI-only.

## 7. Report to the human

Give the human these items:

- The app URL: `https://<domain>`, or `http://localhost:8080`.
- The `login_url` from step 4. It works one time, for 15 minutes.
- The next step: "Log in. The dashboard shows the Amazon SES steps. First create the SES event queue, then connect your AWS account."
- The `connect.env` and `connect.claude` lines, if the human runs the agent on another computer.
- The results of step 5 that are not `ok`.
- This warning: "Back up the `byomailer` volume. It holds the database and `APP_KEY`. Without `APP_KEY`, each account must enter its AWS keys again."

## Common failures

| Symptom | Action |
|---|---|
| `Port 80 is in use` or `Port 443 is in use` | Find the process with `sudo ss -ltnp`. Ask the human before you stop it. |
| `The container stopped.` | Run `docker logs --tail 50 byomailer`. Report the last error line. |
| The browser cannot open the domain | Run `getent hosts <domain>`. It must return the IP address of the server. If not, the DNS record is missing. |
| No HTTPS certificate | Make sure that ports 80 and 443 are open in the firewall. Caddy needs both. |
| The setup URL is lost | Run `docker exec byomailer php artisan byomailer:setup-url --json`. |
| A user cannot log in | Run `docker exec byomailer php artisan byomailer:user:password <email> --generate --json`. |
| `users_without_owner`, exit code `3` | Run `byomailer:user:list --json`. Ask the human which user becomes the owner. |
| The `ses_events` check is `warn` | The instance does not receive bounces and complaints. Give the human the [SES events guide](https://byomailer.com/docs/self-hosting/ses-events). |
