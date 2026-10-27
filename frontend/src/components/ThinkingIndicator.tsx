import { useEffect, useState } from 'react'
import { useI18n } from '../core/context'

export interface ThinkingIndicatorProps {
  /** Shown for the first seconds instead of the first stage. */
  label?: string
  /** Replace the default stages: seconds since start → text. */
  stages?: [number, string][]
}

function clock(seconds: number): string {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

/**
 * The assistant is answering: bouncing dots, a status line that moves on as the
 * turn takes longer, and the elapsed time once it passes a few seconds, so a long
 * turn reads as work in progress rather than a stuck request.
 */
export default function ThinkingIndicator({ label, stages }: ThinkingIndicatorProps) {
  const { __ } = useI18n()
  const [seconds, setSeconds] = useState(0)

  useEffect(() => {
    const started = Date.now()
    const timer = window.setInterval(() => setSeconds(Math.floor((Date.now() - started) / 1000)), 1000)
    return () => window.clearInterval(timer)
  }, [])

  const steps = stages || [
    [0, __('Thinking')],
    [3, __('Looking things up')],
    [9, __('Working on it')],
    [20, __('Still working, bigger changes take a little longer')],
    [45, __('Almost there, the AI model is taking its time')],
  ]
  let text = steps[0][1]
  steps.forEach(([from, stage]) => { if (seconds >= from) text = stage })
  if (label && seconds < 3) text = label

  return (
    <div className="aikit aikit-thinking" role="status" aria-live="polite">
      <span className="aikit-thinking__dots" aria-hidden="true">
        <span /><span /><span />
      </span>
      <span className="aikit-thinking__text">{`${text}…`}</span>
      {seconds >= 5 && <span className="aikit-thinking__time">{clock(seconds)}</span>}
    </div>
  )
}
