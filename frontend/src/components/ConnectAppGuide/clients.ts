import type { ComponentType } from 'react'
import {
  ChatGptIcon, ClaudeCodeIcon, ClaudeDesktopIcon, ClaudeIcon, CodexIcon, CursorIcon, GeminiCliIcon, GeminiIcon, GrokIcon,
  McpIcon, VsCodeIcon, WindsurfIcon,
} from '../icons/ai-clients'
import type { SvgIconProps } from '../icons/ai-clients'

/** The server an app connects to, as the host plugin names it. */
export interface ServerInfo {
  /** Shown to the user and sent to the apps as the connector's name. */
  name: string
  /** The key the apps file the server under (`mcpServers.<slug>`); letters, digits, dashes. */
  slug: string
  /** The Streamable HTTP endpoint. */
  endpoint: string
}

/** One way an app adds this site: a one-click link when the app has one, then the manual steps. */
export interface ClientFlow {
  steps: string[]
  snippet?: (server: ServerInfo) => string
  /** Opens the app with this site filled in (or its add-connector form). */
  deepLink?: (server: ServerInfo) => string
  deepLinkLabel?: string
  /** What happens after the click, under the button. */
  deepLinkHint?: string
}

export interface ClientDefinition {
  id: string
  name: string
  /** The app's logo (inline SVG). */
  Icon: ComponentType<SvgIconProps>
  effort: string
  /** The effort with an Application Password, when it differs (no one-click link). */
  basicEffort?: string
  /** The app cannot send a fixed header, so it needs OAuth. */
  oauthOnly?: boolean
  oauth: ClientFlow
  basic?: ClientFlow
}

const basicHeader = 'Authorization: Basic <base64 of USERNAME:APPLICATION-PASSWORD>'

const remoteProxyConfig = (server: ServerInfo): string => JSON.stringify({
  mcpServers: {
    [server.slug]: {
      command: 'npx',
      args: ['-y', '@automattic/mcp-wordpress-remote@latest'],
      env: {
        WP_API_URL: server.endpoint,
        WP_API_USERNAME: 'your-wordpress-username',
        WP_API_PASSWORD: 'your-application-password',
      },
    },
  },
}, null, 2)

/** Claude's add-connector dialog with the name and URL filled in; connectors follow the Claude account to Desktop and Cowork. */
const claudeConnectorLink = (server: ServerInfo): string => 'https://claude.ai/customize/connectors?modal=add-custom-connector'
  + `&connectorName=${encodeURIComponent(server.name)}&connectorUrl=${encodeURIComponent(server.endpoint)}`

const base64 = (value: string): string => window.btoa(String.fromCharCode(...new TextEncoder().encode(value)))

/**
 * How to add this site to each AI app. `oauth` is used when OAuth sign-in is on
 * (the app opens the site's consent screen); `basic` with an Application
 * Password. Strings go through the host's `__` so the list can be translated.
 */
export function clients(__: (text: string) => string): ClientDefinition[] {
  return [
    {
      id: 'claude-ai',
      Icon: ClaudeIcon,
      name: 'Claude.ai',
      effort: __('One click'),
      oauthOnly: true,
      oauth: {
        deepLink: claudeConnectorLink,
        deepLinkLabel: __('Connect to Claude'),
        deepLinkHint: __('Claude opens with the name and URL filled in. Click Add, then approve the connection on this site.'),
        steps: [
          __('In Claude (web or mobile), open Settings → Connectors and click “Add custom connector”.'),
          __('Give it the server name, paste the server URL below and click Add.'),
          __('Click Connect and approve the connection on this site. No token is needed.'),
        ],
      },
    },
    {
      id: 'claude-desktop',
      Icon: ClaudeDesktopIcon,
      name: 'Claude Desktop',
      effort: __('One click'),
      basicEffort: __('Paste a config'),
      oauth: {
        deepLink: claudeConnectorLink,
        deepLinkLabel: __('Connect to Claude'),
        deepLinkHint: __('Connectors belong to your Claude account, so one added on claude.ai also appears in Claude Desktop and Cowork.'),
        steps: [
          __('In Claude Desktop, open Settings → Connectors, click Add and choose “Add custom connector”.'),
          __('Give it the server name, paste the server URL below and leave the OAuth client fields empty.'),
          __('Click Connect next to the server and approve the connection on this site.'),
        ],
      },
      basic: {
        steps: [__('Open Settings → Developer → Edit Config and add this server to claude_desktop_config.json (needs Node.js):')],
        snippet: remoteProxyConfig,
      },
    },
    {
      id: 'chatgpt',
      Icon: ChatGptIcon,
      name: 'ChatGPT',
      effort: __('Opens the form'),
      oauthOnly: true,
      oauth: {
        deepLink: () => 'https://chatgpt.com/plugins#settings/Connectors?create-connector=true',
        deepLinkLabel: __('Create in ChatGPT'),
        deepLinkHint: __('ChatGPT opens its new-connector form. The URL is not filled in, so keep this page open to copy it.'),
        steps: [
          __('Give it the server name and paste the server URL below as the Server URL.'),
          __('Choose OAuth as the authentication method, accept the notice and click Create.'),
          __('Approve the connection on this site when ChatGPT opens it.'),
          __('No form? Turn on Developer mode first under Settings → Security and login.'),
        ],
      },
    },
    {
      id: 'grok',
      Icon: GrokIcon,
      name: 'Grok',
      effort: __('Opens the form'),
      oauthOnly: true,
      oauth: {
        deepLink: () => 'https://grok.com/connectors',
        deepLinkLabel: __('Open Grok Connectors'),
        steps: [
          __('Click New Connector, then Custom.'),
          __('Give it the server name and paste the server URL below.'),
          __('Click Add Connector and approve the connection on this site.'),
        ],
      },
    },
    {
      id: 'gemini',
      Icon: GeminiIcon,
      name: 'Gemini',
      effort: __('Paste a URL'),
      oauthOnly: true,
      oauth: {
        deepLink: () => 'https://gemini.google.com/',
        deepLinkLabel: __('Open Gemini'),
        deepLinkHint: __('Gemini has no direct link to its custom-app form, so keep this page open to copy the URL.'),
        steps: [
          __('In Gemini on the web, open Settings → Connected Apps (under Personal Intelligence if you do not see it).'),
          __('Under Custom apps, click “Add a custom app”, paste the server URL below and click Next.'),
          __('Approve the connection on this site. The server then works in the Gemini mobile app too.'),
          __('Custom apps need a personal Google account with Keep Activity on, and are not in every country yet.'),
        ],
      },
    },
    {
      id: 'gemini-cli',
      Icon: GeminiCliIcon,
      name: 'Gemini CLI',
      effort: __('Paste a command'),
      oauth: {
        steps: [__('Run this in a terminal, then run “/mcp auth <server>” inside Gemini CLI. A browser opens the approval page on this site:')],
        snippet: server => `gemini mcp add --scope user --transport http ${server.slug} ${server.endpoint}`,
      },
      basic: {
        steps: [__('Run this in a terminal, replacing the user name and Application Password:')],
        snippet: server => `gemini mcp add --scope user --transport http ${server.slug} ${server.endpoint} \\\n  --header "Authorization: Basic $(printf 'USERNAME:APPLICATION-PASSWORD' | base64)"`,
      },
    },
    {
      id: 'cursor',
      Icon: CursorIcon,
      name: 'Cursor',
      effort: __('One click'),
      basicEffort: __('Paste a config'),
      oauth: {
        deepLink: server => `cursor://anysphere.cursor-deeplink/mcp/install?name=${encodeURIComponent(server.slug)}&config=${encodeURIComponent(base64(JSON.stringify({ url: server.endpoint })))}`,
        deepLinkLabel: __('Add to Cursor'),
        deepLinkHint: __('Click Install in the dialog Cursor opens, then approve the connection on this site.'),
        steps: [__('Or add this to ~/.cursor/mcp.json, then click “Needs login” next to the server in Cursor:')],
        snippet: server => JSON.stringify({ mcpServers: { [server.slug]: { url: server.endpoint } } }, null, 2),
      },
      basic: {
        steps: [__('Add this to ~/.cursor/mcp.json (or the project’s .cursor/mcp.json):')],
        snippet: server => JSON.stringify({ mcpServers: { [server.slug]: { url: server.endpoint, headers: { Authorization: 'Basic <base64 of USERNAME:APPLICATION-PASSWORD>' } } } }, null, 2),
      },
    },
    {
      id: 'vscode',
      Icon: VsCodeIcon,
      name: 'GitHub Copilot (VS Code)',
      effort: __('One click'),
      basicEffort: __('Paste a header'),
      oauth: {
        deepLink: server => `vscode:mcp/install?${encodeURIComponent(JSON.stringify({ name: server.slug, type: 'http', url: server.endpoint }))}`,
        deepLinkLabel: __('Add to VS Code'),
        deepLinkHint: __('Click Install in VS Code, then approve the connection on this site.'),
        steps: [__('Or run “MCP: Add Server” from the command palette, choose HTTP and paste the server URL below.')],
      },
      basic: {
        steps: [__('Run “MCP: Add Server” from the command palette, choose HTTP, paste the server URL, and add this header:')],
        snippet: () => basicHeader,
      },
    },
    {
      id: 'windsurf',
      Icon: WindsurfIcon,
      name: 'Windsurf',
      effort: __('Paste a config'),
      oauth: {
        steps: [
          __('Merge this into ~/.codeium/windsurf/mcp_config.json:'),
          __('Refresh the MCP servers in Windsurf and approve the connection on this site when your browser opens.'),
        ],
        snippet: server => JSON.stringify({ mcpServers: { [server.slug]: { serverUrl: server.endpoint } } }, null, 2),
      },
      basic: {
        steps: [__('Merge this into ~/.codeium/windsurf/mcp_config.json, replacing the header value, then refresh the MCP servers in Windsurf:')],
        snippet: server => JSON.stringify({ mcpServers: { [server.slug]: { serverUrl: server.endpoint, headers: { Authorization: 'Basic <base64 of USERNAME:APPLICATION-PASSWORD>' } } } }, null, 2),
      },
    },
    {
      id: 'claude-code',
      Icon: ClaudeCodeIcon,
      name: 'Claude Code',
      effort: __('Paste a command'),
      oauth: {
        steps: [__('Run this in a terminal to add the server, then sign in with “claude mcp login <server>” (or /mcp inside Claude Code). A browser opens the approval page on this site:')],
        snippet: server => `claude mcp add --transport http ${server.slug} ${server.endpoint}`,
      },
      basic: {
        steps: [__('Run this in a terminal, replacing the user name and Application Password:')],
        snippet: server => `claude mcp add --transport http ${server.slug} ${server.endpoint} \\\n  --header "Authorization: Basic $(printf 'USERNAME:APPLICATION-PASSWORD' | base64)"`,
      },
    },
    {
      id: 'codex',
      Icon: CodexIcon,
      name: 'OpenAI Codex CLI',
      effort: __('Paste a command'),
      oauth: {
        steps: [__('Run this in a terminal. Codex opens the approval page on this site; if it does not, run “codex mcp login <server>”:')],
        snippet: server => `codex mcp add ${server.slug} --url ${server.endpoint}`,
      },
      basic: {
        steps: [__('Add this to ~/.codex/config.toml with your user name and Application Password:')],
        snippet: server => `[mcp_servers.${server.slug.replace(/-/g, '_')}]\nurl = "${server.endpoint}"\nhttp_headers = { Authorization = "Basic <base64 of USERNAME:APPLICATION-PASSWORD>" }`,
      },
    },
    {
      id: 'other',
      Icon: McpIcon,
      name: __('Other MCP app'),
      effort: __('Paste a URL'),
      oauth: {
        steps: [
          __('Add a remote MCP server (Streamable HTTP) with the server URL below. Choose OAuth if it asks how to sign in, then approve the connection on this site.'),
          __('Apps that only run local (stdio) servers can use “npx mcp-remote <server URL>” as the command instead.'),
        ],
        snippet: server => JSON.stringify({ mcpServers: { [server.slug]: { url: server.endpoint } } }, null, 2),
      },
      basic: {
        steps: [__('Add a remote MCP server (Streamable HTTP) with the server URL below and this header:')],
        snippet: () => basicHeader,
      },
    },
  ]
}

/** Find a client by id in a list (the defaults, or the defaults merged with a host's). */
export function clientById(list: ClientDefinition[], id: string | null | undefined): ClientDefinition | undefined {
  return id ? list.find(client => client.id === id) : undefined
}

/** A host's extra or replacement clients merged onto the defaults by id, keeping the default order. */
export function mergeClients(defaults: ClientDefinition[], extra: ClientDefinition[] = []): ClientDefinition[] {
  const merged = defaults.map(client => extra.find(item => item.id === client.id) || client)
  return [...merged, ...extra.filter(item => !defaults.some(client => client.id === item.id))]
}
