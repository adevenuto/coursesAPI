import React from 'react';

export interface BadgeProps {
  children?: React.ReactNode;
  /** @default "neutral" */
  tone?: 'neutral' | 'lime' | 'solid';
  /** Show the small leading status dot (ignored when `icon` is set). @default true */
  dot?: boolean;
  icon?: React.ReactNode;
  style?: React.CSSProperties;
}

/**
 * Small pill label — the section eyebrows ("The Problem", "Features") and inline
 * status chips ("Popular", "% Adherence"). Neutral hairline by default, lime tint
 * for accented labels, solid lime for emphasis.
 */
export function Badge(props: BadgeProps): JSX.Element;
