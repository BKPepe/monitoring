import * as echarts from 'echarts';
const t0 = Date.UTC(2026,0,1), day = 86400000;
// day 3 and 4 have no measurement at all (server sends no row)
const present = [0,1,2,5,6];
const chart = echarts.init(null,null,{ssr:true,renderer:'svg',width:600,height:300});
chart.setOption({
  animation:false,
  xAxis:{type:'time'}, yAxis:{type:'value'},
  series:[
    {name:'__range_floor',type:'line',stack:'bk-range',symbol:'none',lineStyle:{opacity:0},areaStyle:{opacity:0},z:1,
     data: present.map(i=>[t0+i*day, 10+i])},
    {name:'__range_span',type:'line',stack:'bk-range',symbol:'none',lineStyle:{opacity:0},areaStyle:{color:'rgba(0,128,255,0.5)'},z:1,
     data: present.map(i=>[t0+i*day, 20])},
  ],
});
const svg = chart.renderToSVGString();
const polys = [...svg.matchAll(/<path[^>]*d="([^"]{0,400})"/g)].map(m=>m[1]);
console.log('paths drawn:', polys.length);
polys.forEach(p=>console.log('  ', p.slice(0,220)));
