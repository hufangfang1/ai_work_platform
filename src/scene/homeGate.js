import * as T from 'three'
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js'

// Thin folded metal, not a deep block. The very slight panel bow stays below
// 3 mm and vanishes at the perimeter, so hinges and edge rails remain aligned.
export function createIronGatePanel(width=1.16,height=2.925,depth=.048){
  if(![width,height,depth].every(v=>Number.isFinite(v)&&v>0))throw new RangeError('Positive finite gate dimensions required');
  const geometry=new RoundedBoxGeometry(width,height,depth,3,Math.min(.006,depth/4));
  const p=geometry.attributes.position,uv=geometry.attributes.uv,colors=[];
  for(let i=0;i<p.count;i++){
    const x=p.getX(i),y=p.getY(i)+height/2,z=p.getZ(i),u=(x+width/2)/width,v=y/height;
    const bow=Math.sin(u*Math.PI)*Math.sin(v*Math.PI)*Math.sin(v*5.2+u*.9)*.0025;
    p.setXYZ(i,x,y,z+bow);uv.setXY(i,(x+width/2)/1.2,y/1.2);
    const dust=.87+.13*Math.min(1,y/.40);
    colors.push(dust,dust*.996,dust*.988);
  }
  geometry.setAttribute('color',new T.Float32BufferAttribute(colors,3));
  geometry.computeVertexNormals();geometry.computeBoundingBox();
  return geometry;
}

// Preserve a second UV set independent of the existing physical material UVs.
// The same bounds are used for the one-off AO bake on this raised apron.
export function setGroundOcclusionUV(geometry,bounds,offset=[0,0,0]){
  const [x0,x1,z0,z1]=bounds;
  if(!(x1>x0&&z1>z0))throw new RangeError('Invalid ground AO bounds');
  const p=geometry.attributes.position,uv=[];
  for(let i=0;i<p.count;i++)uv.push((p.getX(i)+offset[0]-x0)/(x1-x0),(z1-p.getZ(i)-offset[2])/(z1-z0));
  geometry.setAttribute('uv1',new T.Float32BufferAttribute(uv,2));return geometry;
}
