import type { ReactNode } from 'react'
import { useI18n } from '../core/context'
import { useSlots } from './slots/useSlots'

export interface FloatingLauncherProps {
  onClick: () => void
  label?: string
  icon?: ReactNode
  /** Something arrived while the panel was closed. */
  unread?: boolean
  position?: 'bottom-right' | 'bottom-left'
  className?: string
}

/** The pill that opens the assistant, fixed to a bottom corner of the screen. */
export default function FloatingLauncher({ onClick, label, icon, unread = false, position = 'bottom-right', className }: FloatingLauncherProps) {
  const { __ } = useI18n()
  const { Button, Icon } = useSlots()
  const text = label || __('Ask AI')

  return (
    <div className={['aikit', 'aikit-launcher', `aikit-launcher--${position}`, className || ''].join(' ').trim()}>
      <Button
        variant="primary"
        className="aikit-launcher__btn"
        icon={icon === undefined ? <Icon name="spark" size={18} /> : icon}
        aria-label={__('Open the AI assistant')}
        title={text}
        onClick={onClick}
      >
        {text}
        {unread && <span className="aikit-launcher__dot" aria-label={__('New reply')} role="status" />}
      </Button>
    </div>
  )
}
