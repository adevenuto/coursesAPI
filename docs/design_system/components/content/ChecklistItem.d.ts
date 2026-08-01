import React from 'react';

export interface ChecklistItemProps {
  children?: React.ReactNode;
  /** true = lime check, false = muted cross. @default true */
  checked?: boolean;
  /** @default "lime" */
  tone?: 'lime' | 'emerald';
  style?: React.CSSProperties;
}

/**
 * A single line in the before/after comparison lists — a rounded check (or muted
 * cross) followed by the label.
 */
export function ChecklistItem(props: ChecklistItemProps): JSX.Element;
