import * as T from 'three'

// Top remains level beneath the eave. The wall end is wider and deeper;
// the exposed end tapers to a smaller rectangular face.
export function createTaperedEaveBeam(width,depth){
  if(![width,depth].every(n=>Number.isFinite(n)&&n>0))throw new RangeError('Positive beam dimensions required');
  const geometry=new T.BoxGeometry(width,1,depth),p=geometry.attributes.position;
  for(let i=0;i<p.count;i++){
    const t=(p.getZ(i)+depth/2)/depth;
    p.setXYZ(i,p.getX(i)*(1-.28*t),p.getY(i)>0?0:-(.38-.16*t),p.getZ(i));
  }
  geometry.computeVertexNormals();geometry.computeBoundingBox();return geometry;
}

// Procedural surface details traced from the architectural features in the
// February 2015 references. No people or photographic backgrounds are used.
export function createEaveWeatherTexture(){
  const canvas=document.createElement('canvas');canvas.width=1024;canvas.height=256;
  const c=canvas.getContext('2d');let seed=20150219;
  const random=()=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed/4294967296};
  const wash=c.createLinearGradient(0,0,0,256);
  wash.addColorStop(0,'rgba(91,85,73,.24)');wash.addColorStop(.45,'rgba(127,121,106,.09)');wash.addColorStop(1,'rgba(127,121,106,0)');
  c.fillStyle=wash;c.fillRect(0,0,1024,256);
  for(let i=0;i<420;i++){
    const x=random()*1024,y=Math.pow(random(),2.8)*246,r=1+random()*8;
    const fade=Math.pow(1-y/256,1.5);
    c.fillStyle=`rgba(126,121,109,${(.06+random()*.18)*fade})`;
    c.beginPath();
    for(let j=0;j<9;j++){const angle=j/9*Math.PI*2,k=.45+random()*.55;c[j?'lineTo':'moveTo'](x+Math.cos(angle)*r*k*1.8,y+Math.sin(angle)*r*k)}
    c.closePath();c.fill();
  }
  // Broad moisture clouds beneath the slab, with tiny flakes only near the
  // junction. Feathered low-contrast edges avoid a camouflage-like band.
  for(let i=0;i<60;i++){
    const x=random()*1024,y=random()*90,r=20+random()*48;
    const cloud=c.createRadialGradient(x,y,0,x,y,r);
    cloud.addColorStop(0,'rgba(91,86,75,.07)');cloud.addColorStop(1,'rgba(91,86,75,0)');
    c.fillStyle=cloud;c.fillRect(x-r,y-r,r*2,r*2);
  }
  for(let i=0;i<45;i++){
    const x=random()*1024,y=random()*90;
    c.strokeStyle='rgba(109,101,84,.1)';c.lineWidth=1+random()*3;
    c.beginPath();c.moveTo(x,y);c.lineTo(x+random()*4,y+20+random()*100);c.stroke();
  }
  const texture=new T.CanvasTexture(canvas);texture.colorSpace=T.SRGBColorSpace;return texture;
}

export function createBeamTileTexture(){
  const canvas=document.createElement('canvas');canvas.width=canvas.height=128;
  const c=canvas.getContext('2d');c.fillStyle='#e4dfcd';c.fillRect(0,0,128,128);
  c.strokeStyle='#827b6c';c.lineWidth=3;c.strokeRect(4,4,120,120);
  c.fillStyle='#a34535';c.beginPath();c.ellipse(64,57,32,36,0,0,Math.PI*2);c.fill();
  c.strokeStyle='#ccab66';c.lineWidth=2;
  for(const w of [13,25]){c.beginPath();c.ellipse(64,57,w,32,0,0,Math.PI*2);c.stroke()}
  c.fillStyle='#c8a160';c.fillRect(48,18,32,6);c.fillRect(50,91,28,5);
  for(const x of [56,64,72]){c.beginPath();c.moveTo(x,95);c.lineTo(x,116);c.stroke()}
  const texture=new T.CanvasTexture(canvas);texture.colorSpace=T.SRGBColorSpace;return texture;
}

export function createFrostedPatternTexture(){
  const canvas=document.createElement('canvas');canvas.width=256;canvas.height=512;
  const c=canvas.getContext('2d');c.fillStyle='#95aaa3';c.fillRect(0,0,256,512);
  for(let row=0;row<12;row++)for(let col=0;col<5;col++){
    const x=col*57+(row%2)*22-12,y=row*47;
    c.save();c.translate(x,y);c.rotate(Math.sin(row*8+col*3)*.4);
    c.strokeStyle='rgba(58,86,78,.22)';c.lineWidth=1.1;c.beginPath();c.moveTo(0,16);c.quadraticCurveTo(5,0,2,-15);c.stroke();
    c.fillStyle='rgba(66,93,83,.17)';
    for(const [dx,dy,a] of [[-3,3,-.7],[6,-3,.65],[-2,-10,-.6]]){c.beginPath();c.ellipse(dx,dy,5,2,a,0,Math.PI*2);c.fill()}
    c.restore();
  }
  const texture=new T.CanvasTexture(canvas);texture.colorSpace=T.SRGBColorSpace;return texture;
}

export function createPeelingWallSkin(width){
  const shape=new T.Shape();shape.moveTo(-width/2,0);shape.lineTo(width/2,0);
  const steps=Math.ceil(width/.055);
  for(let i=steps;i>=0;i--){const x=-width/2+width*i/steps;shape.lineTo(x,-.49-.045*Math.sin(i*2.7)-.024*Math.sin(i*7.1))}
  shape.closePath();
  const geometry=new T.ExtrudeGeometry(shape,{depth:.022,steps:1,bevelEnabled:false});geometry.computeBoundingBox();return geometry;
}

export function createClearCurtainGeometry(width=1.12,height=1.30){
  const geometry=new T.PlaneGeometry(width,height,32,36),p=geometry.attributes.position;
  for(let i=0;i<p.count;i++){
    const x=p.getX(i),y=p.getY(i),free=(height/2-y)/height;
    p.setZ(i,(Math.sin(x*37+y*9)*.008+Math.sin(x*15-y*4)*.012)*(.2+.8*free));
    p.setY(i,y+Math.sin(x*19)*.012*free);
  }
  geometry.computeVertexNormals();geometry.computeBoundingBox();return geometry;
}
