import * as T from 'three'
import {houseSideX,houseWestX,westBoundary,westWallX,gateX,gateZ,gatePortico,gateEastPillarX,gateWestPillarX,eastWall,sideWallHeight,frontWallHeight,shedPlacement,shedRoof,addedRooms,housePlacement,corridorCanopy} from './homeLayout.js'

// Static, simplified blockers only. The bake is done once; walking the scene
// does not raycast or add another render pass. This is AO, not full GI.
export function courtyardOccluders(){
  const s=shedPlacement,c=corridorCanopy,h=housePlacement;
  return [
    [houseSideX,0,7.7,houseWestX,5,14],
    ...addedRooms.map(({bounds:[a,b,c,d]})=>[a,0,c,b,5,d]),
    [-6.475,0,eastWall.startZ,-6.125,sideWallHeight,eastWall.endZ],[westWallX-westBoundary.thickness/2,0,westBoundary.startZ,westWallX+westBoundary.thickness/2,sideWallHeight,westBoundary.endZ],
    [-6.475,0,gateZ-.175,gateEastPillarX-gatePortico.pillarWidth/2,frontWallHeight,gateZ+.175],
    [gateWestPillarX+gatePortico.pillarWidth/2,0,gateZ-.175,westWallX,frontWallHeight,gateZ+.175],
    [gateEastPillarX-gatePortico.pillarWidth/2,0,gatePortico.frontZ,gateEastPillarX+gatePortico.pillarWidth/2,3.9,gatePortico.backZ],[gateWestPillarX-gatePortico.pillarWidth/2,0,gatePortico.frontZ,gateWestPillarX+gatePortico.pillarWidth/2,3.9,gatePortico.backZ],
    [gateX-gatePortico.outerWidth/2,3.605,gatePortico.frontZ,gateX+gatePortico.outerWidth/2,3.955,gatePortico.backZ],
    [gateX-gatePortico.clearWidth/2,0,gateZ+gatePortico.leafLocalZ,gateX-gatePortico.innerClearWidth/2,gatePortico.innerTopY,gateZ+gatePortico.leafLocalZ+.32],
    [gateX+gatePortico.innerClearWidth/2,0,gateZ+gatePortico.leafLocalZ,gateX+gatePortico.clearWidth/2,gatePortico.innerTopY,gateZ+gatePortico.leafLocalZ+.32],
    [gateX-gatePortico.clearWidth/2,gatePortico.innerTopY,gateZ+gatePortico.leafLocalZ,gateX+gatePortico.clearWidth/2,3.605,gateZ+gatePortico.leafLocalZ+.32],
    ...gateLeafOccluders(),
    [s.position[0]-s.width/2,s.roofY-.07,shedRoof.frontZ,s.position[0]+s.width/2,s.roofY+.07,shedRoof.backZ],
    [h.position[0]-(c.centerX+c.width/2)*h.scaleX,c.baseY,h.position[2]-c.centerZ-c.depth/2,h.position[0]-(c.centerX-c.width/2)*h.scaleX,c.baseY+c.thickness,h.position[2]-c.centerZ+c.depth/2],
    [s.car[0]-.77,.38,s.car[2]-1.37,s.car[0]+.77,1.53,s.car[2]+1.37],
    [-5.7,.57,.78,-3.4,2.58,2.42],
    [s.sink[0]-.4,0,s.sink[2]-.6,s.sink[0]+.4,1.1,s.sink[2]+.6],
  ];
}

// Narrow slabs approximate both hinged leaves without treating their entire
// combined bounding rectangle as one solid shadow blocker.
export function gateLeafOccluders(){
  const boxes=[];
  for(const side of [-1,1]){
    const angle=side===-1?.1:-.04,c=Math.cos(angle),s=Math.sin(angle),hingeX=side*gatePortico.innerClearWidth/2;
    for(let i=0;i<8;i++){
      const xs=[],zs=[];
      for(const x of [-side*i*gatePortico.leafWidth/8,-side*(i+1)*gatePortico.leafWidth/8])for(const z of [-.06,.06]){
        xs.push(gateX+hingeX+x*c+z*s);zs.push(gateZ+gatePortico.leafLocalZ-x*s+z*c);
      }
      boxes.push([Math.min(...xs),.035,Math.min(...zs),Math.max(...xs),gatePortico.innerTopY-.035,Math.max(...zs)]);
    }
  }
  return boxes;
}

export function rayBoxDistance(origin,direction,box,maxDistance=5){
  let near=0,far=maxDistance;
  for(let axis=0;axis<3;axis++){
    const d=direction[axis],p=origin[axis],lo=box[axis],hi=box[axis+3];
    if(Math.abs(d)<1e-8){if(p<lo||p>hi)return Infinity;continue}
    let a=(lo-p)/d,b=(hi-p)/d;if(a>b)[a,b]=[b,a];
    near=Math.max(near,a);far=Math.min(far,b);if(near>far)return Infinity;
  }
  return far<=.0001?Infinity:near;
}

const skySamples=Array.from({length:40},(_,i)=>{
  const u=(i+.5)/40,phi=i*2.399963229728653;
  return [Math.sqrt(u)*Math.cos(phi),Math.sqrt(1-u),Math.sqrt(u)*Math.sin(phi)];
});

export function skyVisibility(point,boxes){
  let blocked=0;
  for(const ray of skySamples){
    let nearest=5;
    for(const box of boxes)nearest=Math.min(nearest,rayBoxDistance(point,ray,box));
    // Nearby creases darken more than a distant wall; retain indirect bounce.
    blocked+=nearest<5?.85*Math.exp(-nearest/4):0;
  }
  return Math.max(.20,1-blocked/skySamples.length);
}

export function bakeGroundOcclusion(bounds,boxes=courtyardOccluders(),size=128,sampleHeight=.025){
  const [x0,x1,z0,z1]=bounds,data=new Uint8Array(size*size);
  for(let y=0;y<size;y++)for(let x=0;x<size;x++){
    const point=[x0+(x+.5)/size*(x1-x0),sampleHeight,z1-(y+.5)/size*(z1-z0)];
    data[y*size+x]=Math.round(skyVisibility(point,boxes)*255);
  }
  const texture=new T.DataTexture(data,size,size,T.RedFormat);
  texture.colorSpace=T.NoColorSpace;texture.channel=1;texture.minFilter=texture.magFilter=T.LinearFilter;
  texture.needsUpdate=true;return texture;
}
