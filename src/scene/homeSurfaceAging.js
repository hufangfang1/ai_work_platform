import * as T from 'three'

// All marks are bounded by the surface and rejected at openings. One mesh
// combines limewash loss, fine cracks and rain runs without covering glazing.
export function createSurfaceAging(width,height,{seed=2015,openings=[],strength=1}={}){
  if(![width,height].every(n=>Number.isFinite(n)&&n>0))throw new RangeError('Positive surface dimensions required');
  let state=seed>>>0;const random=()=>{state=(Math.imul(state,1664525)+1013904223)>>>0;return state/4294967296};
  const positions=[],colors=[],indices=[];
  const shades=['#9a9584','#b0a38e','#8c8b7d','#c0b7a2'].map(c=>new T.Color(c));
  function clear(x,y,rx,ry){return x-rx>=-width/2&&x+rx<=width/2&&y-ry>=0&&y+ry<=height&&!openings.some(o=>x+rx>o.left-.025&&x-rx<o.right+.025&&y+ry>o.bottom-.025&&y-ry<o.top+.025)}
  function patch(x,y,rx,ry,alpha,color,feather=.15){
    if(!clear(x,y,rx,ry))return;
    const start=positions.length/3,n=9;
    const vertex=(px,py,a)=>{positions.push(px,py,0);colors.push(color.r,color.g,color.b,a*strength)};
    vertex(x,y,alpha);
    for(let i=0;i<n;i++){
      const a=i/n*Math.PI*2,r=.62+random()*.38,dx=Math.cos(a)*rx*r,dy=Math.sin(a)*ry*r;
      vertex(x+dx*(1-feather),y+dy*(1-feather),alpha);vertex(x+dx,y+dy,0);
    }
    for(let i=0;i<n;i++){const a=start+1+i*2,b=start+1+(i+1)%n*2;indices.push(start,a,b,a,a+1,b+1,a,b+1,b)}
  }
  for(let i=0;i<Math.ceil(width*height*23);i++){
    const x=(random()-.5)*width,y=random()*height,large=random()<.11;
    const foot=1-Math.min(1,y/.8);
    patch(x,y,large?.045+random()*.11:.004+random()*.024,large?.025+random()*.055:.003+random()*.015,.12+random()*.25+foot*.13,shades[i%4]);
  }
  // Local rain streaks beneath sill ends rather than a uniform dirty stripe.
  for(const o of openings)for(const x of [o.left+.035,o.right-.035]){
    for(let i=0;i<5;i++)patch(x+(random()-.5)*.09,o.bottom-.10-i*.085,.012+random()*.018,.07+random()*.05,.045+random()*.07,shades[2],.45);
  }
  for(let i=0;i<Math.ceil(width*1.1);i++){
    let x=(random()-.5)*width,y=.25+random()*(height-.25);
    for(let j=0;j<5;j++){
      const nx=x+(random()-.5)*.04,ny=y-.025-random()*.045;
      if(clear((x+nx)/2,(y+ny)/2,Math.abs(nx-x)/2+.0015,Math.abs(ny-y)/2)){
        const start=positions.length/3,c=shades[2];
        positions.push(x-.0008,y,0,x+.0008,y,0,nx+.0008,ny,0,nx-.0008,ny,0);
        for(let k=0;k<4;k++)colors.push(c.r,c.g,c.b,.3*strength);
        indices.push(start,start+2,start+1,start,start+3,start+2);
      }x=nx;y=ny;
    }
  }
  const geometry=new T.BufferGeometry();geometry.setAttribute('position',new T.Float32BufferAttribute(positions,3));geometry.setAttribute('color',new T.Float32BufferAttribute(colors,4));geometry.setIndex(indices);geometry.computeVertexNormals();geometry.computeBoundingBox();return geometry;
}

export function createWornVarnishTexture(){
  const canvas=document.createElement('canvas');canvas.width=512;canvas.height=1024;
  const c=canvas.getContext('2d');let seed=1979;const random=()=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed/4294967296};
  c.fillStyle='#654032';c.fillRect(0,0,512,1024);
  for(let i=0;i<420;i++){
    const x=random()*512,y=random()*1024;
    c.strokeStyle=i%3?'rgba(29,23,17,.13)':'rgba(182,131,86,.12)';c.lineWidth=.3+random()*1.5;
    c.beginPath();c.moveTo(x,y);c.bezierCurveTo(x+random()*8,y+40,x-4,y+120,x+2,y+200);c.stroke();
  }
  for(let i=0;i<180;i++){
    const x=random()*512,y=random()*1024,w=1+random()*5,h=2+random()*20;
    c.fillStyle=i%3?'rgba(171,137,95,.36)':'rgba(191,159,112,.5)';
    c.beginPath();c.moveTo(x,y);c.lineTo(x+w,y-h*.12);c.lineTo(x+w*.6,y+h);c.lineTo(x-w*.3,y+h*.6);c.closePath();c.fill();
  }
  const texture=new T.CanvasTexture(canvas);texture.colorSpace=T.SRGBColorSpace;texture.wrapS=texture.wrapT=T.RepeatWrapping;return texture;
}
