import React from 'react';

export interface SectionHeadingProps {
  /** Usually a <Badge>. */
  eyebrow?: React.ReactNode;
  title: React.ReactNode;
  /** Second line, rendered in the accent color. */
  highlight?: React.ReactNode;
  lead?: React.ReactNode;
  /** @default "center" */
  align?: 'center' | 'left';
  /** @default "lime" */
  highlightTone?: 'lime' | 'emerald';
  style?: React.CSSProperties;
}

/**
 * The repeating section header: optional eyebrow badge, a two-line display title
 * whose second line ("Unlimited potential.", "coach like a pro") is the accent
 * color, and an optional muted lead paragraph.
 * @startingPoint section="Content" subtitle="Eyebrow + two-line accent title + lead" viewport="700x260"
 */
export function SectionHeading(props: SectionHeadingProps): JSX.Element;
