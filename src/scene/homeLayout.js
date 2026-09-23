// User confirmed that the house faces the gate across the courtyard.
// Dimensions and other placements remain provisional, not surveyed.
export const housePlacement = { position: [1.4, 0, 7.8], rotationY: Math.PI, scaleX: .8 }
export const westWingYardWallX = 6.1
// Both outer gate pillars are now 0.5 m closer to the opening centre.
export const gateEastPillarX = 1.77
export const gateWestPillarX = westWingYardWallX-.5
export const gateX = (gateEastPillarX + gateWestPillarX) / 2
export const gateZ = -5.5
const gateHalfPillarSpacing = (gateWestPillarX - gateEastPillarX) / 2
const gatePillarWidth = .42
const gateOuterOpeningWidth = 2*gateHalfPillarSpacing-gatePillarWidth
const gateRecessSideInset = .5
const gateInnerOpeningWidth = gateOuterOpeningWidth-2*gateRecessSideInset
const gateRecessDepth = 1
// The gate leaves and exterior recess stay put; only the passage behind them grows.
const gateWallFrontZ = gateZ-.175
const gateFrontLocalZ = -.175
const gateBackLocalZ = 3.1
export const gatePortico = {
  frontZ:gateZ+gateFrontLocalZ,backZ:gateZ+gateBackLocalZ,
  depth:gateBackLocalZ-gateFrontLocalZ,centerLocalZ:(gateFrontLocalZ+gateBackLocalZ)/2,
  recessDepth:gateRecessDepth,recessSideInset:gateRecessSideInset,recessTopInset:1,
  leafLocalZ:gateFrontLocalZ+gateRecessDepth,
  halfPillarSpacing:gateHalfPillarSpacing,pillarWidth:gatePillarWidth,
  outerWidth:2*gateHalfPillarSpacing+gatePillarWidth,
  clearWidth:gateOuterOpeningWidth,innerClearWidth:gateInnerOpeningWidth,
  innerTopY:3.605-1,leafWidth:gateInnerOpeningWidth/2-.015,
}
export const gateApron = {
  frontZ:gatePortico.frontZ,backZ:gatePortico.backZ+.4,
  depth:gatePortico.backZ+.4-gatePortico.frontZ,
  centerZ:(gatePortico.frontZ+gatePortico.backZ+.4)/2,width:gatePortico.clearWidth+.1,
}
// The house is rotated 180° and scaled to 0.8 on X: +0.625 local is 0.5 m east in the yard.
export const houseDoorLocalX = 1.725
const houseSideWindowWidth = .5
const houseSideWindowOffset = 1.37
export const houseEntry = {
  // The house uses scaleX .8, so +.25 local yields +.2 m in the courtyard.
  woodWidth:2.15,sideWindowWidth:houseSideWindowWidth,sideWindowOffset:houseSideWindowOffset,
  // The transom spans the outer edges of both sidelights.
  transomWidth:2*(houseSideWindowOffset+houseSideWindowWidth/2),openingWidth:3.33,
}
export const houseWindows = [{x:-3.1,width:2.8},{x:7.4,width:2.8}]
// Keep the confirmed upper edge at 3.715 m while raising both sills by 0.4 m.
export const houseWindowCenterY = 2.715
export const houseWindowOpeningHeight = 2
export const sideWallHeight = 2.8
export const frontWallHeight = 2.7
export const eastWall = { centerX: -6.3, thickness: .35, startZ:gateWallFrontZ, endZ:8 }
export const houseSideX = eastWall.centerX-eastWall.thickness/2
export const houseLocalMaxX = (housePlacement.position[0]-houseSideX)/housePlacement.scaleX
export const houseWestX = 9.3
export const houseLocalMinX = (housePlacement.position[0]-houseWestX)/housePlacement.scaleX
// Owner correction: the main roof is an inverted V, with a flat projection
// above the entire front corridor. Rise and slab thickness remain estimates.
export const mainRoof = {
  centerX:(houseLocalMinX+houseLocalMaxX)/2,centerZ:-2.85,
  width:houseLocalMaxX-houseLocalMinX+.75,depth:6.8,
  eaveY:5.1,rise:1.2,thickness:.1,
}
export const frontPorch = {centerX:1.7,centerZ:.8,width:15.3,depth:1.8,height:.28}
export const corridorCanopy = {
  centerX:(houseLocalMinX+houseLocalMaxX)/2,centerZ:frontPorch.centerZ,
  width:houseLocalMaxX-houseLocalMinX,depth:frontPorch.depth,baseY:4.9,thickness:.14,
}
export const corridorFrontWorldZ=housePlacement.position[2]-corridorCanopy.centerZ-corridorCanopy.depth/2
// The owner confirmed that the small shelter overlaps the house window.
export const shedPlacement = {
  position: [-4.275, 0, 4.3], width: 3.75, depth: 6.6,
  roofY:sideWallHeight+.1,beamThickness:.08,
  corridorStartZ: 5.6,
  posts: [[-6,1.3],[-2.55,1.3],[-6,5.6],[-2.55,5.6],[-6,7.35],[-2.55,7.35]],
  car: [-3.3,0,4.05], trike: [-4.6,0,1.6], trikeRotationY: -Math.PI/2, sink: [-5.65,0,6.65],
}
// The low vehicle-bay sheet ends at the front of the washing corridor. Its
// wall-side edge bears on the courtyard wall, below the glazed upper band.
export const shedRoof={
  frontZ:shedPlacement.position[2]-shedPlacement.depth/2,backZ:corridorFrontWorldZ,
  depth:corridorFrontWorldZ-(shedPlacement.position[2]-shedPlacement.depth/2),
  centerLocalZ:((shedPlacement.position[2]-shedPlacement.depth/2)+corridorFrontWorldZ)/2-shedPlacement.position[2],
  width:shedPlacement.width+.15,centerLocalX:-.075,
  pitch:0,corrugation:.026,
}
export const shedClerestory={
  z:shedRoof.backZ,
  centerLocalX:shedRoof.centerLocalX,width:shedRoof.width,
  bottomY:shedPlacement.roofY+shedRoof.corrugation,
  topY:corridorCanopy.baseY,
}
// The outside flank of the high corridor is brick, continuous with the east
// courtyard boundary. The glazed band is only on the yard-facing front.
export const shedSideBrick={
  x:eastWall.centerX,thickness:eastWall.thickness,
  startZ:corridorFrontWorldZ,endZ:corridorFrontWorldZ+corridorCanopy.depth,
  bottomY:sideWallHeight,topY:corridorCanopy.baseY,
}
// Local Z along the sheet; purlins touch the valleys from below. Keep the
// confirmed plan unchanged while leaving clearance over the vehicle canopy.
export const shedBeamY = localZ => shedPlacement.roofY-shedRoof.corrugation+localZ*shedRoof.pitch-.005-shedPlacement.beamThickness/2
// A cement wall rises from the house step to the low shed roof. The glass
// above it continues to the high projecting eave on the same front plane.
export const shedStepWall={
  minX:eastWall.centerX,maxX:shedPlacement.position[0]+shedRoof.centerLocalX+shedRoof.width/2,
  z:corridorFrontWorldZ,thickness:.22,
  bottomY:frontPorch.height,
  topY:shedBeamY(corridorFrontWorldZ-shedPlacement.position[2])+shedPlacement.beamThickness/2,
}
// The hand-drawn edge runs lengthwise through the yard, not across it.
// Each pair is [world Z, world X]: earth lies toward the east wall (smaller X),
// while the doorway-to-gate side (larger X) remains poured cement.
export const yardGroundBoundary=[
  [-7.2,2.85],[-5.7,2.95],[-4.2,2.65],[-3.1,2.15],[-2.1,1.55],
  [-1.2,.8],[-.2,.02],[.7,-1.12],[1.5,-1.85],
  [3,-2.38],[5,-2.68],[8,-2.68],
]
export const courtyardTrees=[
  {x:-5.35,z:-2.4,size:1.1,seed:1979},
  {x:-2.1,z:-.5,size:.85,seed:2015}, // smaller tree: moved from the wall to the owner's circled spot
]
// Screenshot annotations: west is +X (left when entering and facing the house).
// Room footprints are provisional; their presence is owner-confirmed.
export const addedRooms = [
  { bounds: [westWingYardWallX, 9.3, -1, 4.3], facing: 'yard' },
  { bounds: [westWingYardWallX, 9.3, 4.3, 7.8], facing: 'yard' },
]
// The western boundary ends at the wing's front corner; its outside face
// shares the same plane as the wing and main house exterior.
export const westBoundary = {
  thickness:.35,
  centerX:houseWestX-.35/2,
  startZ:gateWallFrontZ,
  endZ:addedRooms[0].bounds[2],
}
export const westWallX = westBoundary.centerX
export const westWing={
  position:[7.7,0,(addedRooms[0].bounds[2]+addedRooms[1].bounds[3])/2],
  rotationY:-Math.PI/2,
  width:addedRooms[1].bounds[3]-addedRooms[0].bounds[2],depth:3.2,wallHeight:5,
}
// The western eave platform projects only half as far as the main corridor
// platform. Its rear end meets the main slab at world Z=6.1 without overlap.
export const westCorridorCanopy={
  centerX:(addedRooms[0].bounds[2]+corridorFrontWorldZ)/2-westWing.position[2],
  centerZ:westWing.depth/2+frontPorch.depth/4,
  width:corridorFrontWorldZ-addedRooms[0].bounds[2],depth:frontPorch.depth/2,
  baseY:corridorCanopy.baseY,thickness:corridorCanopy.thickness,
}
export const westGroundCorridor={
  centerX:westCorridorCanopy.centerX,centerZ:westCorridorCanopy.centerZ,
  width:westCorridorCanopy.width,depth:westCorridorCanopy.depth,
  height:frontPorch.height,
}
const westRoofFrontZ=addedRooms[0].bounds[2]-.175
export const westRoof={
  centerX:(westRoofFrontZ+corridorFrontWorldZ)/2-westWing.position[2],centerZ:0,
  width:corridorFrontWorldZ-westRoofFrontZ,depth:westWing.depth+.4,
  eaveY:corridorCanopy.baseY+corridorCanopy.thickness,rise:.65,thickness:.1,
  wallMinX:addedRooms[0].bounds[2]-westWing.position[2],wallMaxX:corridorFrontWorldZ-westWing.position[2],
}
// Owner annotation: door 1 replaced the window nearest the main house;
// the remaining window was made wider and taller.
// The outer window and middle door shift 1 m toward the courtyard entrance;
// the door beside the main-house corner stays at its original position.
export const westFacade = {
  doorTopY:houseWindowCenterY+houseWindowOpeningHeight/2,
  doorLeafHeight:2.64,
  windowCenterY:houseWindowCenterY,
  doors: [{id:'middle',z:4.28,width:1.12},{id:'door-1',z:6.82,width:1.12}],
  windows: [{z:1.1,width:2.16,height:houseWindowOpeningHeight,columns:4,rows:2}],
}
export const homePhotos = [
  { src: '/home-photos/01-house.jpg', name: '屋前的台阶', note: '2015.02.19 · 白墙、木窗、晾衣绳', position: [1.4, 1.65, .5], target: [1.4, 1.8, 7.8] },
  { src: '/home-photos/02-yard.jpg', name: '院里的三轮车', note: '2015.02.19 · 绿篷、车棚与老树', position: [3.8, 1.65, -1.3], target: [-3.5, 1.25, 3] },
  { src: '/home-photos/03-gate.jpg', name: '通向外面的大门', note: '2015.02.19 · 砖门洞、红铁门', position: [.2, 1.65, 3.2], target: [gateX, 1.4, gateZ+.1] },
  { src: '/home-photos/04-summer.jpg', name: '另一个季节', note: '拍摄日期未确认 · 夏日参照，蓝棚未加入冬日场景', position: [-2, 1.65, 1.7], target: [1.4, 1.5, gateZ-.5] },
  { src: '/home-photos/05-bird.jpg', name: '墙头的小鸟', note: '照片标注 2014 · 局部参照，位置暂为推测', position: [-3.7, 1.65, -.6], target: [-6, 1.8, -2.7] },
]
export const obstacles = [
  [houseSideX, houseWestX, 7.6, 14], // continuous house includes its western rear rooms
  ...addedRooms.map(room => room.bounds),
  [-6.5, -6.1, eastWall.startZ, 8.5], [westWallX-.2, westWallX+.2, westBoundary.startZ, westBoundary.endZ], [-6.5, westWallX, 7.8, 8.3],
  [-6.5, gateEastPillarX-gatePillarWidth/2, gateZ-.2, gateZ+.3], [gateWestPillarX+gatePillarWidth/2, westWallX, gateZ-.2, gateZ+.3],
  [gateEastPillarX-gatePillarWidth/2, gateEastPillarX+gatePillarWidth/2, gatePortico.frontZ, gatePortico.backZ], [gateWestPillarX-gatePillarWidth/2, gateWestPillarX+gatePillarWidth/2, gatePortico.frontZ, gatePortico.backZ],
  [-5.8, -2.5, .7, 2.5], [-4.15, -2.45, 2.65, 5.45],
  [-6.05, -5.25, 6.05, 7.25], // washbasin along the wall, in the rear corridor
  [shedStepWall.minX,shedStepWall.maxX,shedStepWall.z-shedStepWall.thickness/2,shedStepWall.z+shedStepWall.thickness/2],
  ...shedPlacement.posts.map(([x,z]) => [x-.045,x+.045,z-.045,z+.045]),
  [-5.7, -4.9, -2.8, -1.9],
]
export function canWalk(x, z, radius = .23) {
  if (x < -5.95 || x > westWallX-.4 || z > 7.55 || z < gateZ-1.7) return false
  if (z < gateZ+.4 && (x < gateEastPillarX+gatePillarWidth/2+.1 || x > gateWestPillarX-gatePillarWidth/2-.1)) return false
  return !obstacles.some(([minX,maxX,minZ,maxZ]) => x > minX-radius && x < maxX+radius && z > minZ-radius && z < maxZ+radius)
}
export function moveWithinYard(position, dx, dz) {
  let { x, z } = position
  const steps = Math.max(1, Math.ceil(Math.hypot(dx,dz)/.12))
  for(let i=0;i<steps;i++) {
    if(canWalk(x+dx/steps,z)) x+=dx/steps
    if(canWalk(x,z+dz/steps)) z+=dz/steps
  }
  return {x,z}
}
