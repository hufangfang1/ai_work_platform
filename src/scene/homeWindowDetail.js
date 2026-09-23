import * as T from 'three'

export function createGlazingGeometry(width,height,seed=0){
  if(![width,height].every(v=>Number.isFinite(v)&&v>0))throw new RangeError('Glazing dimensions must be positive');
  const geometry=new T.PlaneGeometry(width,height,4,6),p=geometry.attributes.position,colors=[];
  for(let i=0;i<p.count;i++){
    const x=p.getX(i),y=p.getY(i),u=x/width+.5,v=y/height+.5;
    // Small variation in old glass, never a visibly rippled plastic sheet.
    p.setZ(i,Math.sin(u*Math.PI)*Math.sin(v*Math.PI)*Math.sin(u*7+v*4+seed)*.0006);
    const edge=Math.max(0,Math.min(u,1-u,v,1-v)),dust=1-Math.min(1,edge*11);
    colors.push(1,1,1,.65+dust*.35);
  }
  geometry.setAttribute('color',new T.Float32BufferAttribute(colors,4));
  geometry.computeVertexNormals();return geometry;
}

export function createCurtainGeometry(width,height,phase=0){
  if(![width,height].every(v=>Number.isFinite(v)&&v>0))throw new RangeError('Curtain dimensions must be positive');
  const geometry=new T.PlaneGeometry(width,height,Math.ceil(width*32),20),p=geometry.attributes.position;
  for(let i=0;i<p.count;i++){
    const x=p.getX(i),y=p.getY(i),bottom=.5-y/height;
    // A shallow cloth surface entirely behind the glass. No invented room.
    p.setZ(i,(Math.sin(x*34+phase)+Math.sin(x*16+phase*.7)*.35)*.0038);
    p.setY(i,y+Math.sin(x*17+phase)*.008*bottom**6);
  }
  geometry.computeVertexNormals();return geometry;
}

export function createPaperGeometry(width,height,seed=1){
  const geometry=new T.PlaneGeometry(width,height,8,12),p=geometry.attributes.position,colors=[];
  for(let i=0;i<p.count;i++){
    const u=p.getX(i)/width+.5,v=p.getY(i)/height+.5,edge=Math.min(u,1-u,v,1-v);
    const wear=1-Math.min(1,edge*18);
    p.setZ(i,wear*.0008*(1+Math.sin(u*21+v*13+seed)));
    const shade=.83+.08*Math.sin(u*6+seed)*Math.sin(v*11)+wear*.04;
    colors.push(shade,shade,shade*.985);
  }
  geometry.setAttribute('color',new T.Float32BufferAttribute(colors,3));geometry.computeVertexNormals();return geometry;
}
