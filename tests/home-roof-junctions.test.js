import test from 'node:test'
import assert from 'node:assert/strict'
import * as T from 'three'
import {createGableRoofDeck,createGableWallClosure} from '../src/scene/homeEaves.js'
import {createFacadeGeometry} from '../src/scene/homeFacade.js'
import {corrugatedSheetGeometry} from '../src/scene/homeDetailGeometry.js'
import {
  housePlacement,houseLocalMinX,houseLocalMaxX,houseSideX,houseWestX,houseDoorLocalX,houseWindows,mainRoof,
  frontPorch,corridorCanopy,corridorFrontWorldZ,westWing,westCorridorCanopy,westGroundCorridor,westRoof,shedPlacement,shedRoof,shedClerestory,shedSideBrick,shedStepWall,shedBeamY,eastWall,sideWallHeight,addedRooms,canWalk,
} from '../src/scene/homeLayout.js'

const near=(actual,expected,tolerance=1e-5)=>assert.ok(Math.abs(actual-expected)<=tolerance,`${actual} should equal ${expected}`)
const transform=({position,rotationY,scaleX=1})=>new T.Matrix4().compose(
  new T.Vector3(...position),new T.Quaternion().setFromAxisAngle(new T.Vector3(0,1,0),rotationY),new T.Vector3(scaleX,1,1),
)
const houseMatrix=transform(housePlacement),wingMatrix=transform(westWing)
const canopyBounds=new T.Box3().setFromPoints([-1,1].flatMap(x=>[-1,1].map(z=>new T.Vector3(
  corridorCanopy.centerX+x*corridorCanopy.width/2,
  corridorCanopy.baseY,
  corridorCanopy.centerZ+z*corridorCanopy.depth/2,
).applyMatrix4(houseMatrix))))
const canopyTop=corridorCanopy.baseY+corridorCanopy.thickness

test('both roofs and eave platforms rise one metre while the wall tops still meet them',()=>{
  near(mainRoof.eaveY,5.1)
  near(corridorCanopy.baseY,4.9)
  near(westCorridorCanopy.baseY,corridorCanopy.baseY)
  near(westRoof.eaveY,canopyTop)
  near(westWing.wallHeight,5)
  const facade=createFacadeGeometry({minX:houseLocalMinX,maxX:houseLocalMaxX,height:5,doorX:houseDoorLocalX,windows:houseWindows})
  near(facade.boundingBox.max.y,5)
  facade.dispose()
})

test('corridor canopy ends align with both house sides in world coordinates',()=>{
  const houseSides=[houseLocalMinX,houseLocalMaxX].map(x=>new T.Vector3(x,0,0).applyMatrix4(houseMatrix).x).sort((a,b)=>a-b)
  near(canopyBounds.min.x,houseSides[0]);near(canopyBounds.max.x,houseSides[1])
  near(canopyBounds.min.x,houseSideX);near(canopyBounds.max.x,houseWestX)
  near(canopyBounds.min.z,6.1);near(canopyBounds.max.z,7.9)
})

test('west wing flat eave projects half the corridor width and meets the main slab',()=>{
  const c=westCorridorCanopy
  const bounds=new T.Box3().setFromPoints([-1,1].flatMap(x=>[-1,1].map(z=>new T.Vector3(
    c.centerX+x*c.width/2,c.baseY,c.centerZ+z*c.depth/2,
  ).applyMatrix4(wingMatrix))))
  near(c.depth,corridorCanopy.depth/2)
  near(c.baseY,corridorCanopy.baseY)
  near(c.thickness,corridorCanopy.thickness)
  near(bounds.min.x,westWing.position[0]-westWing.depth/2-c.depth)
  near(bounds.max.x,westWing.position[0]-westWing.depth/2)
  near(bounds.min.z,addedRooms[0].bounds[2])
  near(bounds.max.z,canopyBounds.min.z)
})

test('raised west corridor matches the main step height and joins its front edge',()=>{
  const c=westGroundCorridor
  const bounds=new T.Box3().setFromPoints([-1,1].flatMap(x=>[-1,1].map(z=>new T.Vector3(
    c.centerX+x*c.width/2,0,c.centerZ+z*c.depth/2,
  ).applyMatrix4(wingMatrix))))
  near(c.height,frontPorch.height)
  near(c.depth,westCorridorCanopy.depth)
  near(bounds.min.x,westWing.position[0]-westWing.depth/2-c.depth)
  near(bounds.max.x,westWing.position[0]-westWing.depth/2)
  near(bounds.min.z,addedRooms[0].bounds[2])
  near(bounds.max.z,canopyBounds.min.z)
})

test('west roof has two real slopes and ends exactly against the canopy front',()=>{
  const deck=createGableRoofDeck(westRoof.width,westRoof.depth,{rise:westRoof.rise,thickness:westRoof.thickness})
  const matrix=wingMatrix.clone().multiply(new T.Matrix4().makeTranslation(westRoof.centerX,westRoof.eaveY,westRoof.centerZ))
  deck.applyMatrix4(matrix);deck.computeBoundingBox()
  const bounds=deck.boundingBox,position=deck.attributes.position,normal=deck.attributes.normal
  near(bounds.max.z,canopyBounds.min.z);near(bounds.min.z,addedRooms[0].bounds[2]-.175)
  near(bounds.min.x,5.9);near(bounds.max.x,9.5)
  near(bounds.max.y,westRoof.eaveY+westRoof.rise)
  near(bounds.min.y,westRoof.eaveY-westRoof.thickness)
  let eastSlope=false,westSlope=false,ridgeVertices=0
  for(let i=0;i<position.count;i++){
    assert.ok(position.getZ(i)<=canopyBounds.min.z+1e-5,'west roof must not project into the corridor slab')
    if(normal.getY(i)>.5){
      eastSlope ||= normal.getX(i)<-.1
      westSlope ||= normal.getX(i)>.1
    }
    if(Math.abs(position.getY(i)-bounds.max.y)<1e-5){near(position.getX(i),westWing.position[0]);ridgeVertices++}
  }
  assert.ok(eastSlope&&westSlope&&ridgeVertices>=2,'roof must have opposing pitches meeting over the wing centre')
  deck.dispose()
})

test('west gable infill seals the original wall top and stops at the canopy joint',()=>{
  const closure=createGableWallClosure({
    minX:westRoof.wallMinX,maxX:westRoof.wallMaxX,
    backZ:-westWing.depth/2,frontZ:westWing.depth/2,wallTop:westWing.wallHeight,
    eaveY:westRoof.eaveY,ridgeZ:westRoof.centerZ,depth:westRoof.depth,
    rise:westRoof.rise,roofThickness:westRoof.thickness,
  })
  const position=closure.attributes.position,normal=closure.attributes.normal,edges=new Map()
  const key=i=>[position.getX(i),position.getY(i),position.getZ(i)].map(n=>n.toFixed(5)).join(',')
  for(let i=0;i<position.count;i++){
    const x=position.getX(i),y=position.getY(i),z=position.getZ(i)
    const underside=westRoof.eaveY+westRoof.rise*(1-Math.abs(z-westRoof.centerZ)/(westRoof.depth/2))-westRoof.thickness
    assert.ok(y>=westWing.wallHeight-1e-5&&y<=underside+1e-5)
    assert.ok(x>=westRoof.wallMinX-1e-5&&x<=westRoof.wallMaxX+1e-5)
    near(Math.hypot(normal.getX(i),normal.getY(i),normal.getZ(i)),1)
  }
  // The infill is a solid, not two unsealed triangular billboards.
  for(let i=0;i<position.count;i+=3){
    const a=new T.Vector3().fromBufferAttribute(position,i),b=new T.Vector3().fromBufferAttribute(position,i+1),c=new T.Vector3().fromBufferAttribute(position,i+2)
    assert.ok(b.sub(a).cross(c.sub(a)).length()>1e-8,'no collapsed side faces at wall/roof contact')
    for(let j=0;j<3;j++){
      const edge=[key(i+j),key(i+(j+1)%3)].sort().join('|')
      edges.set(edge,(edges.get(edge)||0)+1)
    }
  }
  for(const count of edges.values())assert.equal(count,2)
  closure.applyMatrix4(wingMatrix);closure.computeBoundingBox()
  near(closure.boundingBox.max.z,canopyBounds.min.z)
  near(closure.boundingBox.min.x,6.1);near(closure.boundingBox.max.x,9.3)
  near(closure.boundingBox.min.y,westWing.wallHeight)
  closure.dispose()
})

test('low shelter bears on the wall and ends flush with the washing corridor',()=>{
  const sheet=corrugatedSheetGeometry(shedRoof.width,shedRoof.depth,.15,shedRoof.pitch)
  sheet.translate(
    shedPlacement.position[0]+shedRoof.centerLocalX,
    shedPlacement.roofY+shedRoof.centerLocalZ*shedRoof.pitch,
    shedPlacement.position[2]+shedRoof.centerLocalZ,
  )
  sheet.computeBoundingBox()
  near(sheet.boundingBox.min.z,shedRoof.frontZ);near(sheet.boundingBox.max.z,shedRoof.backZ)
  near(sheet.boundingBox.min.x,eastWall.centerX)
  near(sheet.boundingBox.max.x,shedPlacement.position[0]+shedPlacement.width/2)
  near(shedRoof.backZ,corridorFrontWorldZ)
  assert.ok(shedRoof.backZ<shedPlacement.position[2]+shedPlacement.depth/2,'low sheet does not enter washing corridor')
  near(shedPlacement.roofY,sideWallHeight+.1)
  assert.ok(canopyTop-sheet.boundingBox.max.y>2,'shed stays below the separate house platform')
  assert.ok(Math.abs(shedBeamY(-3)-shedPlacement.beamThickness/2-sideWallHeight)<.02,'cross-beam bears on wall top')
  const position=sheet.attributes.position
  let wallCrest=-Infinity,wallValley=Infinity
  for(let i=0;i<position.count;i++){
    if(Math.abs(position.getX(i)-eastWall.centerX)<1e-5){
      wallCrest=Math.max(wallCrest,position.getY(i));wallValley=Math.min(wallValley,position.getY(i))
    }
  }
  near(wallCrest,wallValley)
  assert.ok(wallCrest>sideWallHeight,'sheet sits just above its wall-supported beam')
  near(shedClerestory.z,shedRoof.backZ)
  near(shedClerestory.bottomY,sheet.boundingBox.max.y)
  near(shedClerestory.topY,corridorCanopy.baseY)
  assert.ok(shedClerestory.topY-shedClerestory.bottomY>1.8,'glass fills the gap to the projecting eave')
  sheet.dispose()
})

test('brick closes the outside flank below the main eave without replacing the front glass',()=>{
  near(shedSideBrick.x,eastWall.centerX)
  near(shedSideBrick.thickness,eastWall.thickness)
  near(shedSideBrick.bottomY,sideWallHeight)
  near(shedSideBrick.topY,corridorCanopy.baseY)
  near(shedSideBrick.startZ,shedClerestory.z)
  near(shedSideBrick.endZ,corridorFrontWorldZ+corridorCanopy.depth)
})

test('cement wall rises from the house step to the low shed roof',()=>{
  near(shedStepWall.minX,eastWall.centerX)
  near(shedStepWall.maxX,shedPlacement.position[0]+shedRoof.centerLocalX+shedRoof.width/2)
  near(shedStepWall.z,corridorFrontWorldZ)
  near(shedStepWall.z,shedClerestory.z)
  near(shedStepWall.bottomY,frontPorch.height)
  near(shedStepWall.topY,shedBeamY(shedStepWall.z-shedPlacement.position[2])+shedPlacement.beamThickness/2)
  assert.ok(shedStepWall.topY<shedPlacement.roofY)
  assert.equal(canWalk(-4.5,shedStepWall.z),false)
  assert.equal(canWalk(-2,shedStepWall.z),true)
})
