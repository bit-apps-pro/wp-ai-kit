import type { CSSProperties } from 'react'
import {
  Button as AntButton, Checkbox as AntCheckbox, Dropdown, Input, Modal as AntModal, Segmented, Select as AntSelect,
  Spin, Switch as AntSwitch, Tabs as AntTabs, Tag, Tooltip as AntTooltip, Typography, theme,
} from 'antd'
import Icon from '../components/icons/Icon'
import type {
  BadgeProps, ButtonProps, CheckboxProps, CopyFieldProps, MenuProps, ModalProps, SelectProps, SlotComponents,
  SpinnerProps, SwitchProps, TabsProps, TextInputProps, TooltipProps,
} from '../core/slots'

/**
 * The slot map for an antd host: `<AiKitProvider components={antdComponents}>`.
 * Only this entry imports antd; it stays an optional peer. The icons stay the
 * package's own so the two sets never mix.
 */

const BUTTON_TYPE: Record<NonNullable<ButtonProps['variant']>, 'primary' | 'default' | 'text'> = {
  primary: 'primary',
  secondary: 'default',
  ghost: 'text',
  danger: 'default',
}

function Button({ variant = 'secondary', size = 'md', loading, disabled, icon, type = 'button', title, className, onClick, children, ...aria }: ButtonProps) {
  return (
    <AntButton
      type={BUTTON_TYPE[variant]}
      danger={variant === 'danger'}
      size={size === 'sm' ? 'small' : 'middle'}
      loading={loading}
      disabled={disabled}
      icon={icon}
      htmlType={type}
      title={title}
      className={className}
      aria-label={aria['aria-label']}
      aria-pressed={aria['aria-pressed']}
      onClick={onClick}
    >
      {children}
    </AntButton>
  )
}

function TextInput({ value, onChange, type = 'text', onKeyDown, onBlur, className, ...rest }: TextInputProps) {
  return (
    <Input
      {...rest}
      type={type}
      allowClear={type === 'search'}
      className={className}
      value={value}
      onChange={e => onChange(e.target.value)}
      onKeyDown={onKeyDown}
      onBlur={onBlur}
    />
  )
}

function Select({ value, onChange, options, disabled, id, className, ...aria }: SelectProps) {
  return <AntSelect id={id} className={className} value={value} options={options} disabled={disabled} aria-label={aria['aria-label']} onChange={onChange} popupMatchSelectWidth={false} />
}

function Switch({ checked, onChange, disabled, id, ...aria }: SwitchProps) {
  return <AntSwitch id={id} checked={checked} disabled={disabled} aria-label={aria['aria-label']} onChange={onChange} />
}

function Checkbox({ checked, onChange, disabled, label, id }: CheckboxProps) {
  return <AntCheckbox id={id} checked={checked} disabled={disabled} onChange={e => onChange(e.target.checked)}>{label}</AntCheckbox>
}

function Spinner({ size = 'sm', className }: SpinnerProps) {
  return <Spin size={size === 'md' ? 'default' : 'small'} className={className} />
}

function Modal({ open, onClose, title, footer, width = 480, children }: ModalProps) {
  return (
    <AntModal open={open} onCancel={onClose} title={title} footer={footer ?? null} width={width} destroyOnClose>
      {children}
    </AntModal>
  )
}

function Tabs({ items, activeKey, onChange, barOnly, variant = 'line' }: TabsProps) {
  if (variant === 'segment') {
    const active = items.find(item => item.key === activeKey)
    return (
      <div>
        <Segmented options={items.map(item => ({ label: item.label, value: item.key }))} value={activeKey} onChange={key => onChange(String(key))} />
        {!barOnly && active?.children !== undefined && <div>{active.children}</div>}
      </div>
    )
  }
  return <AntTabs items={items.map(item => ({ key: item.key, label: item.label, children: barOnly ? undefined : item.children }))} activeKey={activeKey} onChange={onChange} />
}

const TAG_COLOR: Record<NonNullable<BadgeProps['tone']>, string> = {
  neutral: 'default',
  success: 'success',
  warning: 'warning',
  danger: 'error',
  info: 'processing',
  accent: 'blue',
}

function Badge({ tone = 'neutral', title, children }: BadgeProps) {
  return <Tag color={TAG_COLOR[tone]} title={title} style={{ marginInlineEnd: 0 }}>{children}</Tag>
}

function CopyField({ value, label, masked, className }: CopyFieldProps) {
  return (
    <Input
      readOnly
      className={className}
      aria-label={label}
      value={masked ? '•'.repeat(Math.min(24, value.length)) : value}
      suffix={<Typography.Text copyable={{ text: value }} />}
    />
  )
}

function Tooltip({ title, children }: TooltipProps) {
  return <AntTooltip title={title}>{children}</AntTooltip>
}

function Menu({ trigger, items, ...aria }: MenuProps) {
  return (
    <Dropdown
      trigger={['click']}
      menu={{
        items: items.map(item => ({ key: item.key, label: item.label, icon: item.icon, danger: item.danger, disabled: item.disabled, onClick: () => item.onClick() })),
      }}
    >
      <span role="button" tabIndex={0} aria-haspopup="menu" aria-label={aria['aria-label']} style={{ display: 'inline-flex' }}>{trigger}</span>
    </Dropdown>
  )
}

export const antdComponents: SlotComponents = {
  Button,
  TextInput,
  Select,
  Switch,
  Checkbox,
  Spinner,
  Modal,
  Tabs,
  Badge,
  CopyField,
  Tooltip,
  Menu,
  Icon,
}

/**
 * The `--aikit-*` tokens from the surrounding antd theme, to spread on the
 * element that carries `.aikit`: `<div className="aikit" style={useAntdTheme()}>`.
 */
export function useAntdTheme(): CSSProperties {
  const { token } = theme.useToken()
  return {
    '--aikit-accent': token.colorPrimary,
    '--aikit-accent-hover': token.colorPrimaryHover,
    '--aikit-accent-fg': token.colorTextLightSolid,
    '--aikit-accent-soft': token.colorPrimaryBg,
    '--aikit-bg': token.colorBgContainer,
    '--aikit-bg-soft': token.colorFillAlter,
    '--aikit-bg-muted': token.colorFillSecondary,
    '--aikit-fg': token.colorText,
    '--aikit-fg-muted': token.colorTextSecondary,
    '--aikit-fg-faint': token.colorTextTertiary,
    '--aikit-border': token.colorBorderSecondary,
    '--aikit-border-strong': token.colorBorder,
    '--aikit-radius': `${token.borderRadius}px`,
    '--aikit-radius-sm': `${token.borderRadiusSM}px`,
    '--aikit-font': token.fontFamily,
    '--aikit-font-size': `${token.fontSize}px`,
    '--aikit-danger': token.colorError,
    '--aikit-danger-soft': token.colorErrorBg,
    '--aikit-success': token.colorSuccess,
    '--aikit-success-soft': token.colorSuccessBg,
    '--aikit-warning': token.colorWarning,
    '--aikit-warning-soft': token.colorWarningBg,
    '--aikit-info': token.colorInfo,
    '--aikit-info-soft': token.colorInfoBg,
  } as CSSProperties
}
