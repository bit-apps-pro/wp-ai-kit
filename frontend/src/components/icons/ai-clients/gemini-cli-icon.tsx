import GeminiSparkle from './gemini-sparkle'
import type { SvgIconProps } from './types'

/** The Gemini sparkle on a terminal-dark tile, to tell the CLI from the web app. */
export default function GeminiCliIcon({ className, size = '1em' }: SvgIconProps) {
  return (
    <svg aria-hidden className={className} fill="none" height={size} viewBox="0 0 24 24" width={size}>
      <rect fill="#1E1F20" height="24" rx="5.4" width="24" />
      <GeminiSparkle inset={5} />
    </svg>
  )
}
