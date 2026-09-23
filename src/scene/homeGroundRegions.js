import * as T from 'three'

// A single strip follows the owner's irregular concrete/earth boundary.
// Both UV sets use the full courtyard footprint so the dirt keeps the same
// metre-scale tiling and baked sky-occlusion registration as the concrete.
export function createYardSoilGeometry(boundary,{frontZ,backZ,minX,maxX,y=.003}={}){
  if(!Array.isArray(boundary)||boundary.length<2||![frontZ,backZ,minX,maxX,y].every(Number.isFinite)||backZ<=frontZ||maxX<=minX){
    throw new RangeError('Valid courtyard bounds and at least two boundary points are required')
  }
  if(boundary[0][0]!==frontZ||boundary.at(-1)[0]!==backZ||boundary.some(([z,x],i)=>
    !Number.isFinite(x)||!Number.isFinite(z)||x<=minX||x>=maxX||(i>0&&z<=boundary[i-1][0])
  ))throw new RangeError('Boundary must span the courtyard with increasing Z and interior X')
  const position=[],uv=[],uv1=[],index=[]
  for(const [z,edgeX] of boundary){
    for(const x of [minX,edgeX]){
      position.push(x,y,z)
      uv.push((x-minX)/(maxX-minX),(backZ-z)/(backZ-frontZ))
      uv1.push((x-minX)/(maxX-minX),(backZ-z)/(backZ-frontZ))
    }
  }
  for(let i=0;i<boundary.length-1;i++){
    const n=i*2
    index.push(n,n+2,n+1,n+1,n+2,n+3)
  }
  const geometry=new T.BufferGeometry()
  geometry.setAttribute('position',new T.Float32BufferAttribute(position,3))
  geometry.setAttribute('uv',new T.Float32BufferAttribute(uv,2))
  geometry.setAttribute('uv1',new T.Float32BufferAttribute(uv1,2))
  geometry.setIndex(index)
  geometry.computeVertexNormals()
  geometry.computeBoundingBox()
  return geometry
}

// Shallow remnants and chipped transitions, distributed around the established
// boundary. Their placement is inferred; no surveyed paving plan is implied.
export function createGroundRemnants(boundary,seed=2015){
  let state=seed>>>0;const rand=()=>{state=(Math.imul(state,1664525)+1013904223)>>>0;return state/4294967296};
  const positions=[],colors=[],indices=[];
  function patch(x,z,rx,rz,color,opacity){
    const start=positions.length/3,n=11,c=new T.Color(color);
    positions.push(x,.006,z);colors.push(c.r,c.g,c.b,opacity);
    for(let i=0;i<n;i++){
      const a=i/n*Math.PI*2,r=.66+rand()*.34,dx=Math.cos(a)*rx*r,dz=Math.sin(a)*rz*r;
      for(const [scale,alpha] of [[.88,opacity],[1,0]]){positions.push(x+dx*scale,.006,z+dz*scale);colors.push(c.r,c.g,c.b,alpha)}
    }
    for(let i=0;i<n;i++){const a=start+1+i*2,b=start+1+(i+1)%n*2;indices.push(start,b,a,a,b+1,a+1,a,b,b+1)}
  }
  for(let i=0;i<boundary.length-1;i++){
    const [z0,x0]=boundary[i],[z1,x1]=boundary[i+1];
    for(let j=0;j<Math.ceil((z1-z0)*9);j++){
      const t=rand(),z=z0+(z1-z0)*t,x=x0+(x1-x0)*t;
      patch(x+.025+(rand()-.5)*.10,z,.035+rand()*.13,.035+rand()*.10,'#847660',.8);
    }
  }
  for(const [x,z,rx,rz] of [[-3.6,-2.2,.65,.4],[-4.9,-.7,.4,.65],[-3.1,.1,.7,.31],[-2.55,1.8,.48,.32],[-4.9,3.3,.35,.7],[-3.9,4.7,.6,.3]]){
    patch(x,z,rx,rz,'#817966',.56);
  }
  const geometry=new T.BufferGeometry();geometry.setAttribute('position',new T.Float32BufferAttribute(positions,3));geometry.setAttribute('color',new T.Float32BufferAttribute(colors,4));geometry.setIndex(indices);geometry.computeVertexNormals();geometry.computeBoundingBox();return geometry;
}

// Photo 01 shows exposed aggregate islands in the poured yard; photos 02–04
// show much larger worn areas near the vehicles. These are local patches,
// never repeated inside the cement albedo. Positions are inferred from views.
export function createYardWearGeometry(boundary,{frontZ,backZ,minX,maxX},seed=21915){
  let state=seed>>>0;const rand=()=>{state=(Math.imul(state,1664525)+1013904223)>>>0;return state/4294967296};
  const positions=[],colors=[],uv=[],indices=[];
  const edgeAt=z=>{let i=1;while(i<boundary.length-1&&boundary[i][0]<z)i++;const [a,x]=boundary[i-1],[b,y]=boundary[i];return x+(y-x)*(z-a)/(b-a)};
  function vertex(x,z,alpha,shade){
    positions.push(x,.008,z);colors.push(shade,shade,shade,alpha);
    uv.push((x-minX)/(maxX-minX),(backZ-z)/(backZ-frontZ));
  }
  function patch(x,z,rx,rz,opacity){
    const start=positions.length/3,n=23;vertex(x,z,opacity,.93);
    for(let i=0;i<n;i++){
      const a=i/n*Math.PI*2,r=.65+rand()*.35,dx=Math.cos(a)*rx*r,dz=Math.sin(a)*rz*r;
      vertex(x+dx*.89,z+dz*.89,opacity*(.83+rand()*.17),.85+rand()*.15);
      vertex(x+dx,z+dz,0,1);
    }
    for(let i=0;i<n;i++){const a=start+1+i*2,b=start+1+(i+1)%n*2;indices.push(start,b,a,a,b,b+1,a,b+1,a+1)}
  }
  // Broad but discontinuous wear through the central yard, with the entrance
  // and raised veranda kept clear. Smaller exposed pits occur near the steps.
  for(let i=0;i<18;i++){
    const z=-1.9+rand()*6.6,edge=edgeAt(z),x=edge+.18+rand()*(4.0-edge);
    const large=i%5===0;patch(x,z,large?.28+rand()*.32:.07+rand()*.2,large?.22+rand()*.3:.07+rand()*.2,.25+rand()*.28);
  }
  for(const [x,z,rx,rz] of [[-.5,4.8,.48,.29],[1.25,4.5,.56,.34],[3.2,4.8,.30,.24],[2.7,-1.7,.7,.30]])patch(x,z,rx,rz,.86);
  const geometry=new T.BufferGeometry();geometry.setAttribute('position',new T.Float32BufferAttribute(positions,3));geometry.setAttribute('color',new T.Float32BufferAttribute(colors,4));geometry.setAttribute('uv',new T.Float32BufferAttribute(uv,2));geometry.setAttribute('uv1',geometry.attributes.uv.clone());geometry.setIndex(indices);geometry.computeVertexNormals();geometry.computeBoundingBox();return geometry;
}
