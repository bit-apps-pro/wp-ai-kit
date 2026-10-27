import { useId } from 'react'

/** Gemini's four-point sparkle in its brand gradient; `inset` shrinks it inside a 24×24 box. */
export default function GeminiSparkle({ inset = 0 }: { inset?: number }) {
  const id = `aikit-gemini-${useId().replace(/:/g, '')}`
  const scale = (24 - 2 * inset) / 24

  return (
    <>
      <defs>
        <linearGradient id={id} x1="0" x2="24" y1="24" y2="0" gradientUnits="userSpaceOnUse">
          <stop offset="0" stopColor="#4285F4" />
          <stop offset="0.55" stopColor="#9B72CB" />
          <stop offset="1" stopColor="#D96570" />
        </linearGradient>
      </defs>
      <path
        transform={inset ? `translate(${inset} ${inset}) scale(${scale})` : undefined}
        d="M11.04 19.32Q12 21.51 12 24q0-2.49.93-4.68.96-2.19 2.58-3.81t3.81-2.55Q21.51 12 24 12q-2.49 0-4.68-.93a12.3 12.3 0 0 1-3.81-2.58 12.3 12.3 0 0 1-2.58-3.81Q12 2.49 12 0q0 2.49-.96 4.68-.93 2.19-2.55 3.81a12.3 12.3 0 0 1-3.81 2.58Q2.49 12 0 12q2.49 0 4.68.96 2.19.93 3.81 2.55t2.55 3.81"
        fill={`url(#${id})`}
      />
    </>
  )
}
