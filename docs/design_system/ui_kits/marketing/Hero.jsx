// TrainerFlow marketing — Nav, Hero, and the hero dashboard mock.
const NS = window.TrainerFlowDesignSystem_2e5958;
const { Button, Badge, StatBlock } = NS;
const Ic = ({n, s=16, c}) => <i data-lucide={n} style={{width:s,height:s,color:c}}></i>;

function Nav() {
  const links = ['Features', 'How It Works', 'Pricing', 'FAQ'];
  return (
    <nav style={{position:'sticky',top:0,zIndex:20,display:'flex',alignItems:'center',justifyContent:'space-between',
      padding:'14px 28px',background:'rgba(10,11,10,0.72)',backdropFilter:'blur(14px)',borderBottom:'1px solid var(--border-subtle)'}}>
      <div style={{display:'flex',alignItems:'center',gap:10,fontFamily:'var(--font-display)',fontWeight:700,fontSize:19,letterSpacing:'-0.02em',color:'var(--text-primary)'}}>
        <span style={{width:26,height:26,borderRadius:8,background:'var(--grad-lime)',display:'grid',placeItems:'center'}}>
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#0a1400" strokeWidth="2.4" strokeLinecap="round"><path d="M4 12h4l3-8 4 16 3-8h2"/></svg>
        </span>TrainerFlow
      </div>
      <div style={{display:'flex',gap:28}}>
        {links.map(l => <a key={l} href="#" style={{fontFamily:'var(--font-body)',fontSize:14,color:'var(--text-secondary)',textDecoration:'none'}}
          onMouseEnter={e=>e.currentTarget.style.color='var(--text-primary)'} onMouseLeave={e=>e.currentTarget.style.color='var(--text-secondary)'}>{l}</a>)}
      </div>
      <div style={{display:'flex',gap:10,alignItems:'center'}}>
        <Button variant="dark" size="sm">Login</Button>
        <Button variant="primary" size="sm">Start Free Trial</Button>
      </div>
    </nav>
  );
}

function Bars() {
  const h = [42,58,36,70,52,84,64];
  return (
    <div style={{display:'flex',alignItems:'flex-end',gap:7,height:90,marginTop:10}}>
      {h.map((v,i)=><div key={i} style={{flex:1,height:v,borderRadius:5,background:'var(--grad-lime)',boxShadow:'0 0 12px rgba(138,230,60,0.35)'}}/>)}
    </div>
  );
}

function DashboardMock() {
  return (
    <div style={{position:'relative',perspective:1200}}>
      <div style={{transform:'rotateY(-14deg) rotateX(4deg)',transformStyle:'preserve-3d'}}>
        <div style={{background:'var(--ink-800)',border:'1px solid var(--border-default)',borderRadius:18,padding:20,boxShadow:'0 40px 90px rgba(0,0,0,0.6), var(--glow-soft)'}}>
          <div style={{display:'flex',alignItems:'center',justifyContent:'space-between'}}>
            <div style={{display:'flex',alignItems:'center',gap:11}}>
              <div style={{width:40,height:40,borderRadius:'50%',background:'linear-gradient(135deg,#3a3f38,#1c1e1b)',display:'grid',placeItems:'center',color:'var(--lime-400)',fontWeight:700,fontFamily:'var(--font-display)'}}>FJ</div>
              <div>
                <div style={{color:'var(--text-primary)',fontWeight:600,fontSize:14}}>Felix Johnson</div>
                <div style={{color:'var(--text-tertiary)',fontSize:12}}>Hypertrophy Program · Week 4</div>
              </div>
            </div>
            <Badge tone="lime" dot={false}>89% Adherence</Badge>
          </div>
          <div style={{display:'flex',gap:26,margin:'20px 0 6px'}}>
            <StatBlock size="sm" value="12" label="Active clients" tone="neutral"/>
            <StatBlock size="sm" value="89%" label="Compliance rate"/>
            <StatBlock size="sm" value="12" label="Unread messages" tone="neutral"/>
          </div>
          <div style={{color:'var(--text-tertiary)',fontSize:12,marginTop:10}}>Top performing clients</div>
          <Bars/>
        </div>
      </div>
      <div style={{position:'absolute',bottom:-26,left:36,background:'var(--ink-750)',border:'1px solid var(--border-default)',borderRadius:12,padding:'12px 16px',display:'flex',alignItems:'center',gap:12,boxShadow:'var(--shadow-lg)'}}>
        <span style={{width:34,height:34,borderRadius:9,background:'rgba(138,230,60,0.12)',border:'1px solid var(--border-lime)',display:'grid',placeItems:'center'}}><Ic n="check" s={16} c="var(--lime-400)"/></span>
        <div><div style={{color:'var(--text-primary)',fontSize:13,fontWeight:600}}>Workout Assigned</div><div style={{color:'var(--text-tertiary)',fontSize:11}}>Push Day · 6 exercises</div></div>
      </div>
    </div>
  );
}

function Hero() {
  return (
    <header style={{position:'relative',overflow:'hidden',padding:'64px 28px 96px',background:'var(--grad-aurora), var(--ink-900)'}}>
      <div style={{maxWidth:1120,margin:'0 auto',display:'grid',gridTemplateColumns:'1fr 1fr',gap:48,alignItems:'center'}}>
        <div>
          <Badge tone="lime"><Ic n="sparkles" s={13} c="var(--lime-400)"/> New AI Workout Builder</Badge>
          <h1 style={{fontFamily:'var(--font-display)',fontWeight:700,fontSize:56,lineHeight:1.06,letterSpacing:'-0.03em',color:'var(--text-primary)',margin:'20px 0 0'}}>
            Everything you need to coach clients in <span style={{color:'var(--lime-400)'}}>one app.</span>
          </h1>
          <p style={{fontFamily:'var(--font-body)',fontSize:18,lineHeight:1.6,color:'var(--text-secondary)',margin:'20px 0 0',maxWidth:440}}>
            From workouts to nutrition to progress tracking, TrainerFlow keeps your coaching organized and effective.
          </p>
          <div style={{display:'flex',gap:12,marginTop:28}}>
            <Button variant="primary" size="lg" trailingIcon={<Ic n="arrow-right" s={17} c="#0a1400"/>}>Start Free Trial</Button>
            <Button variant="secondary" size="lg">See How It Works</Button>
          </div>
          <div style={{display:'flex',gap:22,marginTop:22}}>
            <span style={{display:'flex',alignItems:'center',gap:7,fontSize:13,color:'var(--text-tertiary)'}}><Ic n="check-circle" s={15} c="var(--lime-500)"/> Free 14-day trial</span>
            <span style={{display:'flex',alignItems:'center',gap:7,fontSize:13,color:'var(--text-tertiary)'}}><Ic n="check-circle" s={15} c="var(--lime-500)"/> No credit card</span>
          </div>
        </div>
        <DashboardMock/>
      </div>
    </header>
  );
}

Object.assign(window, { TFNav: Nav, TFHero: Hero, TFIc: Ic });
