// TrainerFlow marketing — Problem, Solution and Features sections.
const NS2 = window.TrainerFlowDesignSystem_2e5958;
const { Badge: B, Button: Btn, SectionHeading, StatBlock: Stat, FeatureCard, ChecklistItem } = NS2;
const Icon = window.TFIc;

function ProblemSection() {
  const items = [
    ['layers','Tool Overload','Switching between Google Sheets, WhatsApp, email and payment apps kills your productivity.'],
    ['user-x','Client Ghosting','Without automated check-ins and easy tracking, clients fall off the wagon and churn.'],
    ['eye-off','Blind Coaching','No data visibility means you\u2019re guessing if your program is actually working.'],
    ['clock','Time Drain','Spending more time on admin work than actually coaching and connecting with clients.'],
  ];
  return (
    <section style={{padding:'96px 28px',background:'var(--ink-900)'}}>
      <div style={{maxWidth:1120,margin:'0 auto'}}>
        <SectionHeading eyebrow={<B tone="neutral">The Problem</B>}
          title="Stop stitching together"
          highlight="spreadsheets & WhatsApp." highlightTone="lime"
          lead="Most trainers spend more time on admin than coaching. It\u2019s time to upgrade your operating system." />
        <div style={{display:'grid',gridTemplateColumns:'repeat(4,1fr)',gap:16,marginTop:48}}>
          {items.map(([ic,t,d])=>(
            <FeatureCard key={t} icon={<Icon n={ic} s={18} c="var(--lime-400)"/>} title={t} description={d}/>
          ))}
        </div>
      </div>
    </section>
  );
}

function SolutionSection() {
  const before = ['Multiple apps for different tasks','Hours spent on spreadsheets','Missed client check-ins','No visibility into compliance'];
  const after = ['Everything in one platform','Automated tracking & reports','Real-time compliance dashboard','AI-powered meal planning'];
  return (
    <section style={{padding:'96px 28px',background:'var(--ink-850)'}}>
      <div style={{maxWidth:1120,margin:'0 auto',display:'grid',gridTemplateColumns:'1fr 1fr',gap:56,alignItems:'center'}}>
        <div>
          <B tone="lime">The Solution</B>
          <h2 style={{fontFamily:'var(--font-display)',fontWeight:700,fontSize:38,letterSpacing:'-0.02em',lineHeight:1.12,margin:'18px 0 0',color:'var(--text-primary)'}}>
            One platform.<br/><span style={{color:'var(--lime-400)'}}>Unlimited potential.</span>
          </h2>
          <p style={{fontFamily:'var(--font-body)',fontSize:16,lineHeight:1.6,color:'var(--text-secondary)',margin:'16px 0 0',maxWidth:420}}>
            TrainerFlow brings workout programming, nutrition planning, client communication and progress tracking into one seamless experience.
          </p>
          <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:'22px 40px',marginTop:32,maxWidth:360}}>
            <Stat value="80%" label="Less admin time"/>
            <Stat value="3x" label="Client retention"/>
            <Stat value="500+" label="Active trainers"/>
            <Stat value="10k+" label="Clients coached"/>
          </div>
        </div>
        <div style={{display:'flex',flexDirection:'column',gap:12}}>
          <div style={{background:'var(--ink-800)',border:'1px solid var(--border-subtle)',borderRadius:16,padding:22}}>
            <div style={{display:'flex',alignItems:'center',gap:8,marginBottom:16,color:'var(--text-secondary)',fontWeight:600,fontSize:14}}><span style={{width:7,height:7,borderRadius:'50%',background:'var(--text-tertiary)'}}/> Before TrainerFlow</div>
            {before.map(x=><div key={x} style={{marginBottom:11}}><ChecklistItem checked={false}>{x}</ChecklistItem></div>)}
          </div>
          <div style={{display:'grid',placeItems:'center'}}><span style={{width:34,height:34,borderRadius:'50%',background:'var(--ink-700)',border:'1px solid var(--border-default)',display:'grid',placeItems:'center'}}><Icon n="arrow-down" s={16} c="var(--text-secondary)"/></span></div>
          <div style={{position:'relative',overflow:'hidden',background:'var(--ink-800)',border:'1px solid var(--border-lime)',borderRadius:16,padding:22,boxShadow:'var(--glow-soft)'}}>
            <div style={{position:'absolute',inset:0,background:'var(--grad-card-glow)'}}/>
            <div style={{position:'relative'}}>
              <div style={{display:'flex',alignItems:'center',gap:8,marginBottom:16,color:'var(--lime-400)',fontWeight:600,fontSize:14}}><span style={{width:7,height:7,borderRadius:'50%',background:'var(--lime-400)'}}/> With TrainerFlow</div>
              {after.map(x=><div key={x} style={{marginBottom:11}}><ChecklistItem>{x}</ChecklistItem></div>)}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function FeaturesSection() {
  const feats = [
    ['dumbbell','Workout Builder','Create and assign customized workout programs with our intuitive drag-and-drop builder.','Popular'],
    ['utensils','Nutrition Planning','Set macros, assign meal plans, and track adherence automatically.',null],
    ['line-chart','Progress Analytics','Visualize strength gains, weight trends and habit consistency.',null],
    ['activity','Progress Tracking','Visualize weight and measurements over time. Monitor steps and activity levels to keep clients on track.',null],
    ['calculator','Calorie Generator','AI-powered calorie and macro calculations based on client goals and activity.',null],
    ['message-circle','In-App Messaging','Communicate seamlessly with clients without leaving the platform.',null],
  ];
  return (
    <section style={{position:'relative',overflow:'hidden',padding:'96px 28px',background:'var(--grad-aurora), var(--ink-900)'}}>
      <div style={{maxWidth:1120,margin:'0 auto'}}>
        <SectionHeading eyebrow={<B tone="lime">Features</B>}
          title="Everything you need to" highlight="coach like a pro"
          lead="Powerful tools designed specifically for personal trainers who want to scale their business without sacrificing quality." />
        <div style={{display:'grid',gridTemplateColumns:'repeat(3,1fr)',gap:16,marginTop:48}}>
          {feats.map(([ic,t,d,badge])=>(
            <FeatureCard key={t} icon={<Icon n={ic} s={18} c="var(--lime-400)"/>} title={t} description={d}
              badge={badge?<B tone="solid" dot={false}>{badge}</B>:null} glow={t==='Nutrition Planning'} />
          ))}
        </div>
      </div>
    </section>
  );
}

function CTASection() {
  return (
    <section style={{padding:'0 28px 96px',background:'var(--ink-900)'}}>
      <div style={{maxWidth:1120,margin:'0 auto',position:'relative',overflow:'hidden',borderRadius:24,padding:'56px 40px',textAlign:'center',background:'var(--grad-aurora), var(--ink-800)',border:'1px solid var(--border-lime)'}}>
        <h2 style={{fontFamily:'var(--font-display)',fontWeight:700,fontSize:36,letterSpacing:'-0.02em',color:'var(--text-primary)',margin:0}}>Ready to coach like a pro?</h2>
        <p style={{fontFamily:'var(--font-body)',fontSize:17,color:'var(--text-secondary)',margin:'14px auto 26px',maxWidth:440}}>Join 500+ trainers who\u2019ve upgraded their coaching operating system.</p>
        <div style={{display:'flex',gap:12,justifyContent:'center'}}>
          <Btn variant="primary" size="lg" trailingIcon={<Icon n="arrow-right" s={17} c="#0a1400"/>}>Start Free Trial</Btn>
          <Btn variant="secondary" size="lg">Book a Demo</Btn>
        </div>
      </div>
    </section>
  );
}

Object.assign(window, { TFProblem: ProblemSection, TFSolution: SolutionSection, TFFeatures: FeaturesSection, TFCTA: CTASection });
