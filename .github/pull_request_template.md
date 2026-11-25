## Summary

<!-- Say what the change does and why. Link the issue, for example "Closes #12". -->

## Release note

<!-- If users can see the change, write one or two short sentences in plain text. The maintainer copies them to the release notes in the docs repository. If users cannot see the change, remove this section. -->

## Checks

- [ ] `php artisan test --compact --parallel` passes.
- [ ] `vendor/bin/pint --test` and `composer analyse` pass.
- [ ] `npm test` and `npm run build` pass (when the frontend changes).
- [ ] `npm run types` and `npm run lint:changed` pass (when the frontend changes).
- [ ] `php artisan migrate:fresh --seed` passes (when a migration changes).
- [ ] `php artisan scramble:analyze` reports no errors, and `public/docs/openapi.json` is up to date (when the API changes).

## Compatibility

- [ ] The change has no database, configuration or public API change, or the change is described below.
- [ ] The MCP tools and the REST API keep their contract, or the change is described below.
- [ ] The change sends no email through a new path, or the new path uses `UserMailerFactory::mailerFor()` and `EmailSendingGate::evaluate()`.
- [ ] A fragment in `changelog.d/` describes the change (when users see the change). The pull request does not edit `CHANGELOG.md`.
- [ ] A screenshot or a terminal output is attached for a visible change.

<!-- Describe the migration, the configuration, the API change and how to roll back. -->
