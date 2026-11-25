# Changelog fragments

Each pull request that users can see adds one file to this folder. The pull request does not edit `CHANGELOG.md`. Two pull requests that add different files cannot have a merge conflict.

## Add a fragment

1. Make a file with the name `<name>.<section>.md`.
   - `<name>` is a short name for the change, for example the branch name without the prefix: `newsletter-send-claim`.
   - `<section>` is one of the Keep a Changelog sections in lowercase: `added`, `changed`, `deprecated`, `removed`, `fixed`, or `security`.
2. Write one entry as a Markdown list item that starts with `- `.

Example `changelog.d/newsletter-send-claim.fixed.md`:

```markdown
- A newsletter starts one send only.
```

## Release

1. Run `scripts/changelog-release.sh`.
   The script moves each fragment into its section under `## [Unreleased]` in `CHANGELOG.md`. Then it removes the fragment files.
2. Read the result in `CHANGELOG.md`.
3. Commit `CHANGELOG.md` and the removed fragments together.
