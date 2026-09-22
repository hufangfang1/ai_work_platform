import * as THREE from 'three'
import { MarchingCubes } from 'three/addons/objects/MarchingCubes.js'

// A smooth union, sculpted once at startup. Unlike overlapping balls, there
// are no seams between the cheeks, forehead and belly.
export function sculptCloud() {
  const resolution = 58, extent = 1.55
  const scratchMaterial = new THREE.MeshBasicMaterial()
  const field = new MarchingCubes(resolution, scratchMaterial, false, false, 40000)
  field.isolation = 0
  const volumes = [
    [0, 0, 0, .83, .92, .69],
    [-.63, .15, -.04, .44, .46, .55], [.64, .11, -.06, .43, .45, .55],
    [-.49, .59, -.035, .35, .35, .48], [-.075, .76, -.025, .40, .44, .50], [.43, .62, -.06, .35, .38, .48],
    [-.51, -.51, -.03, .42, .43, .5], [.50, -.51, -.045, .43, .42, .49],
    [0, -.72, -.03, .46, .34, .51],
  ]
  const blend = .22
  for (let z = 0; z < resolution; z++) {
    for (let y = 0; y < resolution; y++) {
      for (let x = 0; x < resolution; x++) {
        const px = (x / resolution * 2 - 1) * extent
        const py = (y / resolution * 2 - 1) * extent
        const pz = (z / resolution * 2 - 1) * extent
        let distance = 10
        for (const [cx, cy, cz, rx, ry, rz] of volumes) {
          const dx = px - cx, dy = py - cy, dz = pz - cz
          const k0 = Math.hypot(dx / rx, dy / ry, dz / rz)
          const k1 = Math.hypot(dx / (rx * rx), dy / (ry * ry), dz / (rz * rz))
          const d = k1 > .000001 ? k0 * (k0 - 1) / k1 : -Math.min(rx, ry, rz)
          const h = Math.max(blend - Math.abs(distance - d), 0) / blend
          distance = Math.min(distance, d) - h * h * blend * .25
        }
        field.field[z * resolution * resolution + y * resolution + x] = -distance
      }
    }
  }
  field.update()
  const geometry = new THREE.BufferGeometry()
  geometry.setAttribute('position', new THREE.BufferAttribute(field.positionArray.slice(0, field.count * 3), 3))
  geometry.setAttribute('normal', new THREE.BufferAttribute(field.normalArray.slice(0, field.count * 3), 3))
  geometry.scale(extent, extent * .79, extent)
  geometry.normalizeNormals()
  geometry.computeBoundingSphere()
  field.geometry.dispose(); scratchMaterial.dispose()
  return geometry
}

export function sculptEar(sign) {
  const geometry = new THREE.SphereGeometry(1, 48, 40)
  const positions = geometry.attributes.position, colors = []
  const root = new THREE.Color('#f7e7ce'), middle = new THREE.Color('#e9b9a1'), tip = new THREE.Color('#aca5bf')
  for (let i = 0; i < positions.count; i++) {
    const x = positions.getX(i), y = positions.getY(i), z = positions.getZ(i), t = (1 - y) / 2
    // A plump folded petal, with a curved centreline and rounded end.
    positions.setXYZ(i, sign * (.56 * t - .08 * Math.sin(t * Math.PI)), -.66 * t + .15 * Math.sin(t * Math.PI), z * .19 + .12 * Math.sin(t * Math.PI) + .025 * t)
    positions.setX(i, positions.getX(i) + x * (.18 + .17 * t))
    const c = root.clone().lerp(middle, THREE.MathUtils.smoothstep(t, .2, .65)).lerp(tip, THREE.MathUtils.smoothstep(t, .55, 1) * .7)
    colors.push(c.r, c.g, c.b)
  }
  geometry.setAttribute('color', new THREE.Float32BufferAttribute(colors, 3))
  geometry.computeVertexNormals()
  return geometry
}
