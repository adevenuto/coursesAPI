import React from 'react';

export interface ButtonProps {
  children?: React.ReactNode;
  /** Visual style. @default "primary" */
  variant?: 'primary' | 'secondary' | 'ghost' | 'dark';
  /** @default "md" */
  size?: 'sm' | 'md' | 'lg';
  /** Renders an <a> instead of <button>. */
  href?: string;
  leadingIcon?: React.ReactNode;
  trailingIcon?: React.ReactNode;
  disabled?: boolean;
  fullWidth?: boolean;
  style?: React.CSSProperties;
}

/**
 * Primary action control. Lime gradient fill with a soft glow is the hero CTA;
 * secondary/ghost/dark are the quieter pill variants used in nav and cards.
 * @startingPoint section="Core" subtitle="Pill button, lime CTA + quiet variants" viewport="700x160"
 */
export function Button(props: ButtonProps): JSX.Element;
