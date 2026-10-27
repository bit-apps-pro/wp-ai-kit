# Contributing to @bitapps/wp-ai-kit-ui

The package has three layers; keep code in the right one.

- `src/core/` — no DOM, no CSS: types (the wire contract with the PHP kit), the transport,
  the store (`useSyncExternalStore`, no state library), the provider, the hooks, the slot *types*.
- `src/components/` — React components built on the hooks. They render primitives only through
  the slot map (`const { Button, Switch, … } = useSlots()` from `./slots/useSlots`), never raw
  `<button>`/`<select>` (plain `<div>`, `<ul>`, `<input type="search">` inside a composed layout are fine).
- `src/antd/` — the optional antd slot map; the only file allowed to import antd.

Rules:
- Strings: `const { __, sprintf } = useI18n()`; wrap every user-visible string in `__()`. English only.
- CSS: classes `aikit-<component>__<part>` (modifiers `--x`), every rule `.aikit-…:where(.aikit, .aikit *)`,
  colours/sizes only through `--aikit-*` tokens (`src/styles/vars.css`). One CSS file per area
  (`chat.css`, `history.css`, `settings.css`, `mcp.css`), imported by `src/styles/index.css`.
  No global selectors, no resets, no `!important`, no `@font-face`.
- The package never touches `document.body`, never reads the URL, never registers global shortcuts;
  containers (dock, drawer, floating panel) are the host's. `Panel`/`FloatingLauncher` are thin helpers.
- Nothing plugin-specific: no "form", "entry", "Bit Form" wording in generic components; server
  name/slug/endpoint are props (`server={{ name, slug, endpoint }}`).
- Props a host needs to extend behaviour come as render props / providers (`renderPreviewBar`,
  `renderMessageFooter`, `mentions`, `commands`, `context`, `onEvent`), not as flags for one plugin.
- Tests: vitest + @testing-library/react with a mock `Transport` (see `tests/mockTransport.ts`).
- Check before handing over: `pnpm typecheck`, `pnpm test`, `pnpm build`, `pnpm size`.
