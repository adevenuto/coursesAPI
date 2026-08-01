import React from 'react';

export function StatBlock({ value, label, tone = 'lime', size = 'md', style, ...rest }) {
  const color = tone === 'lime' ? 'var(--lime-400)' : tone === 'emerald' ? 'var(--emerald-500)' : 'var(--text-primary)';
  const fs = size === 'lg' ? 44 : size === 'sm' ? 26 : 34;
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 4, ...style }} {...rest}>
      <span style={{
        fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: fs,
        lineHeight: 1, letterSpacing: '-0.02em', color,
      }}>{value}</span>
      <span style={{
        fontFamily: 'var(--font-body)', fontSize: 13.5, color: 'var(--text-tertiary)',
      }}>{label}</span>
    </div>
  );
}
