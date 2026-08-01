/* @ds-bundle: {"format":4,"namespace":"TrainerFlowDesignSystem_2e5958","components":[{"name":"ChecklistItem","sourcePath":"components/content/ChecklistItem.jsx"},{"name":"FeatureCard","sourcePath":"components/content/FeatureCard.jsx"},{"name":"SectionHeading","sourcePath":"components/content/SectionHeading.jsx"},{"name":"StatBlock","sourcePath":"components/content/StatBlock.jsx"},{"name":"Badge","sourcePath":"components/core/Badge.jsx"},{"name":"Button","sourcePath":"components/core/Button.jsx"},{"name":"Card","sourcePath":"components/core/Card.jsx"}],"sourceHashes":{"components/content/ChecklistItem.jsx":"4f438015564d","components/content/FeatureCard.jsx":"cbb9b7b84017","components/content/SectionHeading.jsx":"290089120264","components/content/StatBlock.jsx":"7ddd40e2c427","components/core/Badge.jsx":"af651e91a19f","components/core/Button.jsx":"4205e44983d3","components/core/Card.jsx":"4490a56b6b13","ui_kits/marketing/Hero.jsx":"117858475a7b","ui_kits/marketing/Sections.jsx":"2e67e5d4e8d1"},"inlinedExternals":[],"unexposedExports":[]} */

(() => {

const __ds_ns = (window.TrainerFlowDesignSystem_2e5958 = window.TrainerFlowDesignSystem_2e5958 || {});

const __ds_scope = {};

(__ds_ns.__errors = __ds_ns.__errors || []);

// components/content/ChecklistItem.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function ChecklistItem({
  children,
  checked = true,
  tone = 'lime',
  style,
  ...rest
}) {
  const good = checked;
  const ring = good ? tone === 'lime' ? 'var(--lime-400)' : 'var(--emerald-500)' : 'var(--text-tertiary)';
  return /*#__PURE__*/React.createElement("div", _extends({
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 12,
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("span", {
    style: {
      width: 20,
      height: 20,
      borderRadius: '50%',
      flexShrink: 0,
      display: 'grid',
      placeItems: 'center',
      background: good ? 'rgba(138,230,60,0.14)' : 'rgba(255,255,255,0.04)',
      border: `1px solid ${good ? 'var(--border-lime)' : 'var(--border-default)'}`,
      color: ring
    }
  }, /*#__PURE__*/React.createElement("svg", {
    width: "11",
    height: "11",
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: "3.5",
    strokeLinecap: "round",
    strokeLinejoin: "round"
  }, good ? /*#__PURE__*/React.createElement("polyline", {
    points: "20 6 9 17 4 12"
  }) : /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("line", {
    x1: "18",
    y1: "6",
    x2: "6",
    y2: "18"
  }), /*#__PURE__*/React.createElement("line", {
    x1: "6",
    y1: "6",
    x2: "18",
    y2: "18"
  })))), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-body)',
      fontSize: 14.5,
      color: good ? 'var(--text-primary)' : 'var(--text-secondary)'
    }
  }, children));
}
Object.assign(__ds_scope, { ChecklistItem });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/content/ChecklistItem.jsx", error: String((e && e.message) || e) }); }

// components/content/FeatureCard.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function FeatureCard({
  icon,
  title,
  description,
  badge,
  media,
  hover = true,
  glow = false,
  style,
  ...rest
}) {
  const [h, setH] = React.useState(false);
  return /*#__PURE__*/React.createElement("div", _extends({
    onMouseEnter: () => hover && setH(true),
    onMouseLeave: () => hover && setH(false),
    style: {
      position: 'relative',
      display: 'flex',
      flexDirection: 'column',
      background: 'var(--surface-card)',
      border: `1px solid ${h ? 'var(--border-lime)' : 'var(--border-subtle)'}`,
      borderRadius: 'var(--radius-lg)',
      padding: 22,
      overflow: 'hidden',
      transition: 'border-color .18s ease, transform .18s ease',
      transform: h ? 'translateY(-3px)' : 'none',
      boxShadow: glow ? 'var(--glow-soft)' : 'var(--shadow-sm)',
      ...style
    }
  }, rest), glow && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      background: 'var(--grad-card-glow)',
      pointerEvents: 'none'
    }
  }), media && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      marginBottom: 18
    }
  }, media), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      display: 'flex',
      alignItems: 'flex-start',
      justifyContent: 'space-between',
      gap: 12
    }
  }, icon && /*#__PURE__*/React.createElement("div", {
    style: {
      width: 40,
      height: 40,
      borderRadius: 'var(--radius-md)',
      flexShrink: 0,
      display: 'grid',
      placeItems: 'center',
      background: 'rgba(138,230,60,0.10)',
      border: '1px solid var(--border-lime)',
      color: 'var(--lime-400)'
    }
  }, icon), badge), /*#__PURE__*/React.createElement("h3", {
    style: {
      position: 'relative',
      fontFamily: 'var(--font-display)',
      fontWeight: 600,
      fontSize: 18,
      color: 'var(--text-primary)',
      margin: icon || media ? '16px 0 0' : 0
    }
  }, title), description && /*#__PURE__*/React.createElement("p", {
    style: {
      position: 'relative',
      fontFamily: 'var(--font-body)',
      fontSize: 14,
      lineHeight: 1.55,
      color: 'var(--text-secondary)',
      margin: '8px 0 0',
      textWrap: 'pretty'
    }
  }, description));
}
Object.assign(__ds_scope, { FeatureCard });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/content/FeatureCard.jsx", error: String((e && e.message) || e) }); }

// components/content/SectionHeading.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function SectionHeading({
  eyebrow,
  title,
  highlight,
  lead,
  align = 'center',
  highlightTone = 'lime',
  style,
  ...rest
}) {
  const hlColor = highlightTone === 'lime' ? 'var(--lime-400)' : 'var(--emerald-500)';
  return /*#__PURE__*/React.createElement("div", _extends({
    style: {
      display: 'flex',
      flexDirection: 'column',
      alignItems: align === 'center' ? 'center' : 'flex-start',
      textAlign: align,
      maxWidth: align === 'center' ? 640 : undefined,
      margin: align === 'center' ? '0 auto' : undefined,
      ...style
    }
  }, rest), eyebrow, /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 600,
      fontSize: 'clamp(28px, 4vw, 40px)',
      lineHeight: 1.14,
      letterSpacing: 'var(--ls-tight)',
      color: 'var(--text-primary)',
      margin: eyebrow ? '18px 0 0' : 0,
      textWrap: 'balance'
    }
  }, title, highlight && /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("br", null), /*#__PURE__*/React.createElement("span", {
    style: {
      color: hlColor
    }
  }, highlight))), lead && /*#__PURE__*/React.createElement("p", {
    style: {
      fontFamily: 'var(--font-body)',
      fontSize: 17,
      lineHeight: 1.6,
      color: 'var(--text-secondary)',
      margin: '16px 0 0',
      textWrap: 'pretty'
    }
  }, lead));
}
Object.assign(__ds_scope, { SectionHeading });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/content/SectionHeading.jsx", error: String((e && e.message) || e) }); }

// components/content/StatBlock.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function StatBlock({
  value,
  label,
  tone = 'lime',
  size = 'md',
  style,
  ...rest
}) {
  const color = tone === 'lime' ? 'var(--lime-400)' : tone === 'emerald' ? 'var(--emerald-500)' : 'var(--text-primary)';
  const fs = size === 'lg' ? 44 : size === 'sm' ? 26 : 34;
  return /*#__PURE__*/React.createElement("div", _extends({
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 4,
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 700,
      fontSize: fs,
      lineHeight: 1,
      letterSpacing: '-0.02em',
      color
    }
  }, value), /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-body)',
      fontSize: 13.5,
      color: 'var(--text-tertiary)'
    }
  }, label));
}
Object.assign(__ds_scope, { StatBlock });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/content/StatBlock.jsx", error: String((e && e.message) || e) }); }

// components/core/Badge.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function Badge({
  children,
  tone = 'neutral',
  dot = true,
  icon,
  style,
  ...rest
}) {
  const tones = {
    neutral: {
      color: 'var(--text-secondary)',
      border: '1px solid var(--border-default)',
      background: 'rgba(255,255,255,0.02)'
    },
    lime: {
      color: 'var(--lime-300)',
      border: '1px solid var(--border-lime)',
      background: 'rgba(138,230,60,0.08)'
    },
    solid: {
      color: 'var(--text-on-lime)',
      border: '1px solid transparent',
      background: 'var(--grad-lime)'
    }
  };
  const dotColor = tone === 'lime' ? 'var(--lime-400)' : tone === 'solid' ? 'var(--text-on-lime)' : 'var(--text-tertiary)';
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 7,
      fontFamily: 'var(--font-body)',
      fontSize: 12,
      fontWeight: 600,
      letterSpacing: '0.01em',
      lineHeight: 1,
      padding: '6px 12px',
      borderRadius: 'var(--radius-pill)',
      ...tones[tone],
      ...style
    }
  }, rest), icon, dot && !icon && /*#__PURE__*/React.createElement("span", {
    style: {
      width: 6,
      height: 6,
      borderRadius: '50%',
      background: dotColor,
      flexShrink: 0
    }
  }), children);
}
Object.assign(__ds_scope, { Badge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Badge.jsx", error: String((e && e.message) || e) }); }

// components/core/Button.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const sizes = {
  sm: {
    fontSize: 13,
    padding: '8px 16px',
    height: 36
  },
  md: {
    fontSize: 15,
    padding: '11px 22px',
    height: 44
  },
  lg: {
    fontSize: 16,
    padding: '14px 28px',
    height: 52
  }
};
const base = {
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  gap: 8,
  fontFamily: 'var(--font-body)',
  fontWeight: 600,
  lineHeight: 1,
  borderRadius: 'var(--radius-pill)',
  border: '1px solid transparent',
  cursor: 'pointer',
  whiteSpace: 'nowrap',
  textDecoration: 'none',
  transition: 'transform .12s ease, background .15s ease, box-shadow .15s ease, opacity .15s ease',
  boxSizing: 'border-box'
};
const variants = {
  primary: {
    background: 'var(--grad-lime)',
    color: 'var(--text-on-lime)',
    boxShadow: 'var(--glow-cta)'
  },
  secondary: {
    background: 'var(--surface-raised)',
    color: 'var(--text-primary)',
    border: '1px solid var(--border-default)'
  },
  ghost: {
    background: 'transparent',
    color: 'var(--text-secondary)',
    border: '1px solid var(--border-subtle)'
  },
  dark: {
    background: 'var(--ink-850)',
    color: 'var(--text-primary)',
    border: '1px solid var(--border-default)'
  }
};
function Button({
  children,
  variant = 'primary',
  size = 'md',
  href,
  leadingIcon,
  trailingIcon,
  disabled = false,
  fullWidth = false,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const [press, setPress] = React.useState(false);
  const Tag = href ? 'a' : 'button';
  const hoverFx = !disabled && hover ? variant === 'primary' ? {
    filter: 'brightness(1.06)',
    boxShadow: '0 0 0 1px rgba(138,230,60,0.45), 0 10px 34px rgba(138,230,60,0.38)'
  } : {
    background: 'var(--ink-600)',
    borderColor: 'var(--border-strong)'
  } : null;
  return /*#__PURE__*/React.createElement(Tag, _extends({
    href: href,
    disabled: href ? undefined : disabled,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => {
      setHover(false);
      setPress(false);
    },
    onMouseDown: () => setPress(true),
    onMouseUp: () => setPress(false),
    style: {
      ...base,
      ...sizes[size],
      ...variants[variant],
      ...hoverFx,
      width: fullWidth ? '100%' : undefined,
      transform: press && !disabled ? 'scale(0.97)' : 'scale(1)',
      opacity: disabled ? 0.45 : 1,
      pointerEvents: disabled ? 'none' : 'auto',
      ...style
    }
  }, rest), leadingIcon, children, trailingIcon);
}
Object.assign(__ds_scope, { Button });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Button.jsx", error: String((e && e.message) || e) }); }

// components/core/Card.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function Card({
  children,
  glow = false,
  hover = false,
  padding = 24,
  style,
  ...rest
}) {
  const [isHover, setHover] = React.useState(false);
  return /*#__PURE__*/React.createElement("div", _extends({
    onMouseEnter: () => hover && setHover(true),
    onMouseLeave: () => hover && setHover(false),
    style: {
      position: 'relative',
      background: 'var(--surface-card)',
      border: `1px solid ${isHover ? 'var(--border-lime)' : 'var(--border-subtle)'}`,
      borderRadius: 'var(--radius-lg)',
      padding,
      overflow: 'hidden',
      transition: 'border-color .18s ease, transform .18s ease',
      transform: isHover ? 'translateY(-3px)' : 'none',
      boxShadow: glow ? 'var(--glow-soft)' : 'var(--shadow-md)',
      ...style
    }
  }, rest), glow && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      pointerEvents: 'none',
      background: 'var(--grad-card-glow)'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative'
    }
  }, children));
}
Object.assign(__ds_scope, { Card });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Card.jsx", error: String((e && e.message) || e) }); }

// ui_kits/marketing/Hero.jsx
try { (() => {
// TrainerFlow marketing — Nav, Hero, and the hero dashboard mock.
const NS = window.TrainerFlowDesignSystem_2e5958;
const {
  Button,
  Badge,
  StatBlock
} = NS;
const Ic = ({
  n,
  s = 16,
  c
}) => /*#__PURE__*/React.createElement("i", {
  "data-lucide": n,
  style: {
    width: s,
    height: s,
    color: c
  }
});
function Nav() {
  const links = ['Features', 'How It Works', 'Pricing', 'FAQ'];
  return /*#__PURE__*/React.createElement("nav", {
    style: {
      position: 'sticky',
      top: 0,
      zIndex: 20,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      padding: '14px 28px',
      background: 'rgba(10,11,10,0.72)',
      backdropFilter: 'blur(14px)',
      borderBottom: '1px solid var(--border-subtle)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 10,
      fontFamily: 'var(--font-display)',
      fontWeight: 700,
      fontSize: 19,
      letterSpacing: '-0.02em',
      color: 'var(--text-primary)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 26,
      height: 26,
      borderRadius: 8,
      background: 'var(--grad-lime)',
      display: 'grid',
      placeItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("svg", {
    viewBox: "0 0 24 24",
    width: "15",
    height: "15",
    fill: "none",
    stroke: "#0a1400",
    strokeWidth: "2.4",
    strokeLinecap: "round"
  }, /*#__PURE__*/React.createElement("path", {
    d: "M4 12h4l3-8 4 16 3-8h2"
  }))), "TrainerFlow"), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 28
    }
  }, links.map(l => /*#__PURE__*/React.createElement("a", {
    key: l,
    href: "#",
    style: {
      fontFamily: 'var(--font-body)',
      fontSize: 14,
      color: 'var(--text-secondary)',
      textDecoration: 'none'
    },
    onMouseEnter: e => e.currentTarget.style.color = 'var(--text-primary)',
    onMouseLeave: e => e.currentTarget.style.color = 'var(--text-secondary)'
  }, l))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 10,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Button, {
    variant: "dark",
    size: "sm"
  }, "Login"), /*#__PURE__*/React.createElement(Button, {
    variant: "primary",
    size: "sm"
  }, "Start Free Trial")));
}
function Bars() {
  const h = [42, 58, 36, 70, 52, 84, 64];
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-end',
      gap: 7,
      height: 90,
      marginTop: 10
    }
  }, h.map((v, i) => /*#__PURE__*/React.createElement("div", {
    key: i,
    style: {
      flex: 1,
      height: v,
      borderRadius: 5,
      background: 'var(--grad-lime)',
      boxShadow: '0 0 12px rgba(138,230,60,0.35)'
    }
  })));
}
function DashboardMock() {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      perspective: 1200
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      transform: 'rotateY(-14deg) rotateX(4deg)',
      transformStyle: 'preserve-3d'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--ink-800)',
      border: '1px solid var(--border-default)',
      borderRadius: 18,
      padding: 20,
      boxShadow: '0 40px 90px rgba(0,0,0,0.6), var(--glow-soft)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 11
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      width: 40,
      height: 40,
      borderRadius: '50%',
      background: 'linear-gradient(135deg,#3a3f38,#1c1e1b)',
      display: 'grid',
      placeItems: 'center',
      color: 'var(--lime-400)',
      fontWeight: 700,
      fontFamily: 'var(--font-display)'
    }
  }, "FJ"), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-primary)',
      fontWeight: 600,
      fontSize: 14
    }
  }, "Felix Johnson"), /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-tertiary)',
      fontSize: 12
    }
  }, "Hypertrophy Program \xB7 Week 4"))), /*#__PURE__*/React.createElement(Badge, {
    tone: "lime",
    dot: false
  }, "89% Adherence")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 26,
      margin: '20px 0 6px'
    }
  }, /*#__PURE__*/React.createElement(StatBlock, {
    size: "sm",
    value: "12",
    label: "Active clients",
    tone: "neutral"
  }), /*#__PURE__*/React.createElement(StatBlock, {
    size: "sm",
    value: "89%",
    label: "Compliance rate"
  }), /*#__PURE__*/React.createElement(StatBlock, {
    size: "sm",
    value: "12",
    label: "Unread messages",
    tone: "neutral"
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-tertiary)',
      fontSize: 12,
      marginTop: 10
    }
  }, "Top performing clients"), /*#__PURE__*/React.createElement(Bars, null))), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      bottom: -26,
      left: 36,
      background: 'var(--ink-750)',
      border: '1px solid var(--border-default)',
      borderRadius: 12,
      padding: '12px 16px',
      display: 'flex',
      alignItems: 'center',
      gap: 12,
      boxShadow: 'var(--shadow-lg)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 34,
      height: 34,
      borderRadius: 9,
      background: 'rgba(138,230,60,0.12)',
      border: '1px solid var(--border-lime)',
      display: 'grid',
      placeItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Ic, {
    n: "check",
    s: 16,
    c: "var(--lime-400)"
  })), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-primary)',
      fontSize: 13,
      fontWeight: 600
    }
  }, "Workout Assigned"), /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-tertiary)',
      fontSize: 11
    }
  }, "Push Day \xB7 6 exercises"))));
}
function Hero() {
  return /*#__PURE__*/React.createElement("header", {
    style: {
      position: 'relative',
      overflow: 'hidden',
      padding: '64px 28px 96px',
      background: 'var(--grad-aurora), var(--ink-900)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 1120,
      margin: '0 auto',
      display: 'grid',
      gridTemplateColumns: '1fr 1fr',
      gap: 48,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(Badge, {
    tone: "lime"
  }, /*#__PURE__*/React.createElement(Ic, {
    n: "sparkles",
    s: 13,
    c: "var(--lime-400)"
  }), " New AI Workout Builder"), /*#__PURE__*/React.createElement("h1", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 700,
      fontSize: 56,
      lineHeight: 1.06,
      letterSpacing: '-0.03em',
      color: 'var(--text-primary)',
      margin: '20px 0 0'
    }
  }, "Everything you need to coach clients in ", /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--lime-400)'
    }
  }, "one app.")), /*#__PURE__*/React.createElement("p", {
    style: {
      fontFamily: 'var(--font-body)',
      fontSize: 18,
      lineHeight: 1.6,
      color: 'var(--text-secondary)',
      margin: '20px 0 0',
      maxWidth: 440
    }
  }, "From workouts to nutrition to progress tracking, TrainerFlow keeps your coaching organized and effective."), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 12,
      marginTop: 28
    }
  }, /*#__PURE__*/React.createElement(Button, {
    variant: "primary",
    size: "lg",
    trailingIcon: /*#__PURE__*/React.createElement(Ic, {
      n: "arrow-right",
      s: 17,
      c: "#0a1400"
    })
  }, "Start Free Trial"), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    size: "lg"
  }, "See How It Works")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 22,
      marginTop: 22
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 7,
      fontSize: 13,
      color: 'var(--text-tertiary)'
    }
  }, /*#__PURE__*/React.createElement(Ic, {
    n: "check-circle",
    s: 15,
    c: "var(--lime-500)"
  }), " Free 14-day trial"), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 7,
      fontSize: 13,
      color: 'var(--text-tertiary)'
    }
  }, /*#__PURE__*/React.createElement(Ic, {
    n: "check-circle",
    s: 15,
    c: "var(--lime-500)"
  }), " No credit card"))), /*#__PURE__*/React.createElement(DashboardMock, null)));
}
Object.assign(window, {
  TFNav: Nav,
  TFHero: Hero,
  TFIc: Ic
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/marketing/Hero.jsx", error: String((e && e.message) || e) }); }

// ui_kits/marketing/Sections.jsx
try { (() => {
// TrainerFlow marketing — Problem, Solution and Features sections.
const NS2 = window.TrainerFlowDesignSystem_2e5958;
const {
  Badge: B,
  Button: Btn,
  SectionHeading,
  StatBlock: Stat,
  FeatureCard,
  ChecklistItem
} = NS2;
const Icon = window.TFIc;
function ProblemSection() {
  const items = [['layers', 'Tool Overload', 'Switching between Google Sheets, WhatsApp, email and payment apps kills your productivity.'], ['user-x', 'Client Ghosting', 'Without automated check-ins and easy tracking, clients fall off the wagon and churn.'], ['eye-off', 'Blind Coaching', 'No data visibility means you\u2019re guessing if your program is actually working.'], ['clock', 'Time Drain', 'Spending more time on admin work than actually coaching and connecting with clients.']];
  return /*#__PURE__*/React.createElement("section", {
    style: {
      padding: '96px 28px',
      background: 'var(--ink-900)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 1120,
      margin: '0 auto'
    }
  }, /*#__PURE__*/React.createElement(SectionHeading, {
    eyebrow: /*#__PURE__*/React.createElement(B, {
      tone: "neutral"
    }, "The Problem"),
    title: "Stop stitching together",
    highlight: "spreadsheets & WhatsApp.",
    highlightTone: "lime",
    lead: "Most trainers spend more time on admin than coaching. It\\u2019s time to upgrade your operating system."
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(4,1fr)',
      gap: 16,
      marginTop: 48
    }
  }, items.map(([ic, t, d]) => /*#__PURE__*/React.createElement(FeatureCard, {
    key: t,
    icon: /*#__PURE__*/React.createElement(Icon, {
      n: ic,
      s: 18,
      c: "var(--lime-400)"
    }),
    title: t,
    description: d
  })))));
}
function SolutionSection() {
  const before = ['Multiple apps for different tasks', 'Hours spent on spreadsheets', 'Missed client check-ins', 'No visibility into compliance'];
  const after = ['Everything in one platform', 'Automated tracking & reports', 'Real-time compliance dashboard', 'AI-powered meal planning'];
  return /*#__PURE__*/React.createElement("section", {
    style: {
      padding: '96px 28px',
      background: 'var(--ink-850)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 1120,
      margin: '0 auto',
      display: 'grid',
      gridTemplateColumns: '1fr 1fr',
      gap: 56,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(B, {
    tone: "lime"
  }, "The Solution"), /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 700,
      fontSize: 38,
      letterSpacing: '-0.02em',
      lineHeight: 1.12,
      margin: '18px 0 0',
      color: 'var(--text-primary)'
    }
  }, "One platform.", /*#__PURE__*/React.createElement("br", null), /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--lime-400)'
    }
  }, "Unlimited potential.")), /*#__PURE__*/React.createElement("p", {
    style: {
      fontFamily: 'var(--font-body)',
      fontSize: 16,
      lineHeight: 1.6,
      color: 'var(--text-secondary)',
      margin: '16px 0 0',
      maxWidth: 420
    }
  }, "TrainerFlow brings workout programming, nutrition planning, client communication and progress tracking into one seamless experience."), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '1fr 1fr',
      gap: '22px 40px',
      marginTop: 32,
      maxWidth: 360
    }
  }, /*#__PURE__*/React.createElement(Stat, {
    value: "80%",
    label: "Less admin time"
  }), /*#__PURE__*/React.createElement(Stat, {
    value: "3x",
    label: "Client retention"
  }), /*#__PURE__*/React.createElement(Stat, {
    value: "500+",
    label: "Active trainers"
  }), /*#__PURE__*/React.createElement(Stat, {
    value: "10k+",
    label: "Clients coached"
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 12
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--ink-800)',
      border: '1px solid var(--border-subtle)',
      borderRadius: 16,
      padding: 22
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 8,
      marginBottom: 16,
      color: 'var(--text-secondary)',
      fontWeight: 600,
      fontSize: 14
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 7,
      height: 7,
      borderRadius: '50%',
      background: 'var(--text-tertiary)'
    }
  }), " Before TrainerFlow"), before.map(x => /*#__PURE__*/React.createElement("div", {
    key: x,
    style: {
      marginBottom: 11
    }
  }, /*#__PURE__*/React.createElement(ChecklistItem, {
    checked: false
  }, x)))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      placeItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 34,
      height: 34,
      borderRadius: '50%',
      background: 'var(--ink-700)',
      border: '1px solid var(--border-default)',
      display: 'grid',
      placeItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    n: "arrow-down",
    s: 16,
    c: "var(--text-secondary)"
  }))), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      overflow: 'hidden',
      background: 'var(--ink-800)',
      border: '1px solid var(--border-lime)',
      borderRadius: 16,
      padding: 22,
      boxShadow: 'var(--glow-soft)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      background: 'var(--grad-card-glow)'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 8,
      marginBottom: 16,
      color: 'var(--lime-400)',
      fontWeight: 600,
      fontSize: 14
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 7,
      height: 7,
      borderRadius: '50%',
      background: 'var(--lime-400)'
    }
  }), " With TrainerFlow"), after.map(x => /*#__PURE__*/React.createElement("div", {
    key: x,
    style: {
      marginBottom: 11
    }
  }, /*#__PURE__*/React.createElement(ChecklistItem, null, x))))))));
}
function FeaturesSection() {
  const feats = [['dumbbell', 'Workout Builder', 'Create and assign customized workout programs with our intuitive drag-and-drop builder.', 'Popular'], ['utensils', 'Nutrition Planning', 'Set macros, assign meal plans, and track adherence automatically.', null], ['line-chart', 'Progress Analytics', 'Visualize strength gains, weight trends and habit consistency.', null], ['activity', 'Progress Tracking', 'Visualize weight and measurements over time. Monitor steps and activity levels to keep clients on track.', null], ['calculator', 'Calorie Generator', 'AI-powered calorie and macro calculations based on client goals and activity.', null], ['message-circle', 'In-App Messaging', 'Communicate seamlessly with clients without leaving the platform.', null]];
  return /*#__PURE__*/React.createElement("section", {
    style: {
      position: 'relative',
      overflow: 'hidden',
      padding: '96px 28px',
      background: 'var(--grad-aurora), var(--ink-900)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 1120,
      margin: '0 auto'
    }
  }, /*#__PURE__*/React.createElement(SectionHeading, {
    eyebrow: /*#__PURE__*/React.createElement(B, {
      tone: "lime"
    }, "Features"),
    title: "Everything you need to",
    highlight: "coach like a pro",
    lead: "Powerful tools designed specifically for personal trainers who want to scale their business without sacrificing quality."
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3,1fr)',
      gap: 16,
      marginTop: 48
    }
  }, feats.map(([ic, t, d, badge]) => /*#__PURE__*/React.createElement(FeatureCard, {
    key: t,
    icon: /*#__PURE__*/React.createElement(Icon, {
      n: ic,
      s: 18,
      c: "var(--lime-400)"
    }),
    title: t,
    description: d,
    badge: badge ? /*#__PURE__*/React.createElement(B, {
      tone: "solid",
      dot: false
    }, badge) : null,
    glow: t === 'Nutrition Planning'
  })))));
}
function CTASection() {
  return /*#__PURE__*/React.createElement("section", {
    style: {
      padding: '0 28px 96px',
      background: 'var(--ink-900)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 1120,
      margin: '0 auto',
      position: 'relative',
      overflow: 'hidden',
      borderRadius: 24,
      padding: '56px 40px',
      textAlign: 'center',
      background: 'var(--grad-aurora), var(--ink-800)',
      border: '1px solid var(--border-lime)'
    }
  }, /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 700,
      fontSize: 36,
      letterSpacing: '-0.02em',
      color: 'var(--text-primary)',
      margin: 0
    }
  }, "Ready to coach like a pro?"), /*#__PURE__*/React.createElement("p", {
    style: {
      fontFamily: 'var(--font-body)',
      fontSize: 17,
      color: 'var(--text-secondary)',
      margin: '14px auto 26px',
      maxWidth: 440
    }
  }, "Join 500+ trainers who\\u2019ve upgraded their coaching operating system."), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 12,
      justifyContent: 'center'
    }
  }, /*#__PURE__*/React.createElement(Btn, {
    variant: "primary",
    size: "lg",
    trailingIcon: /*#__PURE__*/React.createElement(Icon, {
      n: "arrow-right",
      s: 17,
      c: "#0a1400"
    })
  }, "Start Free Trial"), /*#__PURE__*/React.createElement(Btn, {
    variant: "secondary",
    size: "lg"
  }, "Book a Demo"))));
}
Object.assign(window, {
  TFProblem: ProblemSection,
  TFSolution: SolutionSection,
  TFFeatures: FeaturesSection,
  TFCTA: CTASection
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/marketing/Sections.jsx", error: String((e && e.message) || e) }); }

__ds_ns.ChecklistItem = __ds_scope.ChecklistItem;

__ds_ns.FeatureCard = __ds_scope.FeatureCard;

__ds_ns.SectionHeading = __ds_scope.SectionHeading;

__ds_ns.StatBlock = __ds_scope.StatBlock;

__ds_ns.Badge = __ds_scope.Badge;

__ds_ns.Button = __ds_scope.Button;

__ds_ns.Card = __ds_scope.Card;

})();
