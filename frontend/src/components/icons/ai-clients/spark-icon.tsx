import type { SvgIconProps } from './types'

/** AI assistant: a chat bubble saying "AI", with sparkles at its top right, drawn in the current text colour. */
export default function SparkIcon({ className, size = '1em' }: SvgIconProps) {
  return (
    <svg aria-hidden className={className} fill="none" height={size} viewBox="0 0 24 24" width={size} stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" focusable="false">
      <path d="M14.6 2.6H6.1a4.5 4.5 0 0 0-4.5 4.5v6.2a4.5 4.5 0 0 0 4.5 4.5v3.8l3.6-3.8h8.2a4.5 4.5 0 0 0 4.5-4.5v-2.6" />
      <path d="M5.4 13.6 7.9 6.8l2.5 6.8M6.3 11.3h3.2M13.3 6.8v6.8" />
      <path d="M18.7 2.4l.9 2.4 2.4.9-2.4.9-.9 2.4-.9-2.4-2.4-.9 2.4-.9z" />
      <path d="M22.2 0.9l.4 1 1 .4-1 .4-.4 1-.4-1-1-.4 1-.4z" fill="currentColor" strokeWidth={0.9} />
    </svg>
  )
}
