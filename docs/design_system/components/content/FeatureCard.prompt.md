One-sentence: The feature/problem grid tile — a lime-tinted icon square, title and muted description, with optional top-right badge or media preview.

```jsx
<FeatureCard
  icon={<i data-lucide="dumbbell"></i>}
  title="Workout Builder"
  description="Create and assign custom workout programs with a drag-and-drop builder."
  badge={<Badge tone="solid" dot={false}>Popular</Badge>}
/>
```

Props: `icon`, `title`, `description`, `badge` (top-right), `media` (preview above copy), `hover`, `glow`.
