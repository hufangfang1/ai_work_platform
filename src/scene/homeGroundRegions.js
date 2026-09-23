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
