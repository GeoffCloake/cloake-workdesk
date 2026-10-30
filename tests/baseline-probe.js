// Route probe: logs in, visits every route at phone width, reports HTTP status,
// horizontal overflow, version label, bottom nav, and CSP violations.
const {chromium}=require('playwright');
const BASE=process.env.BASE||'http://localhost:8080/';
const OUT=process.argv[2]||'artifacts/probe';
const W=parseInt(process.env.W||'375',10);
(async()=>{
  const fs=require('fs');fs.mkdirSync(OUT,{recursive:true});
  const b=await chromium.launch();
  const ctx=await b.newContext({viewport:{width:W,height:740},deviceScaleFactor:2});
  const p=await ctx.newPage();
  const csp=[];
  p.on('console',m=>{if(/Content Security Policy|Refused/.test(m.text()))csp.push(p.url().replace(BASE,'')+' :: '+m.text().slice(0,140));});
  await p.goto(BASE);
  await p.fill('#pw','local-test-pass');
  await Promise.all([p.waitForNavigation(),p.click('button[type=submit]')]);
  const routes=(process.env.ROUTES||'?page=today,?page=clients,?page=client&id=11,?page=tasks,?page=task&id=2048,?page=work,?page=enquiries,?page=invoicing,?page=items,?page=more,add-work.php,add-enquiry.php,convert-enquiry.php?id=1,edit-task.php?id=2048,edit-enquiry.php?id=1').split(',');
  for(const r of routes){
    const resp=await p.goto(BASE+r);
    const m=await p.evaluate(()=>({sw:document.documentElement.scrollWidth,cw:document.documentElement.clientWidth,
      h1:(document.querySelector('h1')||{}).textContent,ver:(document.querySelector('.release-indicator')||{}).textContent,
      nav:[...document.querySelectorAll('nav.bottom a')].map(a=>a.textContent.trim()).join('|')}));
    console.log(resp.status(),r.padEnd(26),'overflowX='+(m.sw>m.cw),'h1='+m.h1,'| '+m.ver,'| nav:'+m.nav);
    await p.screenshot({path:`${OUT}/${r.replace(/[^a-z0-9]+/gi,'_')}.png`,fullPage:true});
  }
  console.log('\nCSP violations:',csp.length);
  [...new Set(csp)].slice(0,15).forEach(x=>console.log('  ',x));
  await b.close();
})();
