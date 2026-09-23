import * as T from 'three'
import {addedRooms,eastWall,westBoundary} from './homeLayout.js'

// Feathered ribbons sit flush with the floor. Unlike tubes they have no raised
// silhouette or cylindrical highlight, even when viewed from the step.
export function createGroundCracks(seed=2015){
  let state=seed>>>0;
  const rand=()=>{state=(state*1664525+1013904223)>>>0;return state/4294967296};
  const positions=[],colors=[],indices=[];
  function ribbon(points,width){
    const first=positions.length/3;
    points.forEach((p,i)=>{
      const prev=points[Math.max(0,i-1)],next=points[Math.min(points.length-1,i+1)];
      const dx=next[0]-prev[0],dz=next[1]-prev[1],length=Math.hypot(dx,dz)||1;
      const taper=Math.pow(Math.sin(Math.PI*i/(points.length-1)),.55);
      const radius=width*(.3+.7*taper)*(.75+rand()*.4);
      for(const offset of [-1,-.24,.24,1]){
        positions.push(p[0]-dz/length*radius*offset,.004,p[1]+dx/length*radius*offset);
        colors.push(1,1,1,Math.abs(offset)===1?0:.7*taper);
      }
      if(i<points.length-1)for(let j=0;j<3;j++){
        const n=first+i*4+j;indices.push(n,n+1,n+4,n+1,n+5,n+4);
      }
    });
  }
  for(const [x,z,angle,length] of [[2,-1,.2,1.4],[.6,4.7,1.3,1.05],[4.8,-3,-.6,.85],[-.7,-4.8,.5,1.6]]){
    const points=[];
    for(let i=0;i<=18;i++){
      const along=i/18*length,side=(rand()-.5)*.07+Math.sin(i*.6)*.03;
      points.push([x+Math.sin(angle)*along+Math.cos(angle)*side,z+Math.cos(angle)*along-Math.sin(angle)*side]);
    }
    ribbon(points,.012);
    for(const start of [6,12]){
      const origin=points[start],branch=[];
      for(let i=0;i<=8;i++)branch.push([origin[0]+Math.cos(angle)*i*.035+(rand()-.5)*.023,origin[1]-Math.sin(angle)*i*.035+i*.012]);
      ribbon(branch,.007);
    }
  }
  const geometry=new T.BufferGeometry();
  geometry.setAttribute('position',new T.Float32BufferAttribute(positions,3));
  geometry.setAttribute('color',new T.Float32BufferAttribute(colors,4));
  geometry.setIndex(indices);geometry.computeVertexNormals();geometry.computeBoundingBox();
  return geometry;
}

// Damp discolouration hugs the wall base, not an even row of floating squares.
export function createWallFootWear(){
  const positions=[],colors=[],indices=[];
  for(const [x,sign,z0,z1] of [
    [-6.1,1,eastWall.startZ+.9,7.45],
    [westBoundary.centerX-westBoundary.thickness/2-.035,-1,westBoundary.startZ+.9,westBoundary.endZ-.15],
    [5.97,-1,addedRooms[0].bounds[2]+.05,7.7],
  ]){
    const first=positions.length/3,steps=Math.ceil((z1-z0)*12);
    for(let i=0;i<=steps;i++){
      const z=T.MathUtils.lerp(z0,z1,i/steps),wave=.5+.5*Math.sin(z*3.4)*Math.cos(z*7.9);
      const width=.06+wave*.24;
      positions.push(x,.003,z,x+sign*width,.003,z);
      colors.push(1,1,1,.24+wave*.25,1,1,1,0);
      if(i<steps){const n=first+i*2;indices.push(...(sign>0?[n,n+2,n+1,n+1,n+2,n+3]:[n,n+1,n+2,n+1,n+3,n+2]));}
    }
  }
  const geometry=new T.BufferGeometry();geometry.setAttribute('position',new T.Float32BufferAttribute(positions,3));
  geometry.setAttribute('color',new T.Float32BufferAttribute(colors,4));geometry.setIndex(indices);geometry.computeVertexNormals();
  return geometry;
}
