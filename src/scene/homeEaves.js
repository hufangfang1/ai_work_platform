import * as T from 'three'

function extrudeSection(points,width,unit){
  const shape=new T.Shape();points.forEach(([z,y],i)=>shape[i?'lineTo':'moveTo'](-z,y));shape.closePath();
  const geo=new T.ExtrudeGeometry(shape,{depth:width,bevelEnabled:false,steps:1,curveSegments:1});geo.rotateY(Math.PI/2);geo.translate(-width/2,0,0);
  const p=geo.attributes.position,n=geo.attributes.normal,uv=geo.attributes.uv;
  for(let i=0;i<p.count;i++){
    const side=Math.abs(n.getX(i))>.5;
    uv.setXY(i,(side?p.getZ(i):p.getX(i))/unit,(Math.abs(n.getY(i))>.5?p.getZ(i):p.getY(i))/unit);
  }
  geo.computeBoundingBox();return geo;
}

/** Owner-confirmed inverted-V roof. The ridge follows X; both slopes have
 * real thickness, with no double-sided sheets hiding open tile undersides. */
export function createGableRoofDeck(width,depth,{rise=1.2,thickness=.1}={}){
  if(![width,depth,rise,thickness].every(n=>Number.isFinite(n)&&n>0)||thickness>=rise)throw new RangeError('Invalid gabled roof dimensions');
  return extrudeSection([[-depth/2,0],[0,rise],[depth/2,0],[depth/2,-thickness],[0,rise-thickness],[-depth/2,-thickness]],width,1);
}

/** Gable infill stays within the original walls, sealing the space below
 * the now confirmed two-pitch roof. Coordinates use the house's frame. */
export function createGableWallClosure({minX,maxX,backZ=-6.1,frontZ=.15,wallTop=4,eaveY=4.1,ridgeZ=-2.85,depth=6.8,rise=1.2,roofThickness=.1}={}){
  if(![minX,maxX,backZ,frontZ,wallTop,eaveY,ridgeZ,depth,rise,roofThickness].every(Number.isFinite)||maxX<=minX||frontZ<=backZ||rise<=0||depth<=0||roofThickness<=0||backZ>=ridgeZ||frontZ<=ridgeZ)throw new RangeError('Invalid gable wall dimensions');
  const under=z=>eaveY+rise*(1-Math.abs(z-ridgeZ)/(depth/2))-roofThickness;
  if(Math.min(under(backZ),under(frontZ))<=wallTop)throw new RangeError('Gable roof must clear the wall top');
  const geo=extrudeSection([[backZ,wallTop],[backZ,under(backZ)],[ridgeZ,under(ridgeZ)],[frontZ,under(frontZ)],[frontZ,wallTop]],maxX-minX,4);
  geo.translate((maxX+minX)/2,0,0);return geo;
}

/** Restrained, feathered ambient darkness beneath an overhang, not a cast
 * light shadow. Vertex alpha reaches zero at the lower edge. One draw call. */
export function createEaveShade(width,{height=.19,seed=1}={}){
  if(!Number.isFinite(width)||width<=0||!Number.isFinite(height)||height<=0||!Number.isFinite(seed))throw new RangeError('Invalid eave shade');
  const segments=Math.max(2,Math.ceil(width/.3)),geo=new T.PlaneGeometry(width,height,segments,4),pos=geo.attributes.position,colors=[];
  for(let i=0;i<pos.count;i++){
    const x=pos.getX(i),v=pos.getY(i)/height+.5;
    const alpha=Math.max(0,Math.min(1,v))**2*(.12+.022*Math.sin(x*2.3+seed));
    // Neutral in linear space; this is only contact-darkening of the plaster.
    colors.push(.115,.118,.11,alpha);
  }
  geo.setAttribute('color',new T.Float32BufferAttribute(colors,4));return geo;
}
