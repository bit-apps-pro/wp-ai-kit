import { Fragment, type ReactNode } from 'react'
import { useI18n } from '../core/context'

export interface MarkdownProps {
  text: string
  /**
   * Which links may be rendered as links: given a URL, return the href to use or
   * null to show plain text. Default: same-origin `/wp-admin/` links only, so
   * model output can never send the user elsewhere.
   */
  linkPolicy?: (url: string) => string | null
  /** The text of a bare URL that passes the policy (default "Open"). */
  linkLabel?: string
}

export function defaultLinkPolicy(raw: string): string | null {
  try {
    const url = new URL(raw, window.location.origin)
    return url.origin === window.location.origin && url.pathname.includes('/wp-admin/') ? url.toString() : null
  } catch {
    return null
  }
}

const BULLET = /^\s*[-*•]\s+/
const NUMBERED = /^\s*\d+[.)]\s+/

/**
 * Minimal, safe formatting for model text: paragraphs, bullet and numbered
 * lists, **bold**, `code` and links the host allows. Builds React elements
 * only (no HTML injection, no images), so model output cannot load anything or
 * run script.
 */
export default function Markdown({ text, linkPolicy = defaultLinkPolicy, linkLabel }: MarkdownProps) {
  const { __ } = useI18n()
  const label = linkLabel || __('Open')

  const inline = (line: string, key: string): ReactNode[] => line.split(/(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\([^)\s]+\)|https?:\/\/[^\s<>()]+)/g).map((part, i) => {
    const k = `${key}-${i}`
    if (part.startsWith('**') && part.endsWith('**') && part.length > 4) return <strong key={k}>{part.slice(2, -2)}</strong>
    if (part.startsWith('`') && part.endsWith('`') && part.length > 2) return <code key={k}>{part.slice(1, -1)}</code>
    const markdown = part.match(/^\[([^\]]+)\]\(([^)\s]+)\)$/)
    if (markdown) {
      const href = linkPolicy(markdown[2])
      return href ? <a key={k} href={href}>{markdown[1]}</a> : <Fragment key={k}>{markdown[1]}</Fragment>
    }
    if (/^https?:\/\//.test(part)) {
      const [, url, trail] = part.match(/^(.*?)([.,;:!?]*)$/) || [part, part, '']
      const href = linkPolicy(url)
      return href ? <Fragment key={k}><a href={href}>{label}</a>{trail}</Fragment> : <Fragment key={k}>{part}</Fragment>
    }
    return <Fragment key={k}>{part}</Fragment>
  })

  const kindOf = (line: string) => (BULLET.test(line) ? 'ul' : NUMBERED.test(line) ? 'ol' : 'p')

  // Each paragraph splits into runs of plain lines and list items ("Included:" then "- Name").
  return (
    <div className="aikit aikit-markdown">
      {String(text || '').split(/\n{2,}/).flatMap((block, b) => {
        const runs: { kind: string, lines: string[] }[] = []
        block.split('\n').forEach((line) => {
          const kind = kindOf(line)
          const last = runs[runs.length - 1]
          if (last && last.kind === kind) last.lines.push(line)
          else runs.push({ kind, lines: [line] })
        })
        return runs.map((run, r) => {
          const key = `${b}-${r}`
          if (run.kind === 'ul') return <ul key={key}>{run.lines.map((l, i) => <li key={i}>{inline(l.replace(BULLET, ''), `${key}-${i}`)}</li>)}</ul>
          if (run.kind === 'ol') return <ol key={key}>{run.lines.map((l, i) => <li key={i}>{inline(l.replace(NUMBERED, ''), `${key}-${i}`)}</li>)}</ol>
          return <p key={key}>{run.lines.map((l, i) => <Fragment key={i}>{i > 0 && <br />}{inline(l, `${key}-${i}`)}</Fragment>)}</p>
        })
      })}
    </div>
  )
}
