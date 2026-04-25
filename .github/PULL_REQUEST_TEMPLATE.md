<!--
Thanks for the contribution. Please complete the checklist below.

Use Conventional Commits in the title and commit messages:
  feat:    new feature
  fix:     bug fix
  docs:    documentation only
  refactor: code change that does not change behavior
  test:    test-only change
  chore:   tooling, deps, build
  ci:      CI workflow change

Breaking changes use `feat!:` / `fix!:`.
-->

## Summary

<!-- 1-3 sentences. What does this PR do, and why? -->

## Related issues

<!-- e.g. Closes #123, refs #456. Required for non-trivial changes. -->

## Changes

<!-- Bulleted list of the user-visible changes. Keep it tight. -->

-

## Checklist

- [ ] Pest tests added or updated for new behavior
- [ ] `composer quality` passes locally (rector --dry-run + pint + phpstan max + pest)
- [ ] `CHANGELOG.md` updated under `[Unreleased]` (Added / Changed / Deprecated / Removed / Fixed / Security)
- [ ] README / docs updated if user-facing API or config changed
- [ ] Conventional Commits used in commit messages
- [ ] No new `phpstan-ignore` annotations added (or each one documented inline)

## Notes for reviewers

<!-- Anything reviewers should pay extra attention to: tradeoffs, follow-ups, deferred work. -->
