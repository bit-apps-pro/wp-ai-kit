import GeminiSparkle from './gemini-sparkle'
import type { SvgIconProps } from './types'

export default function GeminiIcon({ className, size = '1em' }: SvgIconProps) {
  return (
    <svg aria-hidden className={className} fill="none" height={size} viewBox="0 0 24 24" width={size}>
      <GeminiSparkle />
    </svg>
  )
}
