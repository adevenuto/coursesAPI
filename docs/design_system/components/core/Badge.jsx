import React from 'react';

export function Badge({ children, tone = 'neutral', dot = true, icon, style, ...rest }) {
  const tones = {
    neutral: { color: 'var(--text-secondary)', border: '1px solid var(--border-default)', background: 'rgba(255,255,255,0.02)' },
    lime: { color: 'var(--lime-300)', border: '1px solid var(--border-lime)', background: 'rgba(138,230,60,0.08)' },
    solid: { color: 'var(--text-on-lime)', border: '1px solid transparent', background: 'var(--grad-lime)' },
  };
  const dotColor = tone === 'lime' ? 'var(--lime-400)' : tone === 'solid' ? 'var(--text-on-lime)' : 'var(--text-tertiary)';
  return (
    <span
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 7,
        fontFamily: 'var(--font-body)', fontSize: 12, fontWeight: 600,
        letterSpacing: '0.01em', lineHeight: 1,
        padding: '6px 12px', borderRadius: 'var(--radius-pill)',
        ...tones[tone], ...style,
      }}
      {...rest}
    >
      {icon}
      {dot && !icon && (
        <span style={{ width: 6, height: 6, borderRadius: '50%', background: dotColor, flexShrink: 0 }} />
      )}
      {children}
    </span>
  );
}
