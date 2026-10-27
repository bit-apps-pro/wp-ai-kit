# @bitapps/wp-ai-kit-ui

The React side of the Bit Apps WP AI Kit: a headless store and hooks, and themeable components for the
in-admin AI assistant (chat, conversation history) and the AI Assistant / MCP settings screens.
It talks to the kit's PHP through `Kit::uiBootstrap()` and the `<namespace>/ai/*` REST routes.

## Install

```bash
pnpm add @bitapps/wp-ai-kit-ui
```

Peer dependencies: `react` and `react-dom` (18.3+ or 19). `antd` 5 is an **optional** peer, needed only
when you import the antd adapter. None of them is bundled.

## Three entries

| Import | What it holds | Size budget (brotli) |
| --- | --- | --- |
| `@bitapps/wp-ai-kit-ui` (or `/core`) | Types, transport, store, `AiKitProvider`, hooks. No DOM, no CSS. | 12 kB |
| `@bitapps/wp-ai-kit-ui/components` | The components and their stylesheet (imported for you). | 45 kB |
| `@bitapps/wp-ai-kit-ui/antd` | `antdComponents` (the slot map for antd) and `useAntdTheme()`. | 4 kB |

`@bitapps/wp-ai-kit-ui/styles.css` is the same stylesheet, for a host that loads CSS on its own.
Each entry is tree-shaken on its own, so a host pays only for what it imports. A host that builds its own
UI on the hooks never loads the components.

Types resolve through `exports`, and through `typesVersions` for hosts whose `tsconfig` still uses Node 10
resolution (`"module": "commonjs"` without `moduleResolution`).

## Bootstrap and provider

The plugin localizes `Kit::uiBootstrap()` (slug, name, REST base, `wp_rest` nonce, capabilities, engine,
docs and settings URLs). Give it to one provider per plugin, so two plugins on one admin page never share
state:

```tsx
import { AiKitProvider } from '@bitapps/wp-ai-kit-ui'
import { AssistantChat, AssistantSettingsForm } from '@bitapps/wp-ai-kit-ui/components'

const bootstrap = window.myPluginAi // what Kit::uiBootstrap() returned

export function AskAiPanel() {
  return (
    <AiKitProvider bootstrap={bootstrap}>
      <AssistantChat title="AI Assistant" context={() => ({ screen: 'dashboard' })} />
    </AiKitProvider>
  )
}
```

By default, `AiKitProvider` builds the REST transport from `bootstrap.restBase` and `bootstrap.nonce` (WordPress
cookie auth, and the nonce refreshes from the `X-WP-Nonce` response header). A host with its own endpoints
(admin-ajax, say) passes `transport={{ request(action, payload) { … } }}`, answering the same action names
with the same payloads. `store` reuses one store across two panels of the same plugin.

### Components

- **Chat:** `AssistantChat`, `PromptComposer`, `PendingActionCard`, `ConversationHistory`, plus `Panel` and
  `FloatingLauncher`, two thin positioned containers. Docking into the admin layout is the host's job.
  The package never touches `document.body`, never reads the URL and never registers global shortcuts.
- **Settings:** `AssistantSettingsForm` (switch, AI engine, provider key, access level, privacy and limits,
  recent activity) and `SaveBar` (the unsaved-changes strip).
- **MCP:** `McpSettings` (tabs: overview, connections, tools, activity, settings), and its parts
  `ConnectAppGuide`, `ToolList`, `ActivityLog`, `DiagnosticsList`, `ApiKeysCard`, `ConnectionsList`.

### Hooks (headless)

`useAssistant`, `useConversations`, `useAiSettings`, `useMcpSettings`, `useMcpState`, `useActivity`,
`useDiagnostics` and `useAiKitEvents`. Build your own UI on these and skip the components entry. Code outside
React opens the chat through the window bus: `aiKitBus(slug).open({ message, autoSend, newChat })`.

## Slots: the primitives a host maps

Composed components render buttons, inputs, switches, selects, tabs, modals, badges, tooltips, menus and
icons only through a slot map. There are three levels:

1. **Defaults:** map nothing. Plain HTML primitives, styled by `aikit-*` classes and the `--aikit-*` tokens.
2. **antd adapter:**
   ```tsx
   import { ConfigProvider } from 'antd'
   import { antdComponents, useAntdTheme } from '@bitapps/wp-ai-kit-ui/antd'

   function Themed({ children }) {
     const vars = useAntdTheme() // inside ConfigProvider: reads theme.useToken()
     return <div className="my-app" style={vars}>{children}</div>
   }

   <ConfigProvider theme={…}>
     <AiKitProvider bootstrap={bootstrap} components={antdComponents}>…</AiKitProvider>
   </ConfigProvider>
   ```
   Button maps to antd `Button`, TextInput to `Input`, Select, Switch and Checkbox to their antd namesakes,
   Spinner to `Spin`, Modal to `Modal`, Tabs to `Tabs` (or `Segmented` for the segment variant), Badge to `Tag`,
   CopyField to a read-only `Input` with a copy action, Tooltip to `Tooltip`, and Menu to `Dropdown`. Icons stay
   the package's own.
3. **Custom:** pass any subset, `components={{ Button: MyButton, Switch: MyToggle }}`. Whatever you leave out
   keeps the defaults. The prop types (`ButtonProps`, `SwitchProps`, …, `SlotComponents`) are exported from the
   core entry.

## Theming with `--aikit-*`

Every rule is `.aikit-…:where(.aikit, .aikit *)` (one class of specificity), so host styles win without
`!important`.
Each exported component carries the `.aikit` class on its root, so one works on its own (an `ActivityLog` on
a host page); nested inside another, it inherits instead of resetting the font.
The package ships no resets, no global selectors and no fonts. Colours, radii and sizes come only from
tokens (`src/styles/vars.css`):

`--aikit-accent`, `--aikit-accent-hover`, `--aikit-accent-fg`, `--aikit-accent-soft`, `--aikit-bg`,
`--aikit-bg-soft`, `--aikit-bg-muted`, `--aikit-fg`, `--aikit-fg-muted`, `--aikit-fg-faint`,
`--aikit-border`, `--aikit-border-strong`, `--aikit-success|warning|danger|info` (each with a `-soft`),
`--aikit-radius`, `--aikit-radius-sm`, `--aikit-font`, `--aikit-font-size`, `--aikit-shadow`,
`--aikit-z` and `--aikit-control-height`.

The defaults are declared on the document root with no specificity, so your values win wherever you set
them — on your app root (inherited by every component, including portalled panels), on `.aikit`, or on one
component. Tokens built from others (`--aikit-focus`) are computed on each component, so they follow yours:

```css
#my-app { --aikit-accent: #3c4b6d; --aikit-radius: 10px; }
#my-app .aikit[data-theme="dark"] { --aikit-bg: #1f2428; --aikit-fg: #e6e9ec; /* … */ }
```

An antd host spreads `useAntdTheme()` on its root element's `style`.

The package ships light values only. Dark mode is the host's: set the dark tokens under the
`.aikit[data-theme="dark"]` hook. `SaveBar` sticks under a fixed host bar through `--aikit-sticky-top`
(WordPress admin: `var(--wp-admin--admin-bar--height, 32px)`), or its `stickyTop` prop.

The settings screens are `width: 100%` up to 1000 px. Give their container a width: inside a flex
row that means `flex: 1 1 0; min-width: 0`, or the screen shrinks to its content and changes width as
sections open and tabs switch.

## Host extensions

Behaviour a host adds comes as props and render props, never as flags for one plugin:

- **Chat:** `context` (ids the server resolves, or a getter read at send time), `mentions` and `commands`
  providers (for `@` and `/` in the composer), `suggestions`, `renderPreviewBar`, `renderMessageFooter`,
  `renderMessage`, `placeholder`, `onEvent` (`pending`, `approved`, `rejected`, `executed`, `failed`,
  `reply`, `conversation`, `open`), `onSend`, and `switchBlockedReason`. A `reply` event says which request
  it answers in `source` (`view` for the first load, `chat` or `decide` for a turn, `reset`, `open`,
  `forget`, `update`), so a host can act on turns only.
- **Settings:** `AssistantSettingsForm`'s `extraFields({ draft, update, state })` (host rows in the limits
  card), `onSaved`, `onViewAllActivity`, `connectorsUrl`, `show*Card`, `showIntro`, `description` (the
  intro's lead text) and `saveBar={false}` (when the host draws its own bar).
- **Help text:** `AssistantSettingsForm` and `McpSettings` take `help="tooltip"` (default: what each setting
  does sits behind an info button beside its label) or `help="inline"` (printed under the label). A short
  note stays visible in both modes where a setting changes privacy or security, or shows a state. Host rows
  use the same pieces: `SettingText` (label, `help`, `note`), `HelpTip` and `HelpText`, under
  `HelpModeProvider`.
- **MCP:** `McpSettings`'s `server={{ name, slug }}`, `tabs`, `tab`/`onTabChange` (route-driven tabs),
  `clients`, `showIntro` and `description`.

## Optional parts

`bootstrap.features` says what the plugin ships (PHP `PluginContext` `features`). The components hide what
is left out on their own: without `audit` there is no Activity tab, recent-activity card or retention row;
without `history` no history button or `/history` command; without `oauth` no OAuth card and no apps that
only sign in with OAuth; without `apiKeys` no API keys card; without `abilities` / `mcpAdapter` no rows for
them. Host code reads the same flags:

```tsx
import { featureOn, useFeature } from '@bitapps/wp-ai-kit-ui'

const auditOn = useFeature('audit')          // inside the provider
featureOn(bootstrap, 'history')              // anywhere; a kit too old to say counts as on
```

## Translation

The package holds English source strings only. Pass your translate function:

```tsx
import { __ } from '@wordpress/i18n'

<AiKitProvider bootstrap={bootstrap} i18n={{ __: s => __(s, 'my-text-domain') }}>
```

`sprintf` (`%s`, `%d`, positional `%1$s`) runs after translation. Every string the package can show is
listed in `i18n/strings.en.json` (shipped in the package): feed it to your POT pipeline, for example by
generating a PHP file of `__('…', 'my-text-domain')` calls that `wp i18n make-pot` picks up. Regenerate the
list with `pnpm i18n:extract`, and check it is current with `pnpm i18n:check`.

## REST contract

The wire types live in `src/core/types.ts` (contract `"1"`; `AiKitProvider` warns on a different major).
The action → route map is in `src/core/transport.ts`. The PHP side is the kit's `src/Rest/*` controllers and
`Kit::uiBootstrap()`. A refused chat turn answers HTTP 422 with the conversation view, which the store keeps
(the message goes back to the composer).

## Development

```bash
pnpm install
pnpm playground   # every layout (dock, floating, drawer, page, card), the settings and MCP screens,
                  # three themes and dark mode, on an in-memory mock site; plus a style-bleed page
pnpm typecheck && pnpm test && pnpm build && pnpm size   # or: pnpm check
```

Conventions are in `CONTRIBUTING.md`. Tests use vitest and Testing Library with the in-memory transport
in `tests/mockTransport.ts`.
