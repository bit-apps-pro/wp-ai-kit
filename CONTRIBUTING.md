# Contributing

## The one thing to know: this code gets prefixed

Every consuming plugin rewrites the kit into its own namespace
(`BitApps\BitForm\Deps\BitApps\AiKit\…`, `BitApps\PiPro\Deps\…`, …) with
[Imposter](https://github.com/typisttech/imposter) or
[Strauss](https://github.com/BrianHenryIE/strauss). Code that is correct PHP can
still break after prefixing. `composer prefix-check` enforces these rules; CI runs it.

1. **External, non-prefixed namespaces only as inline FQCNs.** Imposter's
   `prefixUse()` rewrites *every* multi-segment `use X\Y;` line, so
   `use WP\MCP\Core\McpAdapter;` becomes `use <Prefix>\WP\MCP\Core\McpAdapter;`
   and fatals. Write `\WP\MCP\Core\McpAdapter::instance()`, `\WP_Ability`,
   `\WP_Error` inline. `use` lines may only import `BitApps\AiKit\…` (and
   `PHPUnit\…` in tests).
2. **No closure `use ($var)` on a source line that also contains a backslash.**
   Same regex. Put the `use (…)` on its own line or avoid the backslash.
3. **No class names in string literals.** `'BitApps\\AiKit\\Foo'` stays unprefixed
   under Imposter and is rewritten under Strauss — either way it is wrong
   somewhere. Use `Foo::class`.
4. **No `files` autoload, no namespaced functions or constants.** The consumers'
   autoload merging only maps classes.
5. **No static cross-plugin state.** Three plugins may each run a copy of this
   kit on one site. Options, transients, REST namespaces, token prefixes and
   table names all come from `PluginContext`; hook names from `Contract`.
6. **No WordPress version checks.** Probe for capabilities
   (`function_exists('wp_register_ability')`, `class_exists('\WP\MCP\Core\McpAdapter')`,
   `wp_supports_ai()`), and only inside `Settings\RequirementsReport` and the
   bridge constructors.
7. **No parameter type hints on hook callbacks.** A `null` passed through
   `apply_filters` against a typed parameter is a production `TypeError`.
   Normalise inside the callback.
8. **PHP 8.0 syntax only** (no enums, readonly properties, `new` in initialisers,
   first-class callable syntax). `composer compat` checks `8.0-`.
9. **WordPress functions directly.** The plugins' rule "use wp-kit `JSON` /
   `Capabilities` wrappers" does not apply here: the kit has no wp-kit.
   Call `wp_json_encode()`, `current_user_can()`, `wp_remote_request()` etc.
10. **Never read a plugin's `Config`, option names or capabilities.** Ask the
    `PluginContext`.

## Workflow

- Branch from `main`, keep PRs focused, add or update tests in `tests/`.
- `composer check` must pass (lint, PHP 8.0 compatibility, prefix-safety, tests).
- SemVer: during `0.x` a minor may break; from `1.0` only additive changes within
  a major. Note breaking changes in `UPGRADING.md`, everything in `CHANGELOG.md`.
- Cross-plugin names (`Contract.php`) change only with `CONTRACT_VERSION`.

## Local development against a plugin

```bash
cd ../bitform                      # or any consumer
composer config repositories.aikit path ../_wp-ai-kit
composer config repositories.aikit.options --json '{"symlink": false}'
composer require bitapps/wp-ai-kit:@dev
```

`symlink: false` is mandatory: Imposter rewrites files in place through their real
path and would rewrite this repository; bitformpro's production build deletes
through symlinks. Re-run `composer update bitapps/wp-ai-kit` after changing the
kit. Before committing a plugin's `composer.json`/`composer.lock`, remove the
repository entry: `composer config --unset repositories.aikit`.
