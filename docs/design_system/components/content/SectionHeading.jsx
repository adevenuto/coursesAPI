import React from 'react';

export function SectionHeading({
  eyebrow, title, highlight, lead, align = 'center', highlightTone = 'lime', style, ...rest
}) {
  const hlColor = highlightTone === 'lime' ? 'var(--lime-400)' : 'var(--emerald-500)';
  return (
    <div
      style={{
        display: 'flex', flexDirection: 'column',
        alignItems: align === 'center' ? 'center' : 'flex-start',
        textAlign: align, maxWidth: align === 'center' ? 640 : undefined,
        margin: align === 'center' ? '0 auto' : undefined,
        ...style,
      }}
      {...rest}
    >
      {eyebrow}
      <h2 style={{
        fontFamily: 'var(--font-display)', fontWeight: 600,
        fontSize: 'clamp(28px, 4vw, 40px)', lineHeight: 1.14,
        letterSpacing: 'var(--ls-tight)', color: 'var(--text-primary)',
        margin: eyebrow ? '18px 0 0' : 0, textWrap: 'balance',
      }}>
        {title}
        {highlight && <><br /><span style={{ color: hlColor }}>{highlight}</span></>}
      </h2>
      {lead && (
        <p style={{
          fontFamily: 'var(--font-body)', fontSize: 17, lineHeight: 1.6,
          color: 'var(--text-secondary)', margin: '16px 0 0', textWrap: 'pretty',
        }}>{lead}</p>
      )}
    </div>
  );
}
