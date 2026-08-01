import React from 'react';

export function Card({ children, glow = false, hover = false, padding = 24, style, ...rest }) {
  const [isHover, setHover] = React.useState(false);
  return (
    <div
      onMouseEnter={() => hover && setHover(true)}
      onMouseLeave={() => hover && setHover(false)}
      style={{
        position: 'relative',
        background: 'var(--surface-card)',
        border: `1px solid ${isHover ? 'var(--border-lime)' : 'var(--border-subtle)'}`,
        borderRadius: 'var(--radius-lg)',
        padding,
        overflow: 'hidden',
        transition: 'border-color .18s ease, transform .18s ease',
        transform: isHover ? 'translateY(-3px)' : 'none',
        boxShadow: glow ? 'var(--glow-soft)' : 'var(--shadow-md)',
        ...style,
      }}
      {...rest}
    >
      {glow && (
        <div style={{
          position: 'absolute', inset: 0, pointerEvents: 'none',
          background: 'var(--grad-card-glow)',
        }} />
      )}
      <div style={{ position: 'relative' }}>{children}</div>
    </div>
  );
}
