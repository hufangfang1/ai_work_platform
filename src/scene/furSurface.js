import * as THREE from 'three'

// A coat of fine ribbons, not cones: the surface stays smooth while the silhouette
// catches a soft, short pile. All strands share one material and one draw call.
const vertexShader = /* glsl */ `
  attribute vec3 strandRoot;
  attribute vec3 strandNormal;
  attribute vec3 strandTangent;
  attribute vec3 strandColor;
  attribute vec4 strandShape;

  varying vec2 vStrandUv;
  varying vec3 vStrandColor;
  varying float vEdge;
  varying float vLight;
  varying float vVariation;

  void main() {
    float t = position.y;
    vec3 n = normalize(strandNormal);
    vec3 tangent = normalize(strandTangent);
    float strandLength = strandShape.x;
    float strandWidth = strandShape.y;
    float bend = strandShape.z;
    // Start slightly inside the underlying sculpt so there are no floating roots.
    vec3 center = strandRoot + n * (strandLength * t - strandLength * 0.10);
    center += tangent * (strandLength * bend * t * t);
    center += cross(n, tangent) * (sin(t * 3.14159265) * strandLength * 0.065);

    vec4 mvCenter = modelViewMatrix * vec4(center, 1.0);
    vec3 viewNormal = normalize(normalMatrix * n);
    vec3 viewTangent = normalize(mat3(modelViewMatrix) * tangent);
    vec3 toCamera = normalize(-mvCenter.xyz);
    vec3 sideways = cross(viewNormal, toCamera);
    // Directly face-on strands use their stable tangent, avoiding a zero cross product.
    float facing = abs(dot(viewNormal, toCamera));
    sideways = mix(sideways, viewTangent, smoothstep(0.89, 0.99, facing));
    sideways -= toCamera * dot(sideways, toCamera);
    sideways = normalize(sideways + vec3(0.000001));

    float taper = pow(max(0.0, 1.0 - t), 0.7) * (0.7 + 0.3 * sin(t * 3.14159265));
    float scale = length(modelViewMatrix[0].xyz);
    mvCenter.xyz += sideways * position.x * strandWidth * taper * scale;

    vec3 keyDirection = normalize(mat3(viewMatrix) * vec3(-0.45, 0.8, 0.6));
    float softKey = dot(viewNormal, keyDirection) * 0.5 + 0.5;
    vLight = 0.94 + softKey * 0.23;
    vEdge = pow(1.0 - facing, 1.45);
    vVariation = strandShape.w;
    vStrandUv = vec2(position.x, t);
    vStrandColor = strandColor;
    gl_Position = projectionMatrix * mvCenter;
  }
`

const fragmentShader = /* glsl */ `
  uniform float warmth;
  uniform float opacity;
  varying vec2 vStrandUv;
  varying vec3 vStrandColor;
  varying float vEdge;
  varying float vLight;
  varying float vVariation;

  void main() {
    // Feather both edges; opaque dark strand edges read as speckles, not plush.
    float edgeSoftness = 1.0 - smoothstep(0.28, 1.0, abs(vStrandUv.x));
    float rootFade = smoothstep(0.0, 0.16, vStrandUv.y);
    float tipFade = 1.0 - smoothstep(0.7, 1.0, vStrandUv.y);
    float alpha = edgeSoftness * rootFade * tipFade;
    alpha *= mix(0.17, 0.88, vEdge) * opacity;
    if (alpha < 0.008) discard;

    float pileShade = mix(0.955, 1.065, vStrandUv.y);
    pileShade *= 0.985 + vVariation * 0.03;
    vec3 lightTint = mix(vec3(1.0), vec3(1.0, 0.91, 0.83), warmth);
    float roomLight = mix(1.0, 0.58, warmth);
    vec3 color = vStrandColor * pileShade * vLight * lightTint * roomLight;
    gl_FragColor = vec4(color, alpha);
    #include <tonemapping_fragment>
    #include <colorspace_fragment>
  }
`

function seededRandom(seed) {
  let state = Number(seed)
  if (!Number.isFinite(state)) {
    state = 2166136261
    for (const character of String(seed)) {
      state = Math.imul(state ^ character.charCodeAt(0), 16777619)
    }
  }
  state = state >>> 0
  return () => {
    state += 0x6d2b79f5
    let value = state
    value = Math.imul(value ^ (value >>> 15), value | 1)
    value ^= value + Math.imul(value ^ (value >>> 7), value | 61)
    return ((value ^ (value >>> 14)) >>> 0) / 4294967296
  }
}

/**
 * Creates a short plush coat in the source geometry's local coordinate system.
 * `density` is the total strand count (not strands per square unit).
 * `vertexColors: true` uses the source's linear-space RGB attribute, if present.
 * Owns only the returned geometry/material; it never disposes the source geometry.
 */
export function createFurSurface(baseGeometry, options = {}) {
  const position = baseGeometry.getAttribute('position')
  if (!position || position.itemSize < 3) throw new TypeError('Fur requires a position attribute.')

  const sourceNormal = baseGeometry.getAttribute('normal')
  const sourceColor = options.vertexColors ? baseGeometry.getAttribute('color') : null
  const index = baseGeometry.getIndex()
  const primitiveCount = index ? index.count : position.count
  const drawStart = Math.max(0, baseGeometry.drawRange.start || 0)
  const drawEnd = Math.min(primitiveCount, drawStart + baseGeometry.drawRange.count)
  const requestedCount = Math.min(30000, Math.max(0, Math.floor(options.density ?? 9000)))
  const length = Math.max(0.0001, options.length ?? 0.035)
  const rootColor = new THREE.Color(options.color ?? '#fff2dd')
  const random = seededRandom(options.seed ?? 61823)
  const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3()
  const ab = new THREE.Vector3(), ac = new THREE.Vector3(), faceNormal = new THREE.Vector3()
  const normal = new THREE.Vector3(), tangent = new THREE.Vector3(), bitangent = new THREE.Vector3()
  const root = new THREE.Vector3(), helper = new THREE.Vector3()
  const triangleIndices = [], cumulativeAreas = []
  let totalArea = 0

  // MarchingCubes allocates larger buffers than its active draw range, so skip
  // inactive/degenerate faces rather than accidentally coating the origin.
  for (let offset = drawStart; offset + 2 < drawEnd; offset += 3) {
    const ia = index ? index.getX(offset) : offset
    const ib = index ? index.getX(offset + 1) : offset + 1
    const ic = index ? index.getX(offset + 2) : offset + 2
    a.fromBufferAttribute(position, ia)
    b.fromBufferAttribute(position, ib)
    c.fromBufferAttribute(position, ic)
    const area = ab.subVectors(b, a).cross(ac.subVectors(c, a)).length() * 0.5
    if (!Number.isFinite(area) || area < 1e-12) continue
    totalArea += area
    triangleIndices.push(ia, ib, ic)
    cumulativeAreas.push(totalArea)
  }

  const count = totalArea > 0 ? requestedCount : 0
  const roots = new Float32Array(count * 3)
  const normals = new Float32Array(count * 3)
  const tangents = new Float32Array(count * 3)
  const colors = new Float32Array(count * 3)
  const shapes = new Float32Array(count * 4)
  const bounds = new THREE.Box3()

  for (let strand = 0; strand < count; strand++) {
    const target = random() * totalArea
    let low = 0, high = cumulativeAreas.length - 1
    while (low < high) {
      const middle = (low + high) >>> 1
      if (target < cumulativeAreas[middle]) high = middle
      else low = middle + 1
    }
    const ia = triangleIndices[low * 3]
    const ib = triangleIndices[low * 3 + 1]
    const ic = triangleIndices[low * 3 + 2]
    a.fromBufferAttribute(position, ia)
    b.fromBufferAttribute(position, ib)
    c.fromBufferAttribute(position, ic)
    const squareRoot = Math.sqrt(random())
    const u = 1 - squareRoot, v = squareRoot * (1 - random()), w = 1 - u - v
    root.copy(a).multiplyScalar(u).addScaledVector(b, v).addScaledVector(c, w)
    faceNormal.subVectors(b, a).cross(ac.subVectors(c, a)).normalize()
    normal.set(0, 0, 0)
    if (sourceNormal) {
      normal.fromBufferAttribute(sourceNormal, ia).multiplyScalar(u)
      normal.addScaledVector(helper.fromBufferAttribute(sourceNormal, ib), v)
      normal.addScaledVector(helper.fromBufferAttribute(sourceNormal, ic), w)
    }
    if (normal.lengthSq() < 0.01) normal.copy(faceNormal)
    normal.normalize()
    helper.set(Math.abs(normal.y) < 0.85 ? 0 : 1, Math.abs(normal.y) < 0.85 ? 1 : 0, 0)
    tangent.crossVectors(normal, helper).normalize()
    bitangent.crossVectors(normal, tangent)
    const angle = random() * Math.PI * 2
    tangent.multiplyScalar(Math.cos(angle)).addScaledVector(bitangent, Math.sin(angle))
    root.toArray(roots, strand * 3)
    normal.toArray(normals, strand * 3)
    tangent.toArray(tangents, strand * 3)
    bounds.expandByPoint(root)

    for (let channel = 0; channel < 3; channel++) {
      const getter = channel === 0 ? 'getX' : channel === 1 ? 'getY' : 'getZ'
      colors[strand * 3 + channel] = sourceColor
        ? sourceColor[getter](ia) * u + sourceColor[getter](ib) * v + sourceColor[getter](ic) * w
        : channel === 0 ? rootColor.r : channel === 1 ? rootColor.g : rootColor.b
    }
    const strandLength = length * (0.64 + random() * 0.72)
    shapes[strand * 4] = strandLength
    shapes[strand * 4 + 1] = strandLength * (0.095 + random() * 0.085) * (options.width ?? 1)
    shapes[strand * 4 + 2] = 0.16 + random() * 0.38
    shapes[strand * 4 + 3] = random()
  }

  const geometry = new THREE.InstancedBufferGeometry()
  const vertices = [], indices = []
  const segments = 4
  for (let step = 0; step <= segments; step++) {
    vertices.push(-1, step / segments, 0, 1, step / segments, 0)
    if (step < segments) {
      const offset = step * 2
      indices.push(offset, offset + 1, offset + 2, offset + 2, offset + 1, offset + 3)
    }
  }
  geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3))
  geometry.setIndex(indices)
  geometry.setAttribute('strandRoot', new THREE.InstancedBufferAttribute(roots, 3))
  geometry.setAttribute('strandNormal', new THREE.InstancedBufferAttribute(normals, 3))
  geometry.setAttribute('strandTangent', new THREE.InstancedBufferAttribute(tangents, 3))
  geometry.setAttribute('strandColor', new THREE.InstancedBufferAttribute(colors, 3))
  geometry.setAttribute('strandShape', new THREE.InstancedBufferAttribute(shapes, 4))
  geometry.instanceCount = count
  if (count) bounds.expandByScalar(length * 2)
  else bounds.set(new THREE.Vector3(), new THREE.Vector3())
  geometry.boundingBox = bounds
  geometry.boundingSphere = bounds.getBoundingSphere(new THREE.Sphere())

  const material = new THREE.ShaderMaterial({
    name: 'Momo soft ribbon pile',
    vertexShader,
    fragmentShader,
    uniforms: {
      warmth: { value: 0 },
      opacity: { value: options.opacity ?? 1 },
    },
    transparent: true,
    depthWrite: false,
    side: THREE.DoubleSide,
    toneMapped: true,
  })
  const object = new THREE.Mesh(geometry, material)
  object.name = 'Momo soft fur'
  object.renderOrder = 1
  object.userData.strandCount = count
  // Interaction belongs to the continuous source sculpt, never to individual hairs.
  object.raycast = () => {}
  let disposed = false

  return {
    object,
    setWarmth(dim) {
      material.uniforms.warmth.value = THREE.MathUtils.clamp(Number(dim) || 0, 0, 1)
    },
    dispose() {
      if (disposed) return
      disposed = true
      object.removeFromParent()
      geometry.dispose()
      material.dispose()
    },
  }
}
