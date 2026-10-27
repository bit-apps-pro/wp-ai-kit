import type { ComponentType, KeyboardEvent, ReactNode } from 'react'

/**
 * The primitives the composed components render through. A host maps them to
 * its own design system (antd through `@bitapps/wp-ai-kit-ui/antd`, Bit Form to
 * its `Btn`, `SingleToggle2`, …); anything not mapped falls back to the plain
 * HTML defaults in `components/slots`.
 */
export type IconName =
  | 'send' | 'attach' | 'history' | 'plus' | 'close' | 'trash' | 'edit' | 'pin' | 'archive' | 'unarchive'
  | 'copy' | 'check' | 'warning' | 'info' | 'chevron-down' | 'chevron-right' | 'back' | 'spark' | 'stop'
  | 'search' | 'filter' | 'external' | 'refresh' | 'key' | 'link' | 'more'

export interface ButtonProps {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  size?: 'sm' | 'md'
  loading?: boolean
  disabled?: boolean
  icon?: ReactNode
  type?: 'button' | 'submit'
  title?: string
  className?: string
  'aria-label'?: string
  'aria-pressed'?: boolean
  onClick?: () => void
  children?: ReactNode
}

export interface TextInputProps {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  disabled?: boolean
  autoFocus?: boolean
  type?: 'text' | 'search' | 'password' | 'number' | 'email'
  id?: string
  list?: string
  min?: number
  max?: number
  className?: string
  'aria-label'?: string
  onKeyDown?: (event: KeyboardEvent<HTMLInputElement>) => void
  onBlur?: () => void
}

export interface SelectProps {
  value: string
  onChange: (value: string) => void
  options: { value: string, label: string, disabled?: boolean }[]
  disabled?: boolean
  id?: string
  className?: string
  'aria-label'?: string
}

export interface SwitchProps {
  checked: boolean
  onChange: (checked: boolean) => void
  disabled?: boolean
  id?: string
  'aria-label'?: string
}

export interface CheckboxProps {
  checked: boolean
  onChange: (checked: boolean) => void
  disabled?: boolean
  label?: ReactNode
  id?: string
}

export interface SpinnerProps {
  size?: 'sm' | 'md'
  className?: string
}

export interface ModalProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  footer?: ReactNode
  width?: number
  children?: ReactNode
}

export interface TabsProps {
  items: { key: string, label: ReactNode, children?: ReactNode }[]
  activeKey: string
  onChange: (key: string) => void
  /** Only the tab bar: the host renders the content itself. */
  barOnly?: boolean
  variant?: 'line' | 'segment'
}

export interface BadgeProps {
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'accent'
  title?: string
  children?: ReactNode
}

export interface CopyFieldProps {
  value: string
  label?: string
  masked?: boolean
  className?: string
}

export interface TooltipProps {
  title: ReactNode
  children: ReactNode
}

export interface MenuItem {
  key: string
  label: ReactNode
  icon?: ReactNode
  danger?: boolean
  disabled?: boolean
  onClick: () => void
}

export interface MenuProps {
  trigger: ReactNode
  items: MenuItem[]
  'aria-label'?: string
}

export interface IconProps {
  name: IconName
  size?: number
  className?: string
}

export interface SlotComponents {
  Button: ComponentType<ButtonProps>
  TextInput: ComponentType<TextInputProps>
  Select: ComponentType<SelectProps>
  Switch: ComponentType<SwitchProps>
  Checkbox: ComponentType<CheckboxProps>
  Spinner: ComponentType<SpinnerProps>
  Modal: ComponentType<ModalProps>
  Tabs: ComponentType<TabsProps>
  Badge: ComponentType<BadgeProps>
  CopyField: ComponentType<CopyFieldProps>
  Tooltip: ComponentType<TooltipProps>
  Menu: ComponentType<MenuProps>
  Icon: ComponentType<IconProps>
}

/** A host maps what it wants; the rest keeps the defaults. */
export type PartialSlotComponents = Partial<SlotComponents>
