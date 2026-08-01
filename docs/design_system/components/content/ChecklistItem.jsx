import React from 'react';

export function ChecklistItem({ children, checked = true, tone = 'lime', style, ...rest }) {
  const good = checked;
  const ring = good
    ? (tone === 'lime' ? 'var(--lime-400)' : 'var(--emerald-500)')
    : 'var(--text-tertiary)';
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, ...style }} {...rest}>
      <span style={{
        width: 20, height: 20, borderRadius: '50%', flexShrink: 0,
        display: 'grid', placeItems: 'center',
        background: good ? 'rgba(138,230,60,0.14)' : 'rgba(255,255,255,0.04)',
        border: `1px solid ${good ? 'var(--border-lime)' : 'var(--border-default)'}`,
        color: ring,
      }}>
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round">
          {good ? <polyline points="20 6 9 17 4 12" /> : <><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></>}
        </svg>
      </span>
      <span style={{
        fontFamily: 'var(--font-body)', fontSize: 14.5,
        color: good ? 'var(--text-primary)' : 'var(--text-secondary)',
      }}>{children}</span>
    </div>
  );
}
