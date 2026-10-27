import type { SvgIconProps } from './types'

/** Windsurf: three swept sail strokes on a teal tile. */
export default function WindsurfIcon({ className, size = '1em' }: SvgIconProps) {
  return (
    <svg aria-hidden className={className} fill="none" height={size} viewBox="0 0 24 24" width={size} focusable="false">
      <rect fill="#0B6E6E" height="24" rx="5.4" width="24" />
      <path d="M5 6.5c3.6-.6 6.6 1.2 8.5 4.6M6.5 11.5c3.3-.4 5.9 1.1 7.6 4.1M8 16.3c2.6-.3 4.6.9 6 3.2" stroke="#fff" strokeLinecap="round" strokeWidth="1.9" />
    </svg>
  )
}
