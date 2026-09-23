import * as T from 'three'

// Local +Y branch. Smooth bending and irregular growth rings preserve shared
// seam positions, so bark remains closed under grazing light.
export function branchGeometry(length,radius,phase=0){
  const geometry=new T.CylinderGeometry(radius*.48,radius,length,10,8);
  const p=geometry.attributes.position;
  for(let i=0;i<p.count;i++){
    const t=(p.getY(i)+length/2)/length,x=p.getX(i),z=p.getZ(i),angle=Math.atan2(z,x);
    const uneven=1+.10*Math.sin(angle*3+phase+t*2)+.05*Math.cos(angle*5-t*3);
    p.setXYZ(i,x*uneven+Math.sin(t*Math.PI)*length*.035*Math.cos(phase),p.getY(i),z*uneven+Math.sin(t*Math.PI)*length*.035*Math.sin(phase));
  }
  geometry.computeVertexNormals();return geometry;
}

// The bottom edge follows the two wheel arches: the tires no longer sit on
// top of a solid rectangular body. The profile is extruded across car width.
export function carBodyGeometry(){
  const s=new T.Shape();s.moveTo(-1.34,.38);s.lineTo(-1.34,.76);s.quadraticCurveTo(-1.3,.98,-1.06,.98);s.lineTo(1.12,.98);s.quadraticCurveTo(1.38,.94,1.38,.72);s.lineTo(1.38,.38);
  for(const center of [.83,-.86]){
    s.lineTo(center+.35,.38);
    for(let i=0;i<=20;i++){const a=i*Math.PI/20;s.lineTo(center+Math.cos(a)*.35,.33+Math.sin(a)*.35)}
  }
  s.lineTo(-1.34,.38);s.closePath();
  const geometry=new T.ExtrudeGeometry(s,{depth:1.48,bevelEnabled:true,bevelThickness:.045,bevelSize:.035,bevelSegments:3,steps:1,curveSegments:12});
  geometry.translate(0,0,-.74);geometry.rotateY(-Math.PI/2);return geometry;
}

// One continuous sheet: corrugation changes the silhouette and underside too.
// Phase is based on world-sized pitch, not on the number of subdivisions.
export function corrugatedSheetGeometry(width,depth,pitch=.15,lengthSlope=.012){
  const segments=Math.ceil(width/pitch)*12,geometry=new T.PlaneGeometry(width,depth,segments,10);
  const p=geometry.attributes.position,uv=geometry.attributes.uv;
  for(let i=0;i<p.count;i++){
    const x=p.getX(i),z=-p.getY(i);
    p.setXYZ(i,x,Math.cos((x+width/2)/pitch*Math.PI*2)*.026+z*lengthSlope,z);
    uv.setXY(i,(x+width/2)/2,(z+depth/2)/3);
  }
  geometry.computeVertexNormals();return geometry;
}

// Low wall rendering is chipped along its top edge, while the floor boundary
// remains straight. This is weathering, not a change to the building footprint.
export function wornSkirtingGeometry(width,height=.44,phase=0){
  const segments=Math.ceil(width*20),positions=[],uvs=[],indices=[],colors=[];
  for(let i=0;i<=segments;i++){
    const x=i/segments*width-width/2;
    const top=height+.027*Math.sin(x*7+phase)+.018*Math.sin(x*23+phase*2)+.01*Math.cos(x*61);
    positions.push(x,0,0,x,top,0);uvs.push((x+width/2)/4,0,(x+width/2)/4,top/4);
    const mottling=.91+.06*Math.sin(x*4.7+phase)*Math.cos(x*12.3);
    colors.push(mottling*.81,mottling*.83,mottling*.80,mottling,mottling,mottling);
    if(i<segments){const n=i*2;indices.push(n,n+2,n+1,n+1,n+2,n+3)}
  }
  const geometry=new T.BufferGeometry();geometry.setAttribute('position',new T.Float32BufferAttribute(positions,3));geometry.setAttribute('uv',new T.Float32BufferAttribute(uvs,2));geometry.setAttribute('color',new T.Float32BufferAttribute(colors,3));geometry.setIndex(indices);geometry.computeVertexNormals();return geometry;
}
