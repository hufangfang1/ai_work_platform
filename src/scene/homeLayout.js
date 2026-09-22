// User confirmed that the house faces the gate across the courtyard.
// Dimensions and other placements remain provisional, not surveyed.
export const housePlacement = { position: [1.4, 0, 7.8], rotationY: Math.PI, scaleX: .8 }
export const gateX = 2.7
export const houseDoorLocalX = 1.1
export const houseWindows = [{x:-3.1,width:3.2},{x:7.4,width:1.65}]
export const eastWall = { centerX: -6.3, thickness: .35 }
export const houseSideX = eastWall.centerX-eastWall.thickness/2
export const houseLocalMaxX = (housePlacement.position[0]-houseSideX)/housePlacement.scaleX
export const houseWestX = 9.3
export const houseLocalMinX = (housePlacement.position[0]-houseWestX)/housePlacement.scaleX
// The owner confirmed that the small shelter overlaps the house window.
export const shedPlacement = {
  position: [-4.275, 0, 4.3], width: 3.75, depth: 6.6,
  corridorStartZ: 5.6,
  posts: [[-6,1.3],[-2.55,1.3],[-6,5.6],[-2.55,5.6],[-6,7.35],[-2.55,7.35]],
  car: [-3.3,0,4.05], trike: [-4.6,0,1.6], trikeRotationY: -Math.PI/2, sink: [-5.65,0,6.65],
}
// Screenshot annotations: west is +X (left when entering and facing the house).
// Room footprints are provisional; their presence is owner-confirmed.
export const westWallX = 9.6
export const addedRooms = [
  { bounds: [6.1, 9.3, .8, 4.3], facing: 'yard' },
  { bounds: [6.1, 9.3, 4.3, 7.8], facing: 'yard' },
]
// Owner annotation: door 1 replaces the window nearest the main house;
// the middle door stays, and the remaining window becomes wider/taller.
export const westFacade = {
  doors: [{id:'middle',z:5.28},{id:'door-1',z:6.82}],
  windows: [{z:3.32,width:1.5,height:1.65}],
}
export const homePhotos = [
  { src: '/home-photos/01-house.jpg', name: '屋前的台阶', note: '2015.02.19 · 白墙、木窗、晾衣绳', position: [1.4, 1.65, .5], target: [1.4, 1.8, 7.8] },
  { src: '/home-photos/02-yard.jpg', name: '院里的三轮车', note: '2015.02.19 · 绿篷、车棚与老树', position: [3.8, 1.65, -1.3], target: [-3.5, 1.25, 3] },
  { src: '/home-photos/03-gate.jpg', name: '通向外面的大门', note: '2015.02.19 · 砖门洞、红铁门', position: [.2, 1.65, 3.2], target: [gateX, 1.4, -7.4] },
  { src: '/home-photos/04-summer.jpg', name: '另一个季节', note: '拍摄日期未确认 · 夏日参照，蓝棚未加入冬日场景', position: [-2, 1.65, 1.7], target: [1.4, 1.5, -6] },
  { src: '/home-photos/05-bird.jpg', name: '墙头的小鸟', note: '照片标注 2014 · 局部参照，位置暂为推测', position: [-3.7, 1.65, -.6], target: [-6, 1.8, -2.7] },
]
export const obstacles = [
  [houseSideX, houseWestX, 7.6, 14], // continuous house includes its western rear rooms
  ...addedRooms.map(room => room.bounds),
  [-6.5, -6.1, -8, 8.5], [westWallX-.2, westWallX+.2, -8, 8.5], [-6.5, westWallX, 7.8, 8.3],
  [-6.5, gateX-1.4, -7.7, -7.2], [gateX+1.4, westWallX, -7.7, -7.2],
  [gateX-1.65, gateX-1.25, -8.5, -6.7], [gateX+1.3, gateX+1.7, -8.5, -6.7],
  [-5.8, -2.5, .7, 2.5], [-4.15, -2.45, 2.65, 5.45],
  [-6.05, -5.25, 6.05, 7.25], // washbasin along the wall, in the rear corridor
  ...shedPlacement.posts.map(([x,z]) => [x-.045,x+.045,z-.045,z+.045]),
  [-5.7, -4.9, -2.8, -1.9],
]
export function canWalk(x, z, radius = .23) {
  if (x < -5.95 || x > westWallX-.4 || z > 7.55 || z < -9.2) return false
  if (z < -7.1 && (x < gateX-1 || x > gateX+1)) return false
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
