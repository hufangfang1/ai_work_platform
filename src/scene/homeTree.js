import * as T from 'three'

const TAU = Math.PI * 2

function seededRandom(seed) {
  let state = seed >>> 0
  return () => {
    state += 0x6d2b79f5
    let value = state
    value = Math.imul(value ^ (value >>> 15), value | 1)
    value ^= value + Math.imul(value ^ (value >>> 7), value | 61)
    return ((value ^ (value >>> 14)) >>> 0) / 4294967296
  }
}

/**
 * A leafless courtyard tree in metres, rooted at (0, 0, 0).
 * Each limb is a continuous curved, tapering sweep. Side branches begin inside
 * their parent and initially follow its tangent: there are no exposed cylinder
 * caps at forks. All limbs share one indexed geometry / one draw call.
 */
export function createWinterTreeGeometry(seed = 1979, { detail = 'near' } = {}) {
  if (!Number.isFinite(seed)) throw new TypeError('Tree seed must be finite')
  if (detail !== 'near' && detail !== 'far') throw new RangeError('Unknown tree detail')

  const random = seededRandom(seed)
  const near = detail === 'near'
  const positions = [], uvs = [], indices = [], seamPairs = []
  const axes = []
  const up = new T.Vector3(0, 1, 0)
  const lean = (random() - .5) * .65
  const phase = random() * TAU
  const height = 7.55 + random() * .65
  const trunk = new T.CatmullRomCurve3([
    new T.Vector3(0, 0, 0),
    new T.Vector3(.012, .32, .005),
    new T.Vector3(.075, 1.2, -.015),
    new T.Vector3(.02 + lean * .12, 2.3, -.055),
    new T.Vector3(.2 + lean * .3, 3.5, .055),
    new T.Vector3(.12 + lean * .6, 4.6, .15),
    new T.Vector3(.38 + lean, 5.7, .045),
    new T.Vector3(.29 + lean * 1.2, 6.8, -.06),
    new T.Vector3(.47 + lean * 1.5, height, .12),
  ], false, 'centripetal')

  const trunkRadius = t => .284 * Math.pow(1 - t, 1.12) + .006
    + .072 * Math.exp(-t * 42)
  axes.push({ curve: trunk, radius: trunkRadius, kind: 0, phase })

  function branch(parent, t, direction, length, baseRadius, kind) {
    const origin = parent.curve.getPointAt(t)
    const tangent = parent.curve.getTangentAt(t).normalize()
    // Root is recessed into the parent, including on the smaller twig forks.
    const parentRadius = parent.radius(t)
    const start = origin.clone().addScaledVector(tangent, -parentRadius * .42)
    const end = origin.clone().addScaledVector(direction, length)
    const out = direction.clone().cross(up)
    if (out.lengthSq() < .001) out.set(1, 0, 0)
    out.normalize().multiplyScalar((random() - .5) * length * .25)
    const c1 = origin.clone().addScaledVector(tangent, length * .26)
      .addScaledVector(direction, length * .11)
    const c2 = origin.clone().addScaledVector(direction, length * .71)
      .addScaledVector(up, -length * (.03 + random() * .055)).add(out)
    const curve = new T.CubicBezierCurve3(start, c1, c2, end)
    const tip = kind === 1 ? .0038 : kind === 2 ? .0025 : .0015
    const radius = s => tip + (baseRadius - tip) * Math.pow(1 - s, 1.22)
    const axis = { curve, radius, kind, phase: phase + random() * TAU }
    axes.push(axis)
    return axis
  }

  // Lower limbs reach out before turning upward. Upper branches are shorter,
  // preventing the repeated umbrella/fan silhouette of a symmetric L-system.
  const primary = []
  for (let i = 0; i < 8; i++) {
    const t = .255 + i * .071 + (random() - .5) * .026
    const angle = phase + i * 2.39996323 + (random() - .5) * .62
    const direction = new T.Vector3(Math.cos(angle), .72 + random() * .7, Math.sin(angle)).normalize()
    const length = (3.9 - t * 2.5) * (.86 + random() * .25)
    primary.push(branch(axes[0], t, direction, length, trunkRadius(t) * (.56 + random() * .12), 1))
  }
  for (let i = 0; i < 2; i++) {
    const angle = phase + i * 3.3 + .7
    primary.push(branch(axes[0], .82 + i * .075,
      new T.Vector3(Math.cos(angle) * .68, 1, Math.sin(angle) * .68).normalize(),
      1.15 - i * .27, trunkRadius(.82 + i * .075) * .62, 1))
  }

  for (let i = 0; i < primary.length; i++) {
    const limb = primary[i]
    const count = i < 8 ? 3 : 2
    for (let j = 0; j < count; j++) {
      const t = .34 + j * .215 + (random() - .5) * .055
      const tangent = limb.curve.getTangentAt(t)
      const angle = Math.atan2(tangent.z, tangent.x)
        + (j % 2 ? -1 : 1) * (.68 + random() * .55)
      const direction = new T.Vector3(Math.cos(angle), .68 + random() * .7, Math.sin(angle)).normalize()
      const length = (1.48 - j * .18) * (i < 8 ? 1 : .58) * (.75 + random() * .35)
      const secondary = branch(limb, t, direction, length, limb.radius(t) * .61, 2)

      // Tiny alternating shoots provide the winter silhouette without a mass
      // of equal-thickness sticks. Their tips remain below a 2 mm radius.
      const shoots = 3
      for (let k = 0; k < shoots; k++) {
        const shootT = .37 + k * .205 + (random() - .5) * .045
        const shootAngle = angle + (k % 2 ? -1 : 1) * (.64 + random() * .65)
        const shootDirection = new T.Vector3(Math.cos(shootAngle), .6 + random() * .85, Math.sin(shootAngle)).normalize()
        branch(secondary, shootT, shootDirection,
          (.36 + random() * .32) * (1 - k * .1), secondary.radius(shootT) * .58, 3)
      }
    }
    if (i < 8) {
      const t = .88, tangent = limb.curve.getTangentAt(t)
      const direction = tangent.clone().add(new T.Vector3(.3 * Math.cos(i), .24, .3 * Math.sin(i))).normalize()
      branch(limb, t, direction, .35 + random() * .24, limb.radius(t) * .65, 3)
    }
  }

  function sweep(axis) {
    const length = axis.curve.getLength()
    const radial = axis.kind === 0 ? (near ? 14 : 10) : axis.kind === 1 ? (near ? 9 : 7) : (near ? 6 : 5)
    const segments = Math.max(axis.kind === 0 ? 32 : 5,
      Math.ceil(length / (near ? (axis.kind < 2 ? .18 : .13) : .35)))
    const frame = axis.curve.computeFrenetFrames(segments, false)
    const startIndex = positions.length / 3
    for (let row = 0; row <= segments; row++) {
      const t = row / segments, centre = axis.curve.getPointAt(t)
      const base = axis.radius(t)
      for (let side = 0; side <= radial; side++) {
        const angle = side / radial * TAU
        const groove = 1 + .048 * Math.sin(angle * 3 + axis.phase + t * 1.4)
          + .025 * Math.sin(angle * 5 - t * 2)
        // The short buttress is part of the bole, not a set of separate roots.
        const buttress = axis.kind === 0
          ? .065 * Math.pow(.5 + .5 * Math.cos(angle * 5 + phase), 3) * Math.exp(-t * 42)
          : 0
        const radius = base * groove + buttress
        const offset = frame.normals[row].clone().multiplyScalar(Math.cos(angle) * radius)
          .addScaledVector(frame.binormals[row], Math.sin(angle) * radius)
        const point = centre.clone().add(offset)
        // Tree bases stay on the ground despite the very slight trunk lean.
        if (axis.kind === 0 && row === 0) point.y = 0
        positions.push(point.x, point.y, point.z)
        // Bark grain is measured in metres; not stretched once per whole limb.
        uvs.push(side / radial * TAU * base, t * length)
      }
      seamPairs.push([startIndex + row * (radial + 1), startIndex + row * (radial + 1) + radial])
      if (row < segments) {
        for (let side = 0; side < radial; side++) {
          const a = startIndex + row * (radial + 1) + side
          const b = a + radial + 1
          indices.push(a, a + 1, b, a + 1, b + 1, b)
        }
      }
    }
    // Caps are recessed at forks and minute at the very end of each twig.
    for (const end of [0, segments]) {
      const centre = axis.curve.getPointAt(end / segments)
      const centerIndex = positions.length / 3
      positions.push(centre.x, centre.y, centre.z)
      uvs.push(0, end ? length : 0)
      const ring = startIndex + end * (radial + 1)
      for (let side = 0; side < radial; side++) {
        if (end) indices.push(centerIndex, ring + side, ring + side + 1)
        else indices.push(centerIndex, ring + side + 1, ring + side)
      }
    }
  }

  // Generate the same axes in both modes so switching mesh detail does not
  // re-randomise the main branches; distant trees only lose some tiny shoots.
  const visibleAxes = axes.filter((axis, index) => near || axis.kind < 3 || index % 3 === 0)
  visibleAxes.forEach(sweep)
  const geometry = new T.BufferGeometry()
  geometry.setAttribute('position', new T.Float32BufferAttribute(positions, 3))
  geometry.setAttribute('uv', new T.Float32BufferAttribute(uvs, 2))
  geometry.setIndex(indices)
  geometry.computeVertexNormals()
  // Keep smooth lighting across the duplicated texture seam of every ring.
  const normals = geometry.attributes.normal
  for (const [a, b] of seamPairs) {
    const normal = new T.Vector3().fromBufferAttribute(normals, a)
      .add(new T.Vector3().fromBufferAttribute(normals, b)).normalize()
    normals.setXYZ(a, normal.x, normal.y, normal.z)
    normals.setXYZ(b, normal.x, normal.y, normal.z)
  }
  geometry.computeBoundingBox()
  geometry.computeBoundingSphere()
  geometry.userData = { kind: 'winter-deciduous', seed, detail, branchCount: visibleAxes.length, units: 'metres' }
  return geometry
}
