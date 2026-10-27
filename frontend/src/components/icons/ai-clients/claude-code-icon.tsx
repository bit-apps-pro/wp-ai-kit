import type { SvgIconProps } from './types'

export default function ClaudeCodeIcon({ className, size = '1em' }: SvgIconProps) {
  return (
    <svg aria-hidden className={className} fill="none" height={size} viewBox="0 0 14 14" width={size}>
      <path
        clipRule="evenodd"
        d="M12.2488 6.38724H14V8.19674H12.25V9.96307H11.3826V11.667H10.5V9.96307H9.63257V11.667H8.75V9.96307H5.25V11.667H4.368V9.96307H3.5V11.667H2.61743V9.96307H1.75V8.19614H0V6.38782H1.75V2.91699H12.2488V6.38724ZM3.5 6.38724H4.368V4.72649H3.5V6.38724ZM9.63083 6.38724H10.5V4.72649H9.63083V6.38724Z"
        fill="#D97757"
        fillRule="evenodd"
      />
    </svg>
  )
}
