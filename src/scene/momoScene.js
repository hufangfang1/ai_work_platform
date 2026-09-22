import * as THREE from 'three'
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js'
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js'
import { createMomoCharacter } from './momoCharacter'

// Articulated, real-time geometry. No image of the character is used here.
export async function createMomoScene(host, initial, onAction) {
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'default' })
  renderer.setPixelRatio(Math.min(devicePixelRatio, 1.75))
  renderer.outputColorSpace = THREE.SRGBColorSpace
  renderer.toneMapping = THREE.ACESFilmicToneMapping
  renderer.toneMappingExposure = 1.0
  renderer.shadowMap.enabled = true
  renderer.shadowMap.type = THREE.VSMShadowMap
  renderer.domElement.setAttribute('aria-label', '可以摸摸头、陪着玩耍的立体 Momo')
  host.appendChild(renderer.domElement)
  const scene = new THREE.Scene()
  const camera = new THREE.OrthographicCamera(-5, 5, 2.5, -2.5, .1, 40)
  const pmrem = new THREE.PMREMGenerator(renderer)
  const studio = new RoomEnvironment()
  const env = pmrem.fromScene(studio, .04)
  scene.environment = env.texture
  scene.environmentIntensity = .28
  studio.dispose(); pmrem.dispose()
  const geometries = new Set(), materials = new Set(), textures = new Set()
  const geo = g => { geometries.add(g); return g }
  const keep = m => { materials.add(m); return m }
  const mat = (color, extra = {}) => keep(new THREE.MeshStandardMaterial({ color, roughness: .78, ...extra }))
  let seed = 173
  const random = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296 }
  function texture(draw, size = 256, color = false) {
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = size
    draw(canvas.getContext('2d'), size)
    const t = new THREE.CanvasTexture(canvas)
    if (color) t.colorSpace = THREE.SRGBColorSpace
    textures.add(t); return t
  }
  const weave = texture((c, s) => {
    c.fillStyle = '#888'; c.fillRect(0, 0, s, s)
    for (let i = 0; i < 22000; i++) {
      const v = Math.floor(85 + random() * 95); c.strokeStyle = `rgb(${v},${v},${v})`
      const x = random() * s, y = random() * s
      c.beginPath(); c.moveTo(x, y); c.lineTo(x + random() * 2 - 1, y + 1 + random() * 3); c.stroke()
    }
  })
  weave.wrapS = weave.wrapT = THREE.RepeatWrapping; weave.repeat.set(5, 4)
  const fabric = color => keep(new THREE.MeshPhysicalMaterial({ color, roughness: 1, sheen: .65, sheenColor: '#fff1df', sheenRoughness: 1, bumpMap: weave, bumpScale: .018 }))
  const cream = mat('#f3debc'), wood = mat('#b37d5b'), porcelain = mat('#f9e9d5', { roughness: .33 })
  const sphere = geo(new THREE.SphereGeometry(1, 48, 32))
  const roundedBox = geo(new RoundedBoxGeometry(1, 1, 1, 3, .12))
  const plane = geo(new THREE.PlaneGeometry(1, 1))
  function mesh(parent, geometry, material, p = [0, 0, 0], s = [1, 1, 1]) {
    const m = new THREE.Mesh(geometry, material); m.position.set(...p); m.scale.set(...s)
    m.castShadow = true; m.receiveShadow = true; parent.add(m); return m
  }
  const ball = (parent, material, p, s) => mesh(parent, sphere, material, p, s)
  const box = (parent, material, p, s) => mesh(parent, roundedBox, material, p, s)
  const group = (parent, p = [0, 0, 0]) => { const g = new THREE.Group(); g.position.set(...p); parent.add(g); return g }
  function tube(parent, points, radius, material, segments = 40) {
    return mesh(parent, geo(new THREE.TubeGeometry(new THREE.CatmullRomCurve3(points.map(p => new THREE.Vector3(...p))), segments, radius, 8, false)), material)
  }
  function softDisc(parent, color, p, size, opacity) {
    const map = texture((c, s) => {
      const g = c.createRadialGradient(s / 2, s / 2, 0, s / 2, s / 2, s / 2)
      g.addColorStop(0, 'rgba(255,255,255,1)'); g.addColorStop(.35, 'rgba(255,255,255,.55)'); g.addColorStop(1, 'rgba(255,255,255,0)')
      c.fillStyle = g; c.fillRect(0, 0, s, s)
    }, 128)
    const m = mesh(parent, plane, keep(new THREE.MeshBasicMaterial({ color, map, transparent: true, opacity, depthWrite: false })), p, size)
    m.castShadow = m.receiveShadow = false; return m
  }
  const hemi = new THREE.HemisphereLight('#f8efe0', '#78948c', .85); scene.add(hemi)
  const key = new THREE.DirectionalLight('#ffe4bd', 2.15)
  key.position.set(-3.5, 8, 5); key.target.position.set(0, 1, 0); scene.add(key, key.target)
  key.castShadow = true; key.shadow.mapSize.set(1024, 1024)
  key.shadow.radius = 8; key.shadow.blurSamples = 12
  Object.assign(key.shadow.camera, { left: -5, right: 5, top: 5, bottom: -4, near: .5, far: 16 })
  key.shadow.normalBias = .025; key.shadow.bias = -.00015
  const rim = new THREE.DirectionalLight('#d9f0df', 1.3); rim.position.set(3, 4, -3); scene.add(rim)
  const fill = new THREE.DirectionalLight('#fff7f0', .3); fill.position.set(1, 2, 6); scene.add(fill)
  // Soft-edged floor, without a hard model-stand silhouette.
  const floorWash = softDisc(scene, '#d6b69c', [0, -.035, -.35], [13, 8, 1], .45); floorWash.rotation.x = -Math.PI / 2
  softDisc(scene, '#aab8a4', [0, 1.65, -2.8], [10, 6, 1], .52)
  const ground = mesh(scene, plane, keep(new THREE.ShadowMaterial({ color: '#77563f', opacity: .09 })), [0, -.02, 0], [200, 200, 1])
  ground.rotation.x = -Math.PI / 2; ground.castShadow = false
  const contact = softDisc(scene, '#715143', [0, .004, .4], [2.5, 1.6, 1], .22); contact.rotation.x = -Math.PI / 2
  const rug = group(scene, [0, .015, .5])
  const rugMat = fabric('#b9a58b'), rugEdge = fabric('#e5d5b7')
  ball(rug, rugMat, [0, .025, 0], [2.45, .06, 1.45])
  for (let i = 0; i < 4; i++) {
    const ring = mesh(rug, geo(new THREE.TorusGeometry(1 - i * .028, .007, 5, 128)), rugEdge, [0, .082, 0], [2.36, 1.37, 1])
    ring.rotation.x = -Math.PI / 2
  }
  for (let i = 0; i < 48; i++) {
    const a = i / 48 * Math.PI * 2
    tube(rug, [[Math.cos(a) * 2.42, .04, Math.sin(a) * 1.43], [Math.cos(a) * 2.51, .028, Math.sin(a) * 1.49]], .013, rugEdge, 3)
  }
  rug.scale.setScalar(initial.rug ? 1 : .001)
  const seat = group(scene, [1.55, .19, .7]), seatMat = fabric('#859b8c')
  mesh(seat, geo(new RoundedBoxGeometry(1, .32, .84, 6, .15)), seatMat)
  const seam=[]
  for(let i=0;i<=80;i++){const a=i/80*Math.PI*2;seam.push([Math.sign(Math.cos(a))*Math.pow(Math.abs(Math.cos(a)),.55)*.485,0,Math.sign(Math.sin(a))*Math.pow(Math.abs(Math.sin(a)),.55)*.407])}
  tube(seat,seam,.008,mat('#b5c2ac'),80)
  ball(seat, seatMat, [0, .158, 0], [.03, .009, .03]); seat.visible = initial.rug
  // The arch is a view onto the sky, not a wall across the whole screen.
  const windowGroup = group(scene, [-2.45, 1.93, -1.75])
  windowGroup.scale.setScalar(.88); windowGroup.rotation.y = .08
  const archShape = (w, bottom, spring) => {
    const s = new THREE.Shape(); s.moveTo(-w, bottom); s.lineTo(w, bottom); s.lineTo(w, spring); s.absarc(0, spring, w, 0, Math.PI, false); s.closePath(); return s
  }
  const arch = geo(new THREE.ExtrudeGeometry(archShape(1.1, -1.4, .25), { depth: .15, bevelEnabled: true, bevelSize: .065, bevelThickness: .07, bevelSegments: 4, curveSegments: 48, steps: 1 }))
  mesh(windowGroup, arch, mat('#9aa99a'), [0, 0, -.13])
  const skyGeo = geo(new THREE.ShapeGeometry(archShape(.97, -1.26, .25), 48)), uv = skyGeo.attributes.uv, positions = skyGeo.attributes.position
  for (let i = 0; i < uv.count; i++) uv.setXY(i, (positions.getX(i) + .97) / 1.94, (positions.getY(i) + 1.26) / 2.48)
  const skyMap = texture((c, s) => {
    const g = c.createLinearGradient(0, 0, 0, s); g.addColorStop(0, '#bfd9dc'); g.addColorStop(.6, '#e6ddd2'); g.addColorStop(1, '#f3d5b4'); c.fillStyle = g; c.fillRect(0, 0, s, s)
  }, 128, true)
  const skyMat = keep(new THREE.MeshBasicMaterial({ map: skyMap, color: '#ffffff' }))
  const sky = mesh(windowGroup, skyGeo, skyMat, [0, 0, .13]); sky.castShadow = false
  const sun = ball(windowGroup, mat('#ffe3a7', { emissive: '#ffdb96', emissiveIntensity: .7 }), [.43, .65, .18], [.23, .23, .025]); sun.castShadow = false
  const cloudMat = mat('#fff5e6', { roughness: 1 }), clouds = group(windowGroup, [-.25, .18, .19])
  ;[[-.25,0,0,.3,.085],[0,.06,0,.27,.14],[.25,0,0,.25,.075]].forEach(([x,y,z,sx,sy]) => { const c = ball(clouds, cloudMat, [x,y,z], [sx,sy,.025]); c.castShadow = false })
  box(windowGroup, cream, [0, -.34, .24], [2.02, .055, .12])
  box(windowGroup, cream, [0, -.04, .24], [.055, 2.65, .12])
  box(windowGroup, porcelain, [0, -1.39, .2], [2.48, .16, .52])
  const curtainMat = fabric('#e5dbc5'); curtainMat.side = THREE.DoubleSide
  const curtains = [-1, 1].map(sign => {
    const g = geo(new THREE.PlaneGeometry(.4, 2.28, 24, 40)), p = g.attributes.position
    for (let i = 0; i < p.count; i++) { const x=p.getX(i),y=p.getY(i); p.setXYZ(i,x+sign*.075*Math.cos(y*1.4),y+.04*Math.cos(x*35),.055*Math.cos(x*38)) }
    g.computeVertexNormals(); return mesh(windowGroup, g, curtainMat, [sign * .84, -.1, .34])
  })
  windowGroup.traverse(o => { if (o.isMesh) o.castShadow = false })
  // A mushroom reading light, a cup, a sprig: restrained signs of life.
  const sideTable = group(scene, [2.8, 0, -.55])
  const tableTop = mesh(sideTable, geo(new THREE.CylinderGeometry(.64, .64, .1, 64)), wood, [0, .75, 0])
  ;[-1,1].forEach(sign=>{const leg=box(sideTable,wood,[sign*.36,.36,0],[.07,.7,.08]);leg.rotation.z=-sign*.12})
  const lamp = group(sideTable, [.12,.8,-.04])
  ball(lamp,porcelain,[0,.08,0],[.22,.085,.2])
  const stem=mesh(lamp,geo(new THREE.CylinderGeometry(.08,.13,.65,32)),porcelain,[0,.37,0])
  const shadeMat=mat('#dfad87',{emissive:'#e69d63',emissiveIntensity:.12,roughness:.48,side:THREE.DoubleSide})
  const shade=mesh(lamp,geo(new THREE.SphereGeometry(.53,64,32,0,Math.PI*2,0,Math.PI/2)),shadeMat,[0,.66,0],[1,.64,1])
  const rimRing=mesh(lamp,geo(new THREE.TorusGeometry(.517,.022,8,80)),porcelain,[0,.655,0]);rimRing.rotation.x=Math.PI/2
  const lampLight=new THREE.PointLight('#ffc68e',1.4,5);lampLight.position.set(2.92,1.44,-.59);scene.add(lampLight)
  const glow=softDisc(scene,'#ffc27e',[2.88,1.35,-.5],[1.5,1.5,1],.16)
  const cup=group(sideTable,[-.35,.87,.18])
  mesh(cup,geo(new THREE.CylinderGeometry(.13,.105,.18,40)),mat('#a5b5a7'),[0,0,0])
  const tea=mesh(cup,geo(new THREE.CircleGeometry(.112,32)),mat('#8f6953'),[0,.094,0]);tea.rotation.x=-Math.PI/2
  const handle=mesh(cup,geo(new THREE.TorusGeometry(.07,.018,8,32)),mat('#a5b5a7'),[-.14,0,0]);handle.scale.set(1,1.1,1)
  const books=group(sideTable,[0,.09,.1])
  ;['#c39183','#e5d6b8','#9eaca0'].forEach((c,i)=>{const b=box(books,mat(c),[0,i*.095,0],[.72,.085,.5]);b.rotation.y=i*.12})
  const plant=group(scene,[-3.05,0,.25])
  const vaseCurve=new THREE.SplineCurve([new THREE.Vector2(0,0),new THREE.Vector2(.22,0),new THREE.Vector2(.31,.1),new THREE.Vector2(.29,.4),new THREE.Vector2(.16,.56),new THREE.Vector2(.15,.61)])
  mesh(plant,geo(new THREE.LatheGeometry(vaseCurve.getPoints(40),48)),mat('#c99d85'))
  const green=mat('#91a080'),stemMat=mat('#899078')
  for(let i=0;i<5;i++){
    const sign=i%2?1:-1,x=sign*(.15+i*.07),y=.82+i*.13,z=Math.sin(i)*.15
    tube(plant,[[0,.4,0],[x*.4,y*.75,z*.4],[x,y,z]],.013,stemMat)
    const leaf=ball(plant,green,[x,y,z],[.12,.27,.035]);leaf.rotation.z=-sign*.55;leaf.rotation.y=i*.4
  }
  const character = createMomoCharacter()
  const root = character.root
  root.position.set(-.25, .12, .45); root.scale.setScalar(1.18); scene.add(root)
  const toy=mesh(scene,geo(new THREE.IcosahedronGeometry(.13,1)),mat('#f3e7d6',{flatShading:true}),[1.7,.2,1.6]);toy.visible=false
  const particles=group(scene),sparkMat=keep(new THREE.MeshBasicMaterial({color:'#f2bd75'})),particleGeo=geo(new THREE.IcosahedronGeometry(1,0))
  for(let i=0;i<9;i++)mesh(particles,particleGeo,sparkMat,[0,0,0],[.02,.02,.02]);particles.visible=false
  const dust=group(scene)
  const dustMat=keep(new THREE.MeshBasicMaterial({color:'#e7c796',transparent:true,opacity:.4}))
  for(let i=0;i<18;i++){const p=mesh(dust,particleGeo,dustMat,[-3+random()*6,.5+random()*3,-1+random()*2],[.006,.006,.006]);p.userData.phase=random()*Math.PI*2;p.userData.start=p.position.clone();p.castShadow=false}

  let state={rug:initial.rug,dim:initial.dim,seated:false},action='idle',actionTime=0,elapsed=0,lastTime=0,disposed=false
  const cues=new Set()
  let inView=true,active=!document.hidden
  const reduced=matchMedia('(prefers-reduced-motion: reduce)'),pointer=new THREE.Vector2(),ray=new THREE.Raycaster(),hits=[],targets=[]
  targets.push(...character.pickables)
  ;[shade,stem,tableTop].forEach(o=>{o.userData.action='lamp';targets.push(o)})
  seat.traverse(o=>{if(o.isMesh){o.userData.action='sit';targets.push(o)}})
  let pointerDirty=false,down=null,horizontalRoom=1
  function pointerMove(e){const r=host.getBoundingClientRect();pointer.set((e.clientX-r.left)/r.width*2-1,-(e.clientY-r.top)/r.height*2+1);pointerDirty=true}
  function visible(o){while(o){if(!o.visible)return false;o=o.parent}return true}
  function pick(){scene.updateMatrixWorld();ray.setFromCamera(pointer,camera);hits.length=0;ray.intersectObjects(targets,false,hits);return hits.find(h=>visible(h.object))?.object.userData.action}
  function pointerDown(e){pointerMove(e);down=[e.clientX,e.clientY]}
  function pointerUp(e){if(down&&Math.hypot(e.clientX-down[0],e.clientY-down[1])<12){pointerMove(e);const picked=pick();if(picked)onAction(picked)}down=null}
  function pointerLeave(){pointer.set(0,0);down=null;host.style.cursor='default'}
  host.addEventListener('pointermove',pointerMove);host.addEventListener('pointerdown',pointerDown);host.addEventListener('pointerup',pointerUp);host.addEventListener('pointerleave',pointerLeave);host.addEventListener('pointercancel',pointerLeave)
  function resize(){
    const w=host.clientWidth,h=host.clientHeight;if(!w||!h)return
    const aspect=w/h,half=aspect<1?2.7:2.38,compact=w<620
    root.scale.setScalar(Math.min(compact?1.20:1.40,(half*aspect-.3)/1.65))
    camera.left=-half*aspect;camera.right=half*aspect;camera.top=half;camera.bottom=-half
    horizontalRoom=Math.max(.1,camera.right-root.scale.x*1.65-.08)
    camera.position.set(.05,3.0,11);camera.lookAt(0,1.36,0);camera.updateProjectionMatrix();renderer.setSize(w,h,false)
    windowGroup.position.x=compact?-2.05:-2.45;sideTable.position.x=compact?2.3:2.8
    lampLight.position.x=sideTable.position.x+.12;glow.position.x=lampLight.position.x;plant.position.x=compact?-2.6:-3.05
  }
  const observer=new ResizeObserver(resize);observer.observe(host);resize()
  const visibility=()=>{active=!document.hidden&&inView;lastTime=0};document.addEventListener('visibilitychange',visibility)
  const viewObserver=new IntersectionObserver(entries=>{inView=entries[0].isIntersecting;visibility()});viewObserver.observe(host)
  const damp=(a,b,dt,s=6)=>THREE.MathUtils.damp(a,b,s,dt),daylight=new THREE.Color('#ffffff'),nightSky=new THREE.Color('#9394bf')
  try{await renderer.compileAsync(scene,camera)}catch{/* Older drivers compile on first render. */}
  function renderFrame(time){
    if(disposed||!active)return
    const dt=lastTime?Math.min((time-lastTime)/1000,.05):.016;lastTime=time;elapsed+=dt;actionTime+=dt*(reduced.matches?2:1)
    if(pointerDirty){host.style.cursor=pick()?'pointer':'default';pointerDirty=false}
    const t=elapsed,a=actionTime,motion=reduced.matches?0:1
    const cue=(at,name)=>{if(a>=at&&!cues.has(name)){cues.add(name);onAction(name)}}
    let x=state.rug?-.43:-.18,y=.12,z=.45,lean=0,turn=pointer.x*.13,crouch=0,paw=0,hop=0,happy=false
    const resting=state.rug&&action==='idle'&&state.dim
    if(state.seated)x=-.65
    if(resting)crouch=.85
    if(action==='rug'){
      cue(1.45,'rug-touch');cue(4.2,'rug-rest')
      if(a<1.2){lean=-.1;turn=.16}else if(a<3){paw=Math.sin((a-1.2)/1.8*Math.PI)*.75;lean=.12}else if(a<4.4){hop=Math.sin((a-3)/1.4*Math.PI)*.27;x=-.43}else{crouch=.7;happy=true;x=-.55}
      if(a>7){action='idle';onAction('settled')}
    }
    if(action==='pet'){const closeness=Math.sin(Math.min(a/4.6,1)*Math.PI);happy=a>1&&a<3.7;lean=-.025;z+=closeness*.17*motion;paw=.06;if(a>4.6)action='idle'}
    if(action==='wave'){paw=1.85+Math.sin(a*10)*.18;happy=a<1.6;if(a>2.6)action='idle'}
    if(action==='play'){
      cue(2.6,'play-chase')
      const p=Math.min(a/5.8,1),center=THREE.MathUtils.clamp(-.43,-horizontalRoom*.25,horizontalRoom*.25)
      x=center+Math.sin(p*Math.PI*2)*Math.min(.9,horizontalRoom-Math.abs(center));z=.5+Math.sin(p*Math.PI)*.22
      hop=Math.abs(Math.sin(a*8))*.14;turn=Math.cos(p*Math.PI*2)*.4;paw=Math.sin(a*8)*.5;lean=.12
      toy.visible=true;toy.position.set(x+Math.cos(p*Math.PI*2)*.55,.18+Math.abs(Math.sin(a*5))*.1,z+.6);toy.rotation.set(a*3,a*4,a)
      if(a>5.8){action='idle';toy.visible=false;onAction('played')}
    }
    if(action==='stretch'){paw=Math.sin(Math.min(a/3,1)*Math.PI)*2.35;lean=-.18*Math.sin(a/3*Math.PI);happy=true;if(a>3){action='idle';onAction('stretched')}}
    if(reduced.matches){x=state.seated?-.65:state.rug?-.43:-.18;z=.45;paw*=.15;lean*=.15;toy.visible=false}
    x=THREE.MathUtils.clamp(x,-horizontalRoom,horizontalRoom)
    root.position.set(damp(root.position.x,x,dt),damp(root.position.y,y+hop*motion,dt,10),damp(root.position.z,z,dt));root.rotation.y=damp(root.rotation.y,turn,dt)
    character.update({t,a,action,pointer,crouch,lean,paw,happy,resting,motion,dim:state.dim},dt)
    particles.visible=action==='pet'&&!reduced.matches
    particles.children.forEach((p,i)=>{const progress=(a*.6+i/9)%1;p.position.set(root.position.x+Math.sin(i*2.4)*progress*.7,1.2+progress*1.6,.95);p.scale.setScalar(.023*(1-progress))})
    dust.children.forEach(p=>{p.position.y=p.userData.start.y+Math.sin(t*.25+p.userData.phase)*.1*motion;p.position.x=p.userData.start.x+Math.sin(t*.14+p.userData.phase)*.12*motion})
    curtains.forEach((c,i)=>{c.rotation.y=Math.sin(t*.65+i)*.025*motion});clouds.position.x=-.25+Math.sin(t*.08)*.1*motion
    const rs=damp(rug.scale.x,state.rug?1:.001,dt,5);rug.scale.setScalar(rs);rug.visible=rs>.005
    seat.visible=state.rug;seat.scale.y=damp(seat.scale.y,state.seated?.7:1,dt)
    contact.position.x=root.position.x;contact.scale.x=2.5-hop*.5;contact.material.opacity=.22-hop*.22
    key.intensity=damp(key.intensity,state.dim?.4:2.15,dt,2);hemi.intensity=damp(hemi.intensity,state.dim?.55:.85,dt,2)
    rim.intensity=damp(rim.intensity,state.dim?.8:1.3,dt,2);fill.intensity=damp(fill.intensity,state.dim?.2:.3,dt,2)
    lampLight.intensity=damp(lampLight.intensity,state.dim?3.8:1.4,dt,2);shadeMat.emissiveIntensity=damp(shadeMat.emissiveIntensity,state.dim?.5:.12,dt,2)
    glow.material.opacity=damp(glow.material.opacity,state.dim?.35:.12,dt,2);skyMat.color.lerp(state.dim?nightSky:daylight,1-Math.exp(-dt*2))
    renderer.render(scene,camera)
  }
  renderer.setAnimationLoop(renderFrame)
  return {
    update(next){state={...state,...next}},
    act(name){action=name;actionTime=0;cues.clear();if(name!=='play')toy.visible=false},
    dispose(){disposed=true;renderer.setAnimationLoop(null);observer.disconnect();viewObserver.disconnect();document.removeEventListener('visibilitychange',visibility);host.removeEventListener('pointermove',pointerMove);host.removeEventListener('pointerdown',pointerDown);host.removeEventListener('pointerup',pointerUp);host.removeEventListener('pointerleave',pointerLeave);host.removeEventListener('pointercancel',pointerLeave);character.dispose();geometries.forEach(g=>g.dispose());materials.forEach(m=>m.dispose());textures.forEach(t=>t.dispose());env.dispose();key.shadow.dispose();renderer.dispose();renderer.domElement.remove()},
  }
}
