import React from 'react';

export interface CardProps {
  children?: React.ReactNode;
  /** Adds the top-down emerald radial glow used on featured cards. @default false */
  glow?: boolean;
  /** Lift + lime border on hover. @default false */
  hover?: boolean;
  /** @default 24 */
  padding?: number | string;
  style?: React.CSSProperties;
}

/**
 * The base charcoal surface — hairline border, 16px radius, soft drop shadow.
 * `glow` adds the signature emerald top-glow for highlighted cards; `hover`
 * enables the lift-and-lime-border interaction used in feature grids.
 */
export function Card(props: CardProps): JSX.Element;
