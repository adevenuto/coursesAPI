import React from 'react';

export function FeatureCard({
  icon, title, description, badge, media, hover = true, glow = false, style, ...rest
}) {
  const [h, setH] = React.useState(false);
  return (
    <div
      onMouseEnter={() => hover && setH(true)}
      onMouseLeave={() => hover && setH(false)}
      style={{
        position: 'relative', display: 'flex', flexDirection: 'column',
        background: 'var(--surface-card)',
        border: `1px solid ${h ? 'var(--border-lime)' : 'var(--border-subtle)'}`,
        borderRadius: 'var(--radius-lg)', padding: 22, overflow: 'hidden',
        transition: 'border-color .18s ease, transform .18s ease',
        transform: h ? 'translateY(-3px)' : 'none',
        boxShadow: glow ? 'var(--glow-soft)' : 'var(--shadow-sm)',
        ...style,
      }}
      {...rest}
    >
      {glow && <div style={{ position: 'absolute', inset: 0, background: 'var(--grad-card-glow)', pointerEvents: 'none' }} />}
      {media && <div style={{ position: 'relative', marginBottom: 18 }}>{media}</div>}
      <div style={{ position: 'relative', display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12 }}>
        {icon && (
          <div style={{
            width: 40, height: 40, borderRadius: 'var(--radius-md)', flexShrink: 0,
            display: 'grid', placeItems: 'center',
            background: 'rgba(138,230,60,0.10)', border: '1px solid var(--border-lime)',
            color: 'var(--lime-400)',
          }}>{icon}</div>
        )}
        {badge}
      </div>
      <h3 style={{
        position: 'relative', fontFamily: 'var(--font-display)', fontWeight: 600,
        fontSize: 18, color: 'var(--text-primary)', margin: icon || media ? '16px 0 0' : 0,
      }}>{title}</h3>
      {description && (
        <p style={{
          position: 'relative', fontFamily: 'var(--font-body)', fontSize: 14,
          lineHeight: 1.55, color: 'var(--text-secondary)', margin: '8px 0 0', textWrap: 'pretty',
        }}>{description}</p>
      )}
    </div>
  );
}
