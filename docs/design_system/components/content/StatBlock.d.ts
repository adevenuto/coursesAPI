import React from 'react';

export interface StatBlockProps {
  /** The headline figure, e.g. "80%", "10k+". */
  value: React.ReactNode;
  label: React.ReactNode;
  /** @default "lime" */
  tone?: 'lime' | 'emerald' | 'neutral';
  /** @default "md" */
  size?: 'sm' | 'md' | 'lg';
  style?: React.CSSProperties;
}

/**
 * A big accent-colored metric over a muted caption — the "80% Less admin time",
 * "10k+ Clients coached" proof stats.
 */
export function StatBlock(props: StatBlockProps): JSX.Element;
