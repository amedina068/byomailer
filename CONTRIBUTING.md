# Contributing to BYOMailer

This guide tells you how to run BYOMailer on your computer, how to test a change, and which rules the code follows.

## Stack

- PHP 8.4 and Laravel 13
- Inertia 3, Vue 3.5, Tailwind CSS 4, and Vite 7
- Laravel Fortify for authentication, Sanctum for API tokens, Horizon for queues, and Laravel MCP for the MCP server
- PHPUnit 12 for the PHP tests and Vitest 4 for the JavaScript tests
- MySQL in production. The tests and local development use SQLite.

## Local setup

1. Install PHP 8.4 with the `bcmath`, `exif`, `gd`, `intl`, `pdo_sqlite`, and `redis` extensions.
2. Install Composer and Node 24. The `.nvmrc` file names the Node version.
3. Install the dependencies, create `.env`, generate the key, run the migrations, and build the frontend:

   ```bash
   composer setup
   ```

   `.env.example` is for a real install, so it sets `APP_ENV=production` and `APP_DEBUG=false`. For development on your computer, set `APP_ENV=local` and `APP_DEBUG=true` in `.env`.

4. Create the owner account in the terminal:

   ```bash
   php artisan byomailer:install
   ```

   To use the setup page instead, open the app and enter the setup token. Run `php artisan byomailer:setup-url` to get the URL with the token.

5. Start the app server, the queue worker, the log viewer, and Vite:

   ```bash
   composer dev
   ```

> Note: PHP 8.5 does not work yet. One dependency (`phpoffice/phpspreadsheet`) does not accept it.

## Tests

Run the PHP tests. `--parallel` runs them in one process for each CPU core:

```bash
php artisan test --compact --parallel
```

Run one file or one test:

```bash
php artisan test --compact tests/Feature/InstallCommandTest.php
php artisan test --compact --filter=test_it_refuses_to_run_when_a_user_already_exists
```

Run the JavaScript tests:

```bash
npm test
```

The Vue code imports route helpers that Wayfinder generates. If Vitest cannot find `@/routes` or `@/actions`, run `php artisan wayfinder:generate --with-form` first.

The test base class calls `Http::preventStrayRequests()`. A test must fake each HTTP call. A test must not call AWS. Mock the SES, SNS, and SQS services the way the existing tests do. `phpunit.xml` sends each AWS SDK call to a closed local port, so a call that a test does not mock fails at once.

### End-to-end tests

The Playwright tests in `tests/e2e/` open the app in Chromium. They test the login, the public pages, contacts, and tags.

Run them from a checkout that has the Composer and npm dependencies:

```bash
npx playwright install chromium
npm run build
npm run test:e2e
```

`npm run test:e2e` starts the app itself. It does these steps:

1. `tests/e2e/prepare-env.mjs` writes `.env.e2e` from `.env.example`. The file sets a new SQLite file in the temporary folder, the `array` mailer, the `sync` queue, no SSR, and an AWS endpoint on a closed local port.
2. `php artisan migrate:fresh --env=e2e --seed --seeder="Database\Seeders\E2eSeeder"` makes a known state. The seeder makes the owner `e2e@example.com` with the password `password`, one tag, and one contact.
3. `php artisan serve --env=e2e` serves the app on port 8123.

The tests use the built Vite assets. After you change the frontend, run `npm run build` again.

The tests need no `.env` file and no secret. The script keeps the `APP_KEY` of an earlier `.env.e2e`. If the file has no key, the script generates a new key. `.gitignore` excludes `.env.e2e`.

You can change the defaults with these variables:

- `E2E_PORT`: the port of the app. The default is `8123`.
- `E2E_PHP`: the PHP binary. The default is `php`.
- `E2E_DATABASE`: the path of the SQLite file.
- `E2E_BROWSER_CHANNEL`: set it to `chrome` to use the installed Google Chrome instead of the Chromium of Playwright.

The `End-to-end tests` job in `.github/workflows/ci.yml` runs these steps from a clean checkout, with PHP 8.4 and Node 24:

```bash
composer install --no-interaction --no-progress --prefer-dist
npm ci
npx playwright install --with-deps chromium
npm run build
CI=true npm run test:e2e
```

If a test fails, Playwright writes a trace to `test-results/`. With `CI` set, it also writes an HTML report to `playwright-report/`. When the CI job fails, it uploads the two folders as the `playwright-report` artifact.

The tests do not cover the setup page and the sign-up page. The seeder makes the owner, so the setup page is closed. Registration is off by default. One test makes sure that the setup page answers 404.

## Code style and checks

Format the PHP code with Pint before you commit. Pint also replaces an inline fully-qualified class name with a `use` import:

```bash
vendor/bin/pint --dirty
```

Run the static analysis (Larastan, level 6). `phpstan-baseline.neon` holds the old errors, so only a new error fails. Do not add an entry to the baseline. Correct the code:

```bash
composer analyse
```

`tests/Feature/ConventionsTest.php` checks the project rules in the source: no untyped `$...Id` controller parameters, no `app()` or `resolve()` in `app/`, no `$fillable`, no `declare(strict_types=1)`, short methods, and short Vue files. Each check has an allowlist of old violations. The allowlist can only become shorter. When you correct an old violation, remove its entry.

Check the frontend types, and lint and format check the frontend files that your branch changes:

```bash
php artisan wayfinder:generate --with-form
npm run types
npm run lint:changed
```

CI runs Pint, Larastan, the PHP tests, the type check, ESLint and Prettier on the changed files, the JavaScript tests, a production build, the API documentation check, and the end-to-end tests on each pull request.

CI also runs `composer audit --locked`. The audit reads `composer.lock` and reports the packages that have security advisories. At the moment the lock file has known advisories, so a failed audit shows a warning and does not fail the run. Run the same check on your computer:

```bash
composer audit --locked
```

A new push to a pull request cancels the CI run of the old commit. Each push to `main` runs to the end.

## Rules for PHP code

- Do not add `declare(strict_types=1);`.
- Import each class with `use` at the top of the file. Do not write an inline fully-qualified name, also not in docblocks and tests.
- Models use `protected $guarded = [];`. Validate input in a FormRequest.
- Give each method a return type and a type for each parameter.
- Put logic that talks to an external API in a service under `app/Services/`, not in a model.
- Give a non-CRUD action its own invokable controller. One controller method returns one type of response.
- Do not write SQL for one database engine. Production runs MySQL and the tests run SQLite. Build JSON expressions through the query grammar.

## Multi-tenancy

A `Client` has many users. Each resource model uses the `BelongsToClient` trait, which adds `ClientScope`.

- `ClientScope` filters by the current tenant, `Tenant::id()`. A request gets the tenant from the logged-in user, and each job that the request dispatches gets the same tenant.
- With no tenant, a query on a client model throws `MissingTenantException` in tests and local. In production, it matches no rows.
- In a command, a cron job, or a webhook, run the work of one client inside `Tenant::run($clientId, ...)`. For a scan of all clients, use `withoutGlobalScopes()` and filter by `client_id` yourself.
- The unsubscribe, preference, and opt-in pages have no logged-in user. The mail in the link gives the tenant. Make sure that the contact belongs to the same client as the email before you change data.

`SendPermissionAlertCommand`, `ProcessSesEventJob`, and `SendNewsletterChunkJob` show the correct pattern for background work.

## Email rules

- Send each campaign email through `UserMailerFactory::mailerFor($user)`, so it uses the SES credentials of the account. Do not use `Mail::to()` for campaign email.
- Gate each new send path with `EmailSendingGate::evaluate()`. Check the content with `SendableContentCheck::problems()` before a newsletter or a sequence step goes live. That check blocks a blank subject or a blank body.
- Show the result of `SendableContentCheck::warnings()` to the user. It reports content without `{{ unsubscribe_url }}` and accounts without a postal address. These are the responsibility of the sender, so they never block a send.
- If you add a route that a contact opens from an email, add its path to `RestrictHostToLinkRoutes::ALLOWED_PATHS`. Build its URL through `ContactMail::linkDomainHost()`, so it works on custom link domains.
- Content uses Liquid. `App\Support\Liquid` renders it in a sandbox: no file system, no PHP objects, a time limit, an output limit and a loop limit. Each `{{ }}` output in HTML is escaped. Content with only `{{ variable }}` placeholders is plain. The old replacement (`ReplacesTemplateVariables`) still renders plain content, so its output does not change. `tests/Feature/LegacyContentRenderTest.php` locks that output.
- Render a message through `Message::render()`. It picks Liquid or the old replacement. The mailable adds `unsubscribe_url` and `opt_in_url` when it renders the email.
- SES bulk templates know no Liquid. A newsletter with Liquid code sends one email for each contact through the mailer.
- Add the `ValidLiquid` rule to each new field that saves a subject, a preview text or a body.

## API changes

The OpenAPI document that Scramble generates is the API contract. `docs/api/` has the committed copy:

- `openapi.yaml`: the OpenAPI 3.1 document
- `postman_collection.json`: the Postman collection, with one folder for each tag
- `postman_environment.json`: the Postman environment, with the `baseUrl` and `token` variables

`public/docs/openapi.json` has the same document as JSON. The documentation pages serve it.

When you add or change an endpoint:

1. Give the controller method a docblock. The first line is the summary. The next paragraph is the description.
2. Put a `#[Group]` attribute on a new controller. Use one of the existing tag names, or add a new tag with a description and a weight.
3. If the method reads a query parameter without `$request->validate()`, add a `#[QueryParameter]` attribute.
4. Run `php artisan scramble:analyze`. It must report no errors.
5. Make the OpenAPI document and the Postman files again, and commit the changes in `docs/api/` and `public/docs/openapi.json`:

   ```bash
   composer api-docs
   ```

   The script runs `php artisan byomailer:openapi` and then `php artisan byomailer:postman`. The second command runs the pinned `openapi-to-postmanv2` with `npx`, so it needs Node and a network connection the first time. Scramble reads the column types from the database, so run the migrations first.

6. Update the Postman collection of the dashboard in `app/Http/Controllers/DocumentationPostmanController.php`.
7. Add request and response examples to `public/docs/api-documentation.md`.
8. For a new resource, add a `documentation.api.<resource>` route in `routes/web.php`, a method in `DocumentationApiController`, a page in `resources/js/pages/documentation/api/`, and an entry in `resources/js/composables/useDocumentationSidebar.ts`.

CI runs `composer api-docs` and fails when `docs/api/` or `public/docs/openapi.json` changes.

`public/docs/` uses `https://byomailer.example` as the example host. The app replaces it with `APP_URL` when it serves the files. The Postman files use `https://app.example.com` and `YOUR_API_TOKEN` as placeholder values.

## Frontend rules

- Use Node 24. Vite 7 and Vitest 4 fail on older versions.
- Follow `STYLE_GUIDE.md`: Phosphor icons in the regular weight, the `bm-*` CSS variables, a maximum radius of 6px, and JetBrains Mono for numbers.
- `npm run build` does not check types. Run `npm run types` and `npm run lint:changed`.
- When a deferred prop loads on a page, show a skeleton until it arrives.

## Release notes

If a pull request has a change that users can see, add a `## Release note` section to its body. Write one or two short sentences in plain text, not Markdown or HTML. Tell the user what changed.

The release notes are in `getting-started/release-notes.md` of the [docs repository](https://github.com/amedina068/byomailer-docs). When a release goes out, the maintainer copies the notes of the merged pull requests to that page. Do not add a release notes file to this repository.

## Changelog

If a pull request changes the behavior for users, add one fragment file to [changelog.d/](changelog.d/README.md). Do not edit [CHANGELOG.md](CHANGELOG.md) in the pull request. Each fragment is a new file, so two pull requests cannot have a merge conflict in the changelog.

The file name is `<name>.<section>.md`. The section is one of the Keep a Changelog sections: `added`, `changed`, `deprecated`, `removed`, `fixed`, or `security`. For example, `changelog.d/newsletter-send-claim.fixed.md` contains one list item:

```markdown
- A newsletter starts one send only.
```

At release time, `scripts/changelog-release.sh` moves the fragments into `CHANGELOG.md`.

## Pull requests

1. Read the [code of conduct](CODE_OF_CONDUCT.md). It applies to issues, pull requests, and reviews.
2. To report a bug or to ask for a feature, open an issue with the matching template. If the change is large, open an issue first.
3. Make a branch from `main`.
4. Write or update a test for each change.
5. Run Pint and the tests for the code that you changed.
6. Open a pull request. Fill in the pull request template. Tell what changed and why.

## Security

Do not open a public issue for a security problem. Report it in private through a GitHub security advisory. [SECURITY.md](SECURITY.md) gives the steps.
