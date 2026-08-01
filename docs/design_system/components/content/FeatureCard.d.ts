import React from 'react';

export interface FeatureCardProps {
  /** Glyph shown in the lime-tinted rounded tile. */
  icon?: React.ReactNode;
  title: React.ReactNode;
  description?: React.ReactNode;
  /** Optional chip in the top-right, e.g. a "Popular" <Badge>. */
  badge?: React.ReactNode;
  /** Optional preview / screenshot area above the title. */
  media?: React.ReactNode;
  /** @default true */
  hover?: boolean;
  /** @default false */
  glow?: boolean;
  style?: React.CSSProperties;
}

/**
 * The icon-title-description tile used in the "problem" and "features" grids.
 * Icon sits in a lime-tinted rounded square; optional top-right badge and an
 * optional media preview above the copy.
 * @startingPoint section="Content" subtitle="Icon + title + description feature tile" viewport="360x260"
 */
export function FeatureCard(props: FeatureCardProps): JSX.Element;
