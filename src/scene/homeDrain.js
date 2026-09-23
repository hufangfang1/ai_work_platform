import * as T from 'three'

// Local -Z points out into the yard. Open bores pass through the concrete rim.
export function createDrainedRim(length,height,depth,count){
  const shape=new T.Shape();
  shape.moveTo(-length/2,-height/2);shape.lineTo(length/2,-height/2);
  shape.lineTo(length/2,height/2);shape.lineTo(-length/2,height/2);shape.closePath();
  const centers=Array.from({length:count},(_,i)=>-length/2+length*(i+1)/(count+1));
  const y=-height/2+.09;
  for(const x of centers){const hole=new T.Path();hole.absarc(x,y,.087,0,Math.PI*2,true);shape.holes.push(hole)}
  const geometry=new T.ExtrudeGeometry(shape,{depth,bevelEnabled:false,curveSegments:24});
  geometry.translate(0,0,-depth/2);geometry.computeBoundingBox();
  return {geometry,centers,y};
}

export function createSpoutGeometry(){
  const section=new T.Shape();section.absarc(0,0,.08,0,Math.PI*2,false);
  const bore=new T.Path();bore.absarc(0,0,.072,0,Math.PI*2,true);section.holes.push(bore);
  const geometry=new T.ExtrudeGeometry(section,{depth:.41,bevelEnabled:false,curveSegments:24});
  // Bevel the open mouth from above: the upper lip recedes and the lower
  // lip forms the leading tip. Keep both skins and the annular cut face aligned.
  const positions=geometry.attributes.position;
  for(let i=0;i<positions.count;i++){
    const z=positions.getZ(i);
    positions.setZ(i,z<.001?-.24+.75*positions.getY(i):z-.24);
  }
  geometry.rotateX(-.035);geometry.computeVertexNormals();geometry.computeBoundingBox();
  return geometry;
}
