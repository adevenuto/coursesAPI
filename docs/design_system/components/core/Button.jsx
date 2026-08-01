import React from 'react';

const sizes = {
  sm: { fontSize: 13, padding: '8px 16px', height: 36 },
  md: { fontSize: 15, padding: '11px 22px', height: 44 },
  lg: { fontSize: 16, padding: '14px 28px', height: 52 },
};

const base = {
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8,
  fontFamily: 'var(--font-body)', fontWeight: 600, lineHeight: 1,
  borderRadius: 'var(--radius-pill)', border: '1px solid transparent',
  cursor: 'pointer', whiteSpace: 'nowrap', textDecoration: 'none',
  transition: 'transform .12s ease, background .15s ease, box-shadow .15s ease, opacity .15s ease',
  boxSizing: 'border-box',
};

const variants = {
  primary: {
    background: 'var(--grad-lime)', color: 'var(--text-on-lime)',
    boxShadow: 'var(--glow-cta)',
  },
  secondary: {
    background: 'var(--surface-raised)', color: 'var(--text-primary)',
    border: '1px solid var(--border-default)',
  },
  ghost: {
    background: 'transparent', color: 'var(--text-secondary)',
    border: '1px solid var(--border-subtle)',
  },
  dark: {
    background: 'var(--ink-850)', color: 'var(--text-primary)',
    border: '1px solid var(--border-default)',
  },
};

export function Button({
  children, variant = 'primary', size = 'md', href, leadingIcon, trailingIcon,
  disabled = false, fullWidth = false, style, ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const [press, setPress] = React.useState(false);
  const Tag = href ? 'a' : 'button';
  const hoverFx = !disabled && hover ? (
    variant === 'primary'
      ? { filter: 'brightness(1.06)', boxShadow: '0 0 0 1px rgba(138,230,60,0.45), 0 10px 34px rgba(138,230,60,0.38)' }
      : { background: 'var(--ink-600)', borderColor: 'var(--border-strong)' }
  ) : null;
  return (
    <Tag
      href={href} disabled={href ? undefined : disabled}
      onMouseEnter={() => setHover(true)} onMouseLeave={() => { setHover(false); setPress(false); }}
      onMouseDown={() => setPress(true)} onMouseUp={() => setPress(false)}
      style={{
        ...base, ...sizes[size], ...variants[variant], ...hoverFx,
        width: fullWidth ? '100%' : undefined,
        transform: press && !disabled ? 'scale(0.97)' : 'scale(1)',
        opacity: disabled ? 0.45 : 1,
        pointerEvents: disabled ? 'none' : 'auto',
        ...style,
      }}
      {...rest}
    >
      {leadingIcon}{children}{trailingIcon}
    </Tag>
  );
}
