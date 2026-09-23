import {test} from 'node:test'
import assert from 'node:assert/strict'
import {westFacade,houseWindowCenterY,houseWindowOpeningHeight,sideWallHeight,frontWallHeight} from '../src/scene/homeLayout.js'
import {mainRoof,frontPorch,corridorCanopy} from '../src/scene/homeLayout.js'
import {shedBeamY} from '../src/scene/homeLayout.js'
test('owner-confirmed inverted-V roof keeps its footprint and the flat canopy covers the whole corridor',()=>{
  assert.equal(mainRoof.depth,6.8);assert.equal(mainRoof.centerZ,-2.85);
  assert.ok(mainRoof.rise>0&&mainRoof.eaveY+mainRoof.rise>mainRoof.eaveY);
  assert.equal(corridorCanopy.depth,1.8);
  assert.ok(frontPorch.depth>corridorCanopy.depth);
  assert.ok(Math.abs((frontPorch.centerZ-frontPorch.depth/2)-(corridorCanopy.centerZ-corridorCanopy.depth/2))<1e-9);
  assert.equal(corridorCanopy.width,houseLocalMaxX-houseLocalMinX);
  assert.equal(corridorCanopy.centerX,(houseLocalMaxX+houseLocalMinX)/2);
  assert.ok(corridorCanopy.baseY>3.72);
  assert.ok(corridorCanopy.baseY+corridorCanopy.thickness<mainRoof.eaveY);
  assert.ok(corridorCanopy.centerZ+corridorCanopy.depth/2>mainRoof.centerZ+mainRoof.depth/2);
});
test('shelter sheet and front purlin clear the trike canopy without changing vehicle placement',()=>{
  let sheetClearance=Infinity,beamClearance=Infinity;
  for(let i=0;i<=80;i++)for(let j=0;j<=80;j++){
    const localX=-.86+i*1.72/80,localZ=-.92+j*2.04/80;
    const worldX=shedPlacement.trike[0]-localZ,worldZ=shedPlacement.trike[2]+localX;
    const x=worldX-shedPlacement.position[0],z=worldZ-shedPlacement.position[2],tarpY=2.45+Math.sqrt(Math.max(0,1-(localX/.86)**2))*.21;
    if(Math.abs(x)>shedPlacement.width/2||Math.abs(z)>shedPlacement.depth/2)continue;
    const metalY=shedPlacement.roofY+.026*Math.cos((x+shedPlacement.width/2)/.15*Math.PI*2)+z*.012;
    sheetClearance=Math.min(sheetClearance,metalY-tarpY);
    if(Math.abs(z+3)<.035)beamClearance=Math.min(beamClearance,shedBeamY(-3)-shedPlacement.beamThickness/2-tarpY);
  }
  assert.ok(sheetClearance>.13);assert.ok(Number.isFinite(beamClearance)&&beamClearance>.05);
  assert.deepEqual(shedPlacement.trike,[-4.6,0,1.6]);
});
test('outer west window and middle door move toward the gate, corner door stays put',()=>{
  assert.deepEqual(westFacade.doors.map(d=>d.z),[4.28,6.82])
  assert.equal(westFacade.windows.length,1)
  assert.equal(westFacade.windows[0].z,1.1)
  assert.ok(westFacade.doors[0].z-westFacade.windows[0].z>3)
  assert.ok(westFacade.windows[0].width>=2.1)
  assert.equal(westFacade.windows[0].height,2)
  assert.equal(westFacade.windowCenterY,2.715)
  assert.equal(westFacade.doorTopY,houseWindowCenterY+houseWindowOpeningHeight/2)
  assert.ok(westFacade.doorLeafHeight>2.5)
  assert.equal(westFacade.windows[0].columns,4)
  assert.equal(westFacade.windows[0].rows,2)
  assert.ok(westFacade.doors.every(door=>door.width<1.2))
  assert.ok(westFacade.doors[1].z-westFacade.doors[0].z>westFacade.doors[0].width+.3)
})
test('courtyard walls rise half a metre without changing the gate portal height',()=>{
  assert.equal(sideWallHeight,2.8)
  assert.equal(frontWallHeight,2.7)
  assert.equal(gatePortico.innerTopY+gatePortico.recessTopInset,3.605)
})
import {canWalk,moveWithinYard,homePhotos,housePlacement,shedPlacement,houseSideX,houseLocalMaxX,houseLocalMinX,houseWestX,eastWall,westBoundary,westWing,addedRooms,obstacles,gateX,gateZ,gatePortico,gateApron,houseDoorLocalX,houseWindows} from '../src/scene/homeLayout.js'
test('gate moves west, house door east and only one window remains under the narrower shelter',()=>{
  assert.ok(gateX>1.4)
  assert.ok(Math.abs(houseDoorLocalX-1.725)<1e-9)
  assert.ok(Math.abs(housePlacement.position[0]-houseDoorLocalX*housePlacement.scaleX-.02)<1e-9)
  assert.ok(shedPlacement.width<5.85)
  const covered=houseWindows.filter(w=>Math.abs(housePlacement.position[0]-w.x*housePlacement.scaleX-shedPlacement.position[0])<shedPlacement.width/2)
  assert.equal(covered.length,1)
  assert.equal(canWalk(1.4,gateZ-.5),false)
})
test('house side is flush with boundary and western rear gap contains rooms',()=>{
  assert.equal(houseSideX,eastWall.centerX-eastWall.thickness/2)
  assert.ok(Math.abs(housePlacement.position[0]-houseLocalMaxX*housePlacement.scaleX-houseSideX)<1e-9)
  assert.ok(Math.abs(housePlacement.position[0]-houseLocalMinX*housePlacement.scaleX-houseWestX)<1e-9)
  assert.equal(westBoundary.centerX+westBoundary.thickness/2,addedRooms[0].bounds[1])
  assert.equal(westBoundary.endZ,addedRooms[0].bounds[2])
  assert.equal(westWing.width,addedRooms[1].bounds[3]-addedRooms[0].bounds[2])
  assert.ok(addedRooms[0].bounds[2]<0)
  assert.equal(westBoundary.startZ,eastWall.startZ)
  assert.equal(westBoundary.startZ,gatePortico.frontZ)
  assert.equal(gatePortico.frontZ,gateZ-.175)
  assert.equal(gateApron.frontZ,gatePortico.frontZ)
  assert.ok(gatePortico.backZ>gateZ+1.8)
  assert.ok(gateApron.backZ>gatePortico.backZ)
  assert.equal(obstacles[0][1],addedRooms[1].bounds[1])
  assert.ok(obstacles[0][2]<=addedRooms[1].bounds[3])
  assert.equal(canWalk(7.5,10),false)
})
test('shelter overlaps the window while the house entrance remains accessible',()=>{
  const windowX=housePlacement.position[0]-7.4*housePlacement.scaleX
  assert.ok(Math.abs(shedPlacement.position[0]-windowX)<shedPlacement.width/2)
  assert.ok(Math.abs(shedPlacement.position[2]+shedPlacement.depth/2-(housePlacement.position[2]-.2))<.01)
  assert.ok(Math.abs(shedPlacement.position[0]-shedPlacement.width/2-(-6.15))<.01)
  assert.equal(canWalk(-1.8,5.9),true)
  assert.equal(canWalk(-4.7,6),false) // cement wall now rises from this step edge
  assert.equal(canWalk(-2,6),true) // wash corridor remains reachable around its house-side end
  assert.equal(canWalk(1.4,6.8),true)
  assert.equal(canWalk(-3.3,3.6),false)
  assert.equal(canWalk(-5.65,6.65),false)
  assert.equal(canWalk(-2.55,5.6),false)
})
test('the house faces the gate and the former house site is open courtyard',()=>{
  assert.ok(Math.abs(Math.cos(housePlacement.rotationY)+1)<1e-10)
  assert.equal(housePlacement.position[0],1.4)
  assert.ok(housePlacement.position[2]>7)
  assert.equal(canWalk(5,0),true)
  assert.equal(canWalk(1.4,7.5),false)
  assert.equal(canWalk(1.4,6),true)
})
test('all photo viewpoints are inside the traversable yard',()=>{
  for(const photo of homePhotos)assert.ok(canWalk(photo.position[0],photo.position[2]),photo.name)
})
test('walls, vehicles and unobserved interiors are not traversable',()=>{
  assert.equal(canWalk(6,1),false)
  assert.equal(canWalk(-3.8,2.5),false)
  assert.equal(canWalk(-4,gateZ),false)
  assert.equal(canWalk(-7,0),false)
})
test('gate passage can be entered but unmodelled outside is bounded',()=>{
  assert.equal(canWalk(gateX,gateZ-.5),true)
  assert.equal(canWalk(gateX,gateZ-2.5),false)
})
test('large movement steps do not tunnel through a parked vehicle',()=>{
  const result=moveWithinYard({x:0,z:3.6},-6,0)
  assert.ok(result.x>-2.45)
  assert.ok(canWalk(result.x,result.z))
})
test('wall sliding preserves valid forward motion',()=>{
  const start={x:westBoundary.centerX-westBoundary.thickness/2-.3,z:-3}
  assert.ok(canWalk(start.x,start.z))
  const result=moveWithinYard(start,2,1)
  assert.ok(result.x<westBoundary.centerX-westBoundary.thickness/2)
  assert.ok(result.z>-2.1)
})
test('annotated rooms are solid and west boundary leaves the former wall open',()=>{
  assert.equal(canWalk(-4.7,9),false)
  assert.equal(canWalk(7.5,2),false)
  assert.equal(canWalk(7.5,6),false)
  assert.equal(canWalk(6.3,-2),true)
  assert.equal(canWalk(9.6,-2),false)
})

// The inner wing wall is the visible photo junction, not the outer house edge.
import {westWingYardWallX} from '../src/scene/homeLayout.js'
test('west window leaves the photo-sized wall return at the wing junction',()=>{
 const w=houseWindows[0];
 const westEdge=housePlacement.position[0]-(w.x-w.width/2)*housePlacement.scaleX;
 const clear=westWingYardWallX-westEdge;
 assert.ok(clear>1.25&&clear<1.5,`clear wall return ${clear}`);
});
