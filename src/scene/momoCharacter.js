import * as THREE from 'three'
import { sculptCloud, sculptEar } from './cloudSculpt'
import { createFurSurface } from './furSurface'

export function createMomoCharacter() {
  const geometries = new Set(), materials = new Set(), textures = new Set(), coats = []
  const geo = value => { geometries.add(value); return value }
  const material = value => { materials.add(value); return value }
  const standard = (color, options = {}) => material(new THREE.MeshStandardMaterial({ color, roughness: .85, ...options }))
  const cream = material(new THREE.MeshPhysicalMaterial({ color: '#f4e5c9', roughness: .95, sheen: 1, sheenColor: '#fff3df', sheenRoughness: .85 }))
  const sphere = geo(new THREE.SphereGeometry(1, 40, 28))
  function group(parent, at = [0, 0, 0]) {
    const result = new THREE.Group(); result.position.set(...at); parent?.add(result); return result
  }
  function mesh(parent, geometry, mat, at = [0, 0, 0], scale = [1, 1, 1]) {
    const result = new THREE.Mesh(geometry, mat); result.position.set(...at); result.scale.set(...scale)
    result.castShadow = true; result.receiveShadow = true; parent.add(result); return result
  }
  function puff(parent, at, scale, density = 800) {
    const geometry = geo(sphere.clone()); geometry.scale(...scale)
    const item = group(parent, at); mesh(item, geometry, cream)
    const fur = createFurSurface(geometry, { density, length: .022, width: .6, opacity: .8, color: '#f6e8d0', seed: 41 + density })
    item.add(fur.object); coats.push(fur); return item
  }
  function line(parent, points, width, mat) {
    return mesh(parent, geo(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points.map(p => new THREE.Vector3(...p))), 24, width, 8, false)), mat)
  }
  function radialMap(color, opacity) {
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = 128
    const ctx = canvas.getContext('2d'), gradient = ctx.createRadialGradient(64,64,0,64,64,64)
    gradient.addColorStop(0, `rgba(${color},${opacity})`); gradient.addColorStop(.45, `rgba(${color},${opacity*.5})`); gradient.addColorStop(1, `rgba(${color},0)`)
    ctx.fillStyle=gradient;ctx.fillRect(0,0,128,128)
    const map = new THREE.CanvasTexture(canvas); map.colorSpace = THREE.SRGBColorSpace; textures.add(map); return map
  }

  const root = group(null), body = group(root, [0, .94, 0])
  const bodyGeometry = geo(sculptCloud())
  const bodyMesh = mesh(body, bodyGeometry, cream)
  const bodyCoat = createFurSurface(bodyGeometry, { density: 16000, length: .031, width: .6, opacity: .8, color: '#f6e8d0', seed: 53 })
  body.add(bodyCoat.object); coats.push(bodyCoat)
  const ears = [-1, 1].map(sign => {
    const ear = group(body, [sign * .82, .40, -.02]), geometry = geo(sculptEar(sign))
    mesh(ear, geometry, material(new THREE.MeshPhysicalMaterial({ vertexColors: true, color: '#ffffff', roughness: .96, sheen: .8, sheenColor: '#ffeddd' })))
    const coat = createFurSurface(geometry, { density: 2000, length: .024, width: .6, opacity: .8, vertexColors: true, seed: sign + 7 })
    ear.add(coat.object); coats.push(coat); return ear
  })
  const feet = [-1, 1].map(sign => puff(body, [sign * .34, -.80, .27], [.25, .15, .29]))
  const paws = [-1, 1].map(sign => {
    const pivot = group(body, [sign * .48, -.26, .51])
    puff(pivot, [-sign * .115, -.14, .20], [.195, .22, .185], 950)
    return pivot
  })
  const tail = puff(body, [.8, -.53, -.3], [.30, .27, .3], 900)
  const face = group(body, [0, .15, 0])
  const eyeMat = material(new THREE.MeshPhysicalMaterial({ color: '#34241f', roughness: .22, clearcoat: 1, clearcoatRoughness: .1 }))
  const ink = standard('#654333', { roughness: .55 })
  const highlights = material(new THREE.MeshBasicMaterial({ color: '#fff5dc' }))
  const eyes = [-1, 1].map(sign => {
    const pivot = group(face, [sign * .305, .19, .654]); pivot.rotation.y = sign * .10
    mesh(pivot, sphere, eyeMat, [0, 0, 0], [.14, .174, .057])
    mesh(pivot, sphere, standard('#9f6736', { roughness: .32 }), [0, -.073, .044], [.094, .067, .018])
    mesh(pivot, sphere, eyeMat, [0, .012, .048], [.084, .119, .015])
    mesh(pivot, sphere, highlights, [-.035, .063, .065], [.032, .04, .01])
    mesh(pivot, sphere, highlights, [.038, -.047, .064], [.013, .015, .007])
    return pivot
  })
  const lids = [-1, 1].map(sign => {
    const lid = group(face, [sign * .305, .185, .718])
    line(lid, [[-.12,-.005,-.008],[-.065,.046,.005],[.015,.054,.012],[.115,0,-.008]], .014, ink)
    lid.visible = false; return lid
  })
  const brows = [-1, 1].map(sign => {
    const brow = group(face, [sign * .31, .45, .55]); brow.rotation.z = -sign * .1
    line(brow, [[-.065,0,0],[0,.02,.008],[.07,0,0]], .009, standard('#b49473')); return brow
  })
  const blush = radialMap('229,139,113', .75)
  const cheekMaterial = material(new THREE.MeshBasicMaterial({ map: blush, transparent: true, depthWrite: false }))
  ;[-1,1].forEach(sign => {
    const cheek = mesh(face, geo(new THREE.PlaneGeometry(.40,.23)), cheekMaterial, [sign*.52,-.005,.627])
    cheek.rotation.y = sign * .25; cheek.castShadow = false
  })
  // A very small muzzle; the eyes and the heart remain the visual anchors.
  mesh(face, sphere, standard('#b28b72'), [0,.028,.715], [.038,.024,.019])
  const mouth = group(face, [0,-.028,.704])
  const mouthLine = line(mouth, [[-.07,.005,0],[-.033,-.023,.006],[0,-.009,.01],[.033,-.023,.006],[.07,.005,0]], .010, ink)
  const smileShape = new THREE.Shape(); smileShape.moveTo(-.077,-.006); smileShape.quadraticCurveTo(0,-.024,.077,-.006); smileShape.bezierCurveTo(.055,-.116,-.055,-.116,-.077,-.006)
  const smile = mesh(mouth, geo(new THREE.ShapeGeometry(smileShape,20)), standard('#794b37'), [0,0,.015]); smile.visible=false
  const tongue = mesh(mouth, sphere, standard('#dfaa90'), [0,-.078,.028], [.036,.018,.006]); tongue.visible=false

  const heartShape = new THREE.Shape()
  heartShape.moveTo(0,-.26); heartShape.bezierCurveTo(-.50,.03,-.29,.42,0,.205); heartShape.bezierCurveTo(.29,.42,.50,.03,0,-.26)
  const heartMaterial = material(new THREE.MeshPhysicalMaterial({ color:'#eba343',emissive:'#e88b25',emissiveIntensity:.28,roughness:.3,clearcoat:.65,clearcoatRoughness:.24 }))
  const heartEdge = material(new THREE.MeshPhysicalMaterial({ color:'#b97432',emissive:'#d98624',emissiveIntensity:.18,roughness:.38,clearcoat:.45 }))
  const heart = mesh(body, geo(new THREE.ExtrudeGeometry(heartShape,{depth:.09,bevelEnabled:true,bevelSize:.045,bevelThickness:.06,bevelSegments:6,curveSegments:36,steps:1})),[heartMaterial,heartEdge],[0,-.39,.735],[.9,.9,.9])
  const haloMap = radialMap('255,176,57', .7)
  const haloMat = material(new THREE.SpriteMaterial({ map:haloMap,transparent:true,depthWrite:false,blending:THREE.AdditiveBlending,opacity:.5 }))
  const halo = new THREE.Sprite(haloMat);halo.position.set(0,-.35,.94);halo.scale.set(1.2,1.2,1);body.add(halo)
  // Warm spill is part of the character shader, not an extra dynamic light.
  cream.onBeforeCompile = shader => {
    shader.vertexShader = 'varying vec3 vMomoLocal;\n'+shader.vertexShader.replace('#include <begin_vertex>','#include <begin_vertex>\nvMomoLocal = position;')
    shader.fragmentShader = 'varying vec3 vMomoLocal;\n'+shader.fragmentShader.replace('#include <emissivemap_fragment>','#include <emissivemap_fragment>\nfloat heartFalloff = exp(-5.5 * distance(vMomoLocal,vec3(0.0,-0.39,0.735)));\ntotalEmissiveRadiance += vec3(0.52,0.19,0.025)*heartFalloff;')
  }
  cream.customProgramCacheKey = () => 'momo-warm-spill-v1'
  root.traverse(o => { if(o.isMesh && !o.isInstancedMesh) o.userData.action = 'pet' })
  // Only the solid form participates in picking; fine fibres don't need rays.
  const pickables = [bodyMesh]
  ears.forEach(e => pickables.push(e.children[0]))
  paws.forEach(p => pickables.push(p.children[0].children[0]))
  pickables.push(heart)
  const damp = (a,b,dt,s=8) => THREE.MathUtils.damp(a,b,s,dt)

  function update({t, a, action, pointer, crouch, lean, paw, happy, resting, motion, dim}, dt) {
    const pet = action === 'pet', noticed = pet && a < .55, enjoying = pet && a > 1.05 && a < 3.65
    const settle = pet ? Math.sin(THREE.MathUtils.clamp((a-.35)/4.1,0,1)*Math.PI) : 0
    const turn = pointer.x * .11 * motion
    body.position.y = damp(body.position.y,.94-crouch*.15+(noticed?.05*motion:0),dt)
    body.rotation.x = damp(body.rotation.x,lean + crouch*.11 - settle*.095*motion,dt)
    body.rotation.y = damp(body.rotation.y,turn,dt,4)
    body.rotation.z = damp(body.rotation.z,pet?settle*.13*motion:Math.sin(t*.7)*.016*motion,dt,5)
    const breath = Math.sin(t*1.65)*.008*motion
    body.scale.set(1+breath+settle*.012*motion,1-breath-settle*.028*motion,1)
    const closed = resting || enjoying || (happy && action !== 'wave' && !noticed && !pet)
    const blinkCycle = t % 4.7, blinking = blinkCycle < .16
    eyes.forEach(eye => {eye.visible=!closed;eye.scale.y=noticed?1.08:blinking?.12:1})
    lids.forEach(lid=>{lid.visible=closed;lid.scale.y=resting?-1:1})
    brows.forEach((b,i)=>{b.position.y=damp(b.position.y,noticed?.51:.45,dt);b.rotation.z=damp(b.rotation.z,(i?1:-1)*(noticed?-.24:-.1),dt)})
    smile.visible = tongue.visible = !resting && (enjoying || (happy && !pet)); mouthLine.visible=!smile.visible
    ears.forEach((ear,i)=>{
      const sign=i?1:-1
      const relaxation=resting?.16:enjoying?.12:noticed?-.16:0
      ear.rotation.z=damp(ear.rotation.z,-sign*(.10+relaxation)+Math.sin(t*1.5+i)*.032*motion,dt,4)
      ear.rotation.x=damp(ear.rotation.x,-lean*.6+Math.sin(t*1.3+i)*.025*motion,dt,4)
    })
    paws.forEach((p,i)=>{
      const sign=i?1:-1, lift=(i===1?paw:paw*.52)
      p.rotation.z=damp(p.rotation.z,sign*(.12+lift-enjoying*.12),dt,7)
      p.rotation.x=damp(p.rotation.x,-crouch*.45-enjoying*.12,dt,5)
    })
    feet.forEach((foot,i)=>{foot.position.y=damp(foot.position.y,-.8+crouch*.15,dt);foot.position.z=damp(foot.position.z,.27+crouch*.12,dt);foot.rotation.x=action==='play'?Math.sin(t*8+i*Math.PI)*.23*motion:0})
    tail.rotation.y=Math.sin(t*(happy?5:1.8))*.15*motion
    const beat = Math.pow(Math.max(0,Math.sin(t*2.7)),5)
    heart.scale.setScalar(.9+beat*.018*motion+(enjoying?.025:0))
    heartMaterial.emissiveIntensity=.24+beat*.13+(enjoying?.25:0)
    haloMat.opacity=.15+beat*.05+(enjoying?.12:0)
    coats.forEach(coat=>coat.setWarmth(dim?1:0))
  }
  return { root, pickables, update, dispose(){coats.forEach(c=>c.dispose());geometries.forEach(g=>g.dispose());materials.forEach(m=>m.dispose());textures.forEach(t=>t.dispose())} }
}
