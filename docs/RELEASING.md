# Releasing

> Audience: the maintainer who cuts a kit release, and plugin developers who upgrade to one.

One tag releases both artifacts:

| Artifact | Registry | Published by |
| --- | --- | --- |
| `bitapps/wp-ai-kit` (PHP) | Packagist | Packagist reads the git tag (GitHub webhook) |
| `@bitapps/wp-ai-kit-ui` (React) | npm, public, `@bitapps` scope | `.github/workflows/release.yml` on the same tag |

## Versioning

- **SemVer, one version for both.** Tag `vX.Y.Z`; `frontend/package.json` `version` must
  be `X.Y.Z`. The release workflow refuses a tag that does not match.
- **Additive within a major.** A minor adds features (new routes, props, hooks,
  optional `features` keys). A patch fixes. Anything a consumer must change goes in
  `UPGRADING.md` and needs a major.
- **The wire contract has its own number.** `Contract::CONTRACT_VERSION` and
  `frontend/package.json` `"contract"` change only when a hook payload, a route shape or
  a name scheme changes incompatibly. `frontend/scripts/check-contract.mjs` checks that
  they match; CI runs it. `AiKitProvider` warns in the console on a mismatch.

## Before the tag

1. **All checks green on `main`:**
   ```bash
   composer check                        # PHP: lint, 8.0 compat, prefix safety, rector, tests
   composer test-integration             # the kit's tables against MySQL (WP_TESTS_DIR, WP_TESTS_CONFIG_FILE_PATH)
   cd frontend && pnpm check             # UI: types, tests, build, size budgets
   pnpm i18n:check && pnpm contract:check
   ```
2. **Changelog.** `CHANGELOG.md`: move `[Unreleased]` under `## [X.Y.Z] - YYYY-MM-DD`
   and keep an empty `[Unreleased]` on top. `UPGRADING.md` only when something breaks.
3. **Version.** Set `frontend/package.json` `version` to `X.Y.Z`. The PHP package has no
   version field; the tag is its version.
4. **Try it in a consumer** (Bit Form) before tagging:
   - the PHP path repository (see `CONTRIBUTING.md`);
   - `"@bitapps/wp-ai-kit-ui": "link:../../_wp-ai-kit/frontend"` in its `package.json`;
   - its own suites.
5. **Security.** A release that adds or changes a REST route, an auth path or a tool
   runner rule gets a review against `docs/SECURITY.md`.

## Cut the release

```bash
git checkout main && git pull
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin vX.Y.Z
```

- **Packagist** picks the tag up within minutes. The first time, submit the repository
  on packagist.org and enable the GitHub hook.
- **npm.** `release.yml` installs, runs `pnpm check`, then
  `npm publish --provenance --access public` from `frontend/`.
  - **One-time setup:** create the npm organisation `bitapps`, then add an automation
    token as the repository secret `NPM_TOKEN`. Provenance needs the workflow's
    `id-token: write` permission, which it has.
- **GitHub release.** Create one from the tag and paste the changelog section.

To publish by hand instead (first release, or CI unavailable):

```bash
cd frontend
pnpm install --frozen-lockfile && pnpm check
npm publish --access public          # needs `npm login` to an account in the bitapps org
```

## After the release: upgrade a consumer

**PHP.**
1. In the plugin, remove any `repositories.aikit` path entry.
2. Run `composer require bitapps/wp-ai-kit:^X.Y` (or `composer update bitapps/wp-ai-kit`).
3. Imposter or Strauss prefixes it on install.
4. Commit `composer.json` and `composer.lock`.

**UI.**
1. Replace the `link:` dependency with `pnpm add @bitapps/wp-ai-kit-ui@^X.Y.Z` in the
   plugin's frontend.
2. Drop the `link:`-only Vite settings (`server.fs.allow` for the kit path).
   `resolve.dedupe` can stay.

**Strings.** Regenerate the plugin's translation template. Bit Form:
`pnpm gettext-parser && pnpm i18n:php` merges `i18n/strings.en.json` from the package.

**Check.** Run the plugin's suites, open its AI screens once, and check the browser
console for a contract warning.

## Hotfix

1. Branch from the release tag.
2. Fix, test, and bump the patch version.
3. Tag `vX.Y.(Z+1)` and merge back to `main`.
4. Never move or delete a published tag. Packagist and npm both cache it.
