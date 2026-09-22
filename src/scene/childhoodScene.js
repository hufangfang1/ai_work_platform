import * as T from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js'
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js'
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js'
import { makeHomeMaterials } from './homeMaterials.js'
import { branchGeometry, carBodyGeometry, corrugatedSheetGeometry, wornSkirtingGeometry } from './homeDetailGeometry.js'
import { homePhotos, housePlacement, houseLocalMinX, houseLocalMaxX, houseDoorLocalX, houseWindows, gateX, eastWall, shedPlacement, addedRooms, westFacade, westWallX, moveWithinYard } from './homeLayout.js'

export async function createChildhoodScene(host, report) {
  const renderer=new T.WebGLRenderer({antialias:true,powerPreference:'default'})
  renderer.setPixelRatio(Math.min(devicePixelRatio,1.6));renderer.outputColorSpace=T.SRGBColorSpace
  renderer.toneMapping=T.ACESFilmicToneMapping;renderer.toneMappingExposure=1.05
  renderer.shadowMap.enabled=true;renderer.shadowMap.type=T.PCFShadowMap
  const canvas=renderer.domElement;canvas.tabIndex=0;canvas.setAttribute('aria-label','老家院子三维场景，拖动环顾，WASD或方向按钮行走');host.appendChild(canvas)
  const scene=new T.Scene();scene.background=new T.Color('#cbd0cb');scene.fog=new T.Fog('#cbd0cb',22,65)
  const pmrem=new T.PMREMGenerator(renderer),studio=new RoomEnvironment(),environment=pmrem.fromScene(studio,.06);scene.environment=environment.texture;scene.environmentIntensity=.45;studio.dispose();pmrem.dispose()
  const camera=new T.PerspectiveCamera(64,1,.08,130);camera.rotation.order='YXZ'
  const M=makeHomeMaterials(renderer),geos=new Set(),extraTextures=new Set(),extraMats=new Set()
  const g=value=>{geos.add(value);return value}
  const cube=g(new T.BoxGeometry()),sphere=g(new T.SphereGeometry(1,12,8)),plane=g(new T.PlaneGeometry(1,1))
  const mat=M.mat,wood=M.mapped(M.maps.wood),metal=mat('#4c4940',{metalness:.45,roughness:.7}),dark=mat('#252821'),red=mat('#722d2b',{metalness:.2}),rubber=mat('#252824'),silver=mat('#9c9d8f',{metalness:.65,roughness:.5})
  const white=M.mapped(M.maps.plaster),brick=M.mapped(M.maps.brick,[2,1]),concrete=M.mapped(M.maps.ground,[2,1])
  function mesh(parent,geometry,material,p=[0,0,0],s=[1,1,1]){const o=new T.Mesh(geometry,material);o.position.set(...p);o.scale.set(...s);o.castShadow=o.receiveShadow=true;parent.add(o);return o}
  const box=(parent,m,p,s)=>{
    if(!m.map||!m.userData.unit)return mesh(parent,cube,m,p,s)
    const geometry=g(cube.clone()),uv=geometry.attributes.uv,n=geometry.attributes.normal,[u,v]=m.userData.unit
    for(let i=0;i<uv.count;i++){const axis=Math.abs(n.getX(i))>.5?'x':Math.abs(n.getY(i))>.5?'y':'z',a=axis==='x'?s[2]:s[0],b=axis==='y'?s[2]:s[1];uv.setXY(i,uv.getX(i)*a/u/m.map.repeat.x,uv.getY(i)*b/v/m.map.repeat.y)}
    return mesh(parent,geometry,m,p,s)
  }
  const ball=(parent,m,p,s)=>mesh(parent,sphere,m,p,s)
  function group(parent,p=[0,0,0]){const o=new T.Group();o.position.set(...p);parent.add(o);return o}
  function rod(parent,a,b,r,m){const av=new T.Vector3(...a),bv=new T.Vector3(...b),d=bv.clone().sub(av),o=mesh(parent,g(new T.CylinderGeometry(r*.85,r,d.length(),7)),m,av.clone().add(bv).multiplyScalar(.5).toArray());o.quaternion.setFromUnitVectors(new T.Vector3(0,1,0),d.normalize());return o}
  function face(parent,m,p,s,rotation=0){const o=mesh(parent,plane,m,p,[s[0],s[1],1]);o.rotation.y=rotation;return o}
  let seed=2192015;const rand=()=>{seed=(seed*1664525+1013904223)>>>0;return seed/4294967296}
  const hemi=new T.HemisphereLight('#dce3e6','#77786f',2.1);scene.add(hemi)
  const sun=new T.DirectionalLight('#f2f2e9',.95);sun.position.set(-10,16,3);sun.target.position.set(0,0,0);scene.add(sun,sun.target)
  sun.castShadow=true;sun.shadow.mapSize.set(2048,2048);Object.assign(sun.shadow.camera,{left:-17,right:17,top:17,bottom:-17,near:1,far:48});sun.shadow.normalBias=.04;sun.shadow.bias=-.00015
  const shadowHelper=new T.CameraHelper(sun.shadow.camera);shadowHelper.visible=false;scene.add(shadowHelper)
  const earth=mesh(scene,g(new T.PlaneGeometry(140,140)),M.mapped(M.maps.ground,[16,16]));earth.rotation.x=-Math.PI/2
  box(scene,M.mapped(M.maps.ground,[3,4]),[(westWallX-6.3)/2,-.065,0],[westWallX+6.3,.12,16])
  // Confirmed by the owner: the house faces the entrance across the yard.
  const house=group(scene,housePlacement.position);house.rotation.y=housePlacement.rotationY;house.scale.x=housePlacement.scaleX
  const doorX=houseDoorLocalX,doorW=1.28,doorH=2.65
  box(house,white,[(houseLocalMinX+doorX-.8)/2,1.9,0],[doorX-.8-houseLocalMinX,3.8,.3]);box(house,white,[(houseLocalMaxX+doorX+.8)/2,1.9,0],[houseLocalMaxX-doorX-.8,3.8,.3]);box(house,white,[doorX,3.35,0],[1.6,.9,.3])
  // A single building volume and continuous facade, not a lower side annex.
  box(house,white,[(houseLocalMaxX+houseLocalMinX)/2,2,-3.1],[houseLocalMaxX-houseLocalMinX,4,6]);box(house,mat('#242620'),[doorX,1.6,.16],[1.45,3.05,.025])
  box(house,concrete,[1.7,.14,.8],[15.3,.28,1.8]);box(house,mat('#817b6a'),[1.7,.06,1.82],[15.5,.13,.35])
  // Photo 1: exposed, uneven brick riser and a shallow drainage strip below it.
  const stepColors=['#817a69','#918273','#746f61','#a08c78'].map(c=>mat(c));
  for(let i=0;i<49;i++){
    const x=-5.8+i*.31,h=.19+rand()*.055;
    const step=mesh(house,g(new RoundedBoxGeometry(.29,h,.2,1,.012)),stepColors[i%4],[x,h/2,1.75]);step.rotation.z=(rand()-.5)*.035;
  }
  box(house,dark,[1.7,.014,2.01],[15.3,.022,.16]);
  box(house,concrete,[1.7,.028,2.17],[15.3,.055,.16]);
  // Worn cement skirting in the reference, kept clear of the doorway.
  const wallCement=M.mapped(M.maps.plaster,[1,1],{color:'#a5a69f',bumpScale:.009,vertexColors:true});
  for(const [a,b] of [[houseLocalMinX,doorX-.82],[doorX+.82,houseLocalMaxX]])
    mesh(house,g(wornSkirtingGeometry(b-a,.44,a)),wallCement,[(a+b)/2,.28,.167]);
  const door=group(house,[doorX-doorW/2,.28,.25]);door.rotation.y=-.22
  box(door,wood,[doorW/2,doorH/2,0],[doorW,doorH,.075])
  for(let i=0;i<3;i++)box(door,wood,[doorW/2,.4+i*.85,.045],[doorW-.1,.045,.035])
  const fu=M.writing('福');face(door,fu,[doorW*.52,1.95,.05],[.5,.6]);
  box(door,silver,[doorW-.1,1.2,.075],[.03,.16,.035])
  // Separate hinges and recessed plank seams give the door depth at arm's length.
  for(const y of [.42,2.14]){box(door,metal,[.055,y,.061],[.11,.17,.018]);rod(door,[.015,y-.12,.062],[.015,y+.12,.062],.018,metal)}
  for(let i=1;i<6;i++)box(door,mat('#352f28'),[i*doorW/6,doorH/2,.0385],[.005,doorH-.03,.001]);
  const couplet=M.writing('平安顺遂岁岁春','#852522','#bf9b46',true)
  face(house,couplet,[doorX-.77,1.63,.23],[.13,2.7]);face(house,couplet,[doorX+.77,1.63,.23],[.13,2.7])
  box(house,wood,[doorX,3.05,.2],[1.8,.09,.15])
  // Photo 1: a broad wooden transom and narrow glazed sidelights frame the door.
  box(house,dark,[doorX,3.39,.205],[2.45,.6,.035]);
  for(const y of [3.07,3.72])box(house,wood,[doorX,y,.25],[2.6,.07,.1]);
  for(let i=0;i<6;i++)box(house,wood,[doorX-1.25+i*.5,3.39,.25],[.055,.64,.1]);
  for(const side of [-1,1]){
    box(house,dark,[doorX+side*1.06,1.74,.2],[.36,2.46,.04]);
    for(const dx of [-.21,.21])box(house,wood,[doorX+side*1.06+dx,1.74,.25],[.045,2.5,.08]);
    box(house,wood,[doorX+side*1.06,1.74,.26],[.43,.045,.07]);
    rod(house,[doorX+side*1.06,.51,.28],[doorX+side*1.06,2.97,.28],.012,metal);
  }
  // Old wooden windows, dark interiors and separate iron bars.
  function windowAt(x,width=2.5){
    const win=group(house,[x,2.27,.20]),glass=mat('#283633',{metalness:.25,roughness:.38})
    box(win,wood,[0,0,0],[width+ .16,2.45,.1]);box(win,glass,[0,0,.06],[width,2.29,.025])
    for(let j=0;j<4;j++)box(win,wood,[-width/2+j*width/3,0,.1],[.05,2.4,.055])
    for(const y of [-1.16,.38,1.16])box(win,wood,[0,y,.1],[width,.06,.055])
    for(let j=0;j<10;j++)rod(win,[-width/2+j*width/9,-1.12,.16],[-width/2+j*width/9,1.12,.16],.012,metal)
    box(win,concrete,[0,-1.24,.08],[width+.23,.12,.3])
    // Curtains remain inside the frame, so parallax stays physically consistent.
    const curtain=mat('#53605b')
    for(let j=0;j<14;j++)box(win,curtain,[-width/2+.10+j*width/14,-.12,.079],[.045,2.05,.02])
  }
  for(const win of houseWindows)windowAt(win.x,win.width)
  const roofWidth=houseLocalMaxX-houseLocalMinX+.75;
  const roof=box(house,mat('#66574a'),[(houseLocalMaxX+houseLocalMinX)/2,4.35,-2.85],[roofWidth,.13,6.8]);roof.rotation.x=.11
  const tileColors=[mat('#6b6258',{side:T.DoubleSide}),mat('#776c5e',{side:T.DoubleSide}),mat('#625e56',{side:T.DoubleSide})];
  function tiledRoof(parent,width,depth,center,tilt,spacing=.186){
    // Overlapping half-round clay tiles, not uninterrupted wooden-looking logs.
    const shape=new T.CylinderGeometry(spacing*.48,spacing*.52,.48,8,1,true,0,Math.PI);
    shape.rotateX(Math.PI/2);shape.rotateZ(Math.PI/2);const geo=g(shape),rows=Math.ceil(depth/.41),cols=Math.floor(width/spacing);
    const tiles=new T.InstancedMesh(geo,tileColors[0],rows*cols),dummyTile=new T.Object3D(),color=new T.Color();
    for(let r=0;r<rows;r++)for(let c=0;c<cols;c++){
      const z=-depth/2+r*.41;dummyTile.position.set(center[0]-width/2+(c+.5)*spacing,center[1]-Math.sin(tilt)*z+(r%2)*.004,center[2]+z);dummyTile.rotation.set(tilt,0,0);dummyTile.updateMatrix();const index=r*cols+c;tiles.setMatrixAt(index,dummyTile.matrix);color.setHSL(.095,.10+rand()*.06,.32+rand()*.08);tiles.setColorAt(index,color);
    }tiles.castShadow=tiles.receiveShadow=true;parent.add(tiles);
  }
  tiledRoof(house,roofWidth,6.8,[(houseLocalMaxX+houseLocalMinX)/2,4.43,-2.85],.11);
  // Washing machine, thermos and a blue garment under a sagging clothesline.
  const washer=group(house,[1.7,0,0]);
  const washerPlastic=mat('#bfc4bb',{roughness:.62}),washerTrim=mat('#808b85',{roughness:.7});
  mesh(washer,g(new RoundedBoxGeometry(.78,1,.68,3,.045)),washerPlastic,[1.38,.79,.7]);
  mesh(washer,g(new RoundedBoxGeometry(.81,.08,.72,3,.035)),washerTrim,[1.38,1.30,.7]);
  for(const [x,w] of [[1.23,.45],[1.62,.25]])mesh(washer,g(new RoundedBoxGeometry(w,.035,.52,2,.025)),mat('#d0d3c6',{roughness:.5}),[x,1.35,.76]);
  for(const x of [1.1,1.39,1.64])mesh(washer,g(new T.CylinderGeometry(.035,.037,.025,12)),washerTrim,[x,1.355,.43]);
  for(const x of [1.09,1.67])for(const z of [.46,.95])box(washer,washerTrim,[x,.3,z],[.10,.045,.09]);
  const hosePath=[[1.79,.85,.45],[1.87,.38,.5],[1.95,.32,.85],[2.02,.30,1.05]].map(p=>new T.Vector3(...p));
  mesh(washer,g(new T.TubeGeometry(new T.CatmullRomCurve3(hosePath),16,.018,6,false)),washerTrim);
  const thermos=mesh(house,g(new T.CylinderGeometry(.095,.105,.48,16)),mat('#e2ddc5'),[-2.2,.53,.87]);mesh(house,g(new T.CylinderGeometry(.052,.065,.1,12)),silver,[-2.2,.82,.87])
  rod(house,[-2.08,.42,.87],[-2.02,.47,.87],.014,silver);rod(house,[-2.02,.47,.87],[-2.02,.68,.87],.014,silver);rod(house,[-2.02,.68,.87],[-2.08,.72,.87],.014,silver);
  const linePoints=[[-5.5,3.1,.7],[-2,2.63,.8],[2.6,2.67,.9],[5.4,3,.7]].map(a=>new T.Vector3(...a))
  mesh(house,g(new T.TubeGeometry(new T.CatmullRomCurve3(linePoints),40,.009,4,false)),mat('#6b5141'))
  // One draped garment, with an uneven hem, not a rigid rectangular flag.
  const cloth=mesh(house,g(new T.PlaneGeometry(.37,1.2,12,24)),mat('#23768c',{side:T.DoubleSide}),[-2.2,2.13,.82]);const cp=cloth.geometry.attributes.position
  for(let i=0;i<cp.count;i++){const x=cp.getX(i),y=cp.getY(i),drop=(.6-y)/1.2;cp.setXYZ(i,x+Math.sin(drop*2)*.025,y+Math.sin(x*14)*drop*.032,Math.sin(x*39+drop*1.8)*(.01+drop*.03))}cloth.geometry.computeVertexNormals()
  // Western rooms belong to this house: a continuous wing, not separate annexes.
  const wing=group(scene,[7.7,0,4.3]);wing.rotation.y=-Math.PI/2;
  box(wing,white,[0,2,0],[7,4,3.2]);
  box(wing,tileColors[1],[0,4.02,0],[7.35,.14,3.6]);
  tiledRoof(wing,7.35,3.6,[0,4.1,0],0,.14);
  for(const {bounds:[x0,x1,z0,z1],facing} of addedRooms){
    const room=group(scene,[(x0+x1)/2,0,(z0+z1)/2]);
    const w=facing==='yard'?z1-z0:x1-x0,d=facing==='yard'?x1-x0:z1-z0;
    room.rotation.y=facing==='yard'?-Math.PI/2:Math.PI;
    box(room,brick,[0,.17,d/2+.2],[w,.34,.5]);
  }
  for(const opening of westFacade.doors)
    box(wing,wood,[opening.z-4.3,1.35,1.63],[.82,2.35,.08]);
  for(const opening of westFacade.windows){
    const x=opening.z-4.3,w=opening.width,h=opening.height,y=2.05;
    box(wing,dark,[x,y,1.65],[w,h,.06]);
    for(const dx of [-w/2,0,w/2])box(wing,wood,[x+dx,y,1.69],[.045,h+.1,.07]);
    for(const dy of [-h/2,0,h/2])box(wing,wood,[x,y+dy,1.69],[w+.09,.045,.07]);
    box(wing,concrete,[x,y-h/2-.04,1.72],[w+.15,.1,.25]);
  }
  // West boundary moved beyond the two rooms, not through their fronts.
  box(scene,M.mapped(M.maps.brick,[5,1.3]),[eastWall.centerX,1.15,0],[eastWall.thickness,2.3,16]);box(scene,M.mapped(M.maps.brick,[5,1.3]),[westWallX,1.15,0],[.35,2.3,16]);
  // Uneven exposed coping breaks the perfectly straight silhouette of the wall.
  const capGeo=g(new RoundedBoxGeometry(.35,.09,.25,1,.013)),caps=new T.InstancedMesh(capGeo,stepColors[0],124),capPose=new T.Object3D(),capColor=new T.Color();
  for(let i=0;i<124;i++){const side=i<62?eastWall.centerX:westWallX;capPose.position.set(side,2.315+rand()*.02,-7.85+(i%62)*.254);capPose.rotation.set((rand()-.5)*.05,(rand()-.5)*.025,(rand()-.5)*.04);capPose.updateMatrix();caps.setMatrixAt(i,capPose.matrix);capColor.setHSL(.085,.12,.32+rand()*.13);caps.setColorAt(i,capColor)}caps.castShadow=caps.receiveShadow=true;scene.add(caps);
  box(scene,M.mapped(M.maps.brick,[2.8,1.1]),[(-6.3+gateX-1.4)/2,1.1,-7.5],[gateX-1.4+6.3,2.2,.35]);box(scene,brick,[(gateX+1.7+westWallX)/2,1.1,-7.5],[westWallX-gateX-1.7,2.2,.35])
  const gate=group(scene,[gateX,0,-7.5])
  box(gate,brick,[-1.43,1.95,0],[.42,3.9,1.8]);box(gate,brick,[1.43,1.95,0],[.42,3.9,1.8]);box(gate,concrete,[0,3.78,0],[3.3,.35,1.95])
  const gateInside=M.mapped(M.maps.plaster,[.5,1]);box(gate,gateInside,[-1.19,1.72,0],[.04,3.42,1.7]);box(gate,gateInside,[1.19,1.72,0],[.04,3.42,1.7])
  const gateLeaf=group(gate,[1.21,0,-.32]);gateLeaf.rotation.y=-.22
  box(gate,metal,[0,3.08,-.34],[2.48,.05,.08]);box(gate,metal,[0,3.47,-.34],[2.48,.05,.08]);for(let j=0;j<9;j++)rod(gate,[-1.2+j*.3,3.08,-.34],[-1.2+j*.3,3.47,-.34],.014,metal)
  box(gateLeaf,mat('#502f2c',{metalness:.35,roughness:.8}),[-.58,1.5,0],[1.16,3,.065])
  for(const y of [.55,1.5,2.42,2.98])box(gateLeaf,metal,[-.58,y,.06],[1.15,.035,.03])
  const gateFu=face(gateLeaf,fu,[-.6,1.83,.045],[.39,.39]);gateFu.rotation.z=Math.PI/4
  box(scene,concrete,[gateX,.025,-7.6],[2.5,.06,3.2])
  // Long wooden poles leaning beside the gate.
  for(let i=0;i<12;i++)rod(scene,[gateX-2.3-rand()*.6,.05,-6.7+rand()*.4],[gateX-1.65-rand()*.6,2.0+rand()*1.1,-7.0],.018+rand()*.015,wood)
  rod(scene,[gateX+1.9,.1,-6.7],[gateX+2.25,2.1,-7.1],.024,wood);const shovel=box(scene,silver,[gateX+1.89,.28,-6.66],[.38,.48,.045]);shovel.rotation.x=-.2
  function basin(p,r=.38){const o=mesh(scene,g(new T.CylinderGeometry(r,r*.74,.14,24,1,true)),silver,p);mesh(scene,g(new T.CircleGeometry(r*.75,24)),dark,[p[0],p[1]-.062,p[2]]).rotation.x=-Math.PI/2;return o}
  basin([gateX+2.15,.09,-6.3],.4);basin([gateX+2.65,.09,-5.5],.26)
  // One continuous roof against the boundary: rear washing corridor + front carport.
  const shed=group(scene,shedPlacement.position),shedRoof=M.maps.roofMetal?M.mapped(M.maps.roofMetal,[1,1],{metalness:.32,roughness:.92,side:T.DoubleSide}):mat('#45473e',{metalness:.32,roughness:.92,side:T.DoubleSide})
  for(const [x,z] of shedPlacement.posts)rod(shed,[x-shedPlacement.position[0],0,z-shedPlacement.position[2]],[x-shedPlacement.position[0],2.65,z-shedPlacement.position[2]],.045,metal)
  mesh(shed,g(corrugatedSheetGeometry(shedPlacement.width,shedPlacement.depth)),shedRoof,[0,2.68,0]);
  // Shallow sheet laps and fastening points; the rear corridor and front carport
  // retain their single uninterrupted roof and confirmed outer dimensions.
  for(const x of [-.92,.58])box(shed,metal,[x,2.697,0],[.018,.014,shedPlacement.depth]);
  const fasteners=new T.InstancedMesh(g(new T.SphereGeometry(.014,6,4)),metal,39),fastenerPose=new T.Object3D();
  for(let i=0;i<39;i++){const x=-1.8+(i%13)*.30,z=[-3,1.3,3.05][Math.floor(i/13)];fastenerPose.position.set(x,2.68+Math.cos((x+shedPlacement.width/2)/.15*Math.PI*2)*.026+z*.012+.012,z);fastenerPose.scale.set(1,.35,1);fastenerPose.updateMatrix();fasteners.setMatrixAt(i,fastenerPose.matrix)}shed.add(fasteners);
  for(const z of [-3,1.3,3.05])box(shed,wood,[0,2.55,z],[shedPlacement.width,.08,.07])
  box(scene,concrete,[shedPlacement.position[0],.13,6.6],[shedPlacement.width,.26,2]);
  // Hollow washbasin with rim and wall tap; corridor remains open beside it.
  const sink=group(scene,shedPlacement.sink),ceramic=mat('#c9cdc1');
  box(sink,concrete,[0,.42,0],[.66,.84,1.08]);
  box(sink,dark,[0,.88,0],[.65,.035,.91]);
  for(const x of [-.35,.35])box(sink,ceramic,[x,.98,0],[.1,.24,1.2]);
  for(const z of [-.55,.55])box(sink,ceramic,[0,.98,z],[.8,.24,.1]);
  rod(sink,[-.39,1.1,0],[-.39,1.4,0],.025,silver);rod(sink,[-.39,1.4,0],[-.12,1.4,0],.025,silver);rod(sink,[-.12,1.4,0],[-.12,1.32,0],.025,silver);
  // Rounded panel edges and recessed glass catch the soft sky reflection.
  const contactCanvas=document.createElement('canvas');contactCanvas.width=contactCanvas.height=128;
  const contactContext=contactCanvas.getContext('2d'),contactGradient=contactContext.createRadialGradient(64,64,15,64,64,64);
  contactGradient.addColorStop(0,'rgba(24,26,22,.48)');contactGradient.addColorStop(.6,'rgba(24,26,22,.25)');contactGradient.addColorStop(1,'rgba(24,26,22,0)');contactContext.fillStyle=contactGradient;contactContext.fillRect(0,0,128,128);
  const contactTexture=new T.CanvasTexture(contactCanvas);extraTextures.add(contactTexture);
  const contactMaterial=new T.MeshBasicMaterial({map:contactTexture,transparent:true,depthWrite:false});extraMats.add(contactMaterial);
  function contact(parent,x,z,w,d){const o=mesh(parent,plane,contactMaterial,[x,.006,z],[w,d,1]);o.rotation.x=-Math.PI/2;o.castShadow=o.receiveShadow=false}
  const car=group(scene,shedPlacement.car);
  contact(car,0,0,2.5,3.6);
  mesh(car,g(carBodyGeometry()),silver);
  const autoGlass=mat('#303e40',{metalness:.35,roughness:.22});
  function glazing(points){
    const geometry=g(new T.BufferGeometry());geometry.setAttribute('position',new T.Float32BufferAttribute(points.flat(),3));geometry.setIndex([0,1,2,0,2,3]);geometry.computeVertexNormals();return mesh(car,geometry,autoGlass);
  }
  // Separate sloped glass panes, with pillars following the body taper rather
  // than upright bars around a rectangular black cabin.
  glazing([[-.69,.99,.94],[.69,.99,.94],[.57,1.49,.40],[-.57,1.49,.40]]);
  glazing([[.69,.99,-1.10],[-.69,.99,-1.10],[-.57,1.49,-.73],[.57,1.49,-.73]]);
  mesh(car,g(new RoundedBoxGeometry(1.22,.085,1.25,3,.035)),silver,[0,1.52,-.16]);
  for(const sign of [-1,1]){
    const points=[[sign*.69,.99,-1.10],[sign*.69,.99,.94],[sign*.57,1.49,.40],[sign*.57,1.49,-.73]];
    glazing(sign<0?points:points.slice().reverse());
    rod(car,points[0],points[3],.044,silver);rod(car,points[1],points[2],.039,silver);
    rod(car,[sign*.69,.99,-.12],[sign*.57,1.49,-.20],.029,metal);
    rod(car,points[0],points[1],.027,silver);rod(car,points[2],points[3],.028,silver);
  }
  for(const x of [-.7,.7]){
    box(car,silver,[x*1.09,.9,-.22],[.025,.035,.18]);
    // Door gaps, sill trim and small mirrors, without guessing a car badge.
    for(const z of [-.55,.23])rod(car,[x*1.13,.46,z],[x*1.13,.96,z],.006,metal);
    box(car,rubber,[x*1.13,.48,.02],[.016,.035,.86]);
    rod(car,[x,1.08,.64],[x*1.25,1.07,.64],.023,metal);
    mesh(car,g(new RoundedBoxGeometry(.18,.13,.24,2,.04)),silver,[x*1.29,1.09,.64]);
  }
  box(car,silver,[0,.92,-1.34],[1.52,.1,.07]);box(car,rubber,[0,.45,-1.4],[1.6,.12,.075]);
  box(car,dark,[0,.67,-1.395],[.44,.17,.018]);box(car,silver,[0,.86,-1.402],[.19,.035,.022]);
  rod(car,[-.34,1.13,-1.004],[.32,1.13,-1.004],.012,metal);
  for(const x of [-.3,.3])rod(car,[x-.16,1.035,.9],[x+.17,1.08,.85],.009,metal);
  function wheel(parent,x,y,z,r=.33){
    const tire=mesh(parent,g(new T.TorusGeometry(r*.76,r*.24,10,32)),rubber,[x,y,z]);tire.rotation.y=Math.PI/2;
    const hub=mesh(parent,g(new T.CylinderGeometry(r*.56,r*.56,r*.42,24)),silver,[x,y,z]);hub.rotation.z=Math.PI/2;
    for(const side of [-1,1]){
      const ring=mesh(parent,g(new T.TorusGeometry(r*.49,.012,6,24)),metal,[x+side*r*.23,y,z]);ring.rotation.y=Math.PI/2;
      for(let k=0;k<6;k++){const a=k*Math.PI/3;ball(parent,dark,[x+side*r*.22,y+Math.sin(a)*r*.34,z+Math.cos(a)*r*.34],[.012,r*.08,r*.07])}
      ball(parent,silver,[x+side*r*.25,y,z],[.018,r*.14,r*.14]);
    }return tire
  }
  for(const x of [-.83,.83])for(const z of [-.86,.83]){wheel(car,x,.3,z,.3);contact(car,x,z,.48,.68)}
  const tailLens=mat('#812e26',{roughness:.27,metalness:.1});
  for(const x of [-.59,.59]){
    mesh(car,g(new RoundedBoxGeometry(.23,.29,.075,2,.045)),tailLens,[x,.81,-1.36]);
    box(car,mat('#b7afa0',{roughness:.3}),[x,.79,-1.403],[.18,.052,.012]);
    mesh(car,g(new RoundedBoxGeometry(.31,.17,.07,2,.035)),mat('#cfcec0',{roughness:.2}),[x,.81,1.36]);
  }
  // The green canopy and red three-wheeler are the central photo landmarks.
  const trike=group(scene,shedPlacement.trike);trike.rotation.y=shedPlacement.trikeRotationY
  contact(trike,0,-.1,2.5,3.8);
  box(trike,red,[0,.62,.15],[1.63,.24,2.5]);box(trike,red,[0,.93,.88],[1.63,.62,.09])
  for(const x of [-.79,.79]) {box(trike,red,[x,.86,.05],[.075,.47,1.8]);for(let k=0;k<3;k++)box(trike,silver,[x*1.055,.77+k*.12,.05],[.02,.016,1.7]);wheel(trike,x,.38,.54,.38)}
  wheel(trike,0,.37,-1.7,.37)
  const tarp=M.mapped(M.maps.tarp,[1,1],{side:T.DoubleSide})
  function hangingTarp(p,width,height,angle){
    const geometry=g(new T.PlaneGeometry(width,height,28,24)),a=geometry.attributes.position;
    for(let i=0;i<a.count;i++){const x=a.getX(i),y=a.getY(i),free=(height/2-y)/height;a.setZ(i,(Math.sin(x*18+y*2)*.022+Math.sin(x*7-y*4)*.025)*Math.sin(free*Math.PI/2));a.setY(i,y+Math.sin(x*11)*.015*free)}geometry.computeVertexNormals();
    const panel=mesh(trike,geometry,tarp,p);panel.rotation.y=angle;
  }
  for(const x of [-.82,.82]){hangingTarp([x,1.68,.09],1.9,1.57,Math.sign(x)*Math.PI/2);for(const z of [-.87,.85])rod(trike,[x,.93,z],[x,2.48,z],.021,silver)}
  hangingTarp([0,1.71,1.04],1.67,1.57,0)
  const roofGeometry=g(new T.PlaneGeometry(1.72,2.04,32,8)),rp=roofGeometry.attributes.position
  for(let i=0;i<rp.count;i++){const x=rp.getX(i),z=-rp.getY(i);rp.setXYZ(i,x,Math.sqrt(Math.max(0,1-(x/.86)**2))*.21,z)}roofGeometry.computeVertexNormals()
  mesh(trike,roofGeometry,tarp,[0,2.45,.1])
  const canopyEnd=new T.Shape();canopyEnd.moveTo(-.86,0);for(let i=0;i<=24;i++){const x=-.86+i*1.72/24;canopyEnd.lineTo(x,Math.sqrt(Math.max(0,1-(x/.86)**2))*.21)}canopyEnd.lineTo(.86,0);canopyEnd.closePath();
  mesh(trike,g(new T.ShapeGeometry(canopyEnd)),tarp,[0,2.45,1.12]);
  const orange=mat('#b6753c');for(const x of [-.846,.846]){box(trike,orange,[x,2.25,.1],[.013,.027,1.98]);for(const z of [-.86,.1,1.05])box(trike,orange,[x,1.67,z],[.013,1.2,.022])}
  mesh(trike,g(new RoundedBoxGeometry(.79,.17,.61,3,.075)),dark,[0,1.1,-.83]);
  mesh(trike,g(new RoundedBoxGeometry(.48,.32,.68,3,.09)),red,[0,.66,-1.26]);
  // Curved leg shield, front mudguard and split fork instead of a bare stem.
  const shield=g(new T.SphereGeometry(1,24,16));mesh(trike,shield,red,[0,.99,-1.46],[.27,.39,.14]);
  const fender=mesh(trike,g(new T.TorusGeometry(.405,.055,8,24,Math.PI)),red,[0,.37,-1.7]);fender.rotation.y=Math.PI/2;
  for(const x of [-.12,.12])rod(trike,[x,.37,-1.7],[x,1.27,-1.43],.025,silver);
  rod(trike,[0,.8,-1.5],[0,1.47,-1.38],.033,metal);rod(trike,[-.42,1.47,-1.38],[.42,1.47,-1.38],.026,metal);
  for(const sign of [-1,1]){rod(trike,[sign*.3,1.47,-1.38],[sign*.45,1.47,-1.4],.039,rubber);rod(trike,[sign*.31,1.47,-1.38],[sign*.43,1.77,-1.35],.011,metal);ball(trike,silver,[sign*.43,1.79,-1.35],[.09,.055,.024])}
  ball(trike,silver,[0,1.29,-1.57],[.19,.13,.075]);ball(trike,mat('#d4d1bd',{roughness:.26}),[0,1.29,-1.63],[.15,.095,.025]);
  // Bare winter branches, merged into a handful of draw calls.
  function tree(x,z,size=1){
    const pieces=[]
    function branch(a,b,r,depth){const av=new T.Vector3(...a),bv=new T.Vector3(...b),v=bv.clone().sub(av),q=new T.Quaternion().setFromUnitVectors(new T.Vector3(0,1,0),v.clone().normalize());const geometry=branchGeometry(v.length(),r,rand()*Math.PI*2);geometry.applyMatrix4(new T.Matrix4().compose(av.add(bv).multiplyScalar(.5),q,new T.Vector3(1,1,1)));pieces.push(geometry)
      if(depth<1)return
      for(let j=0;j<2;j++){const length=v.length()*(.59+rand()*.13),angle=rand()*Math.PI*2,dir=v.clone().normalize().multiplyScalar(.7).add(new T.Vector3(Math.cos(angle)*.85,.25+rand()*.3,Math.sin(angle)*.85)).normalize();branch(b,[b[0]+dir.x*length,b[1]+dir.y*length,b[2]+dir.z*length],r*.44,depth-1)}
    }
    branch([0,0,0],[.2,3.7,.1],.29,5);branch([.05,1.7,0],[-1.1,4.4,.4],.16,4);branch([.1,2.2,0],[1.2,4.1,-.55],.14,4)
    for(let i=0;i<6;i++){const a=i*Math.PI/3;branch([Math.cos(a)*.75,.015,Math.sin(a)*.75],[Math.cos(a)*.16,.35,Math.sin(a)*.16],.055,0)}
    const combined=g(mergeGeometries(pieces));pieces.forEach(p=>p.dispose());const o=mesh(scene,combined,M.mapped(M.maps.bark,[2,3]),[x,0,z],[size,size,size]);return o
  }
  tree(-5.35,-2.4,1.1);tree(-5.9,-.5,.85)
  // The photos have scattered distant trunks, not a dense grove screening the gate.
  for(let i=0;i<10;i++){
    const x=-19+rand()*38,z=-14-rand()*12;
    if(Math.abs(x-gateX)>3.5)tree(x,z,.6+rand()*.5);
  }
  // A distant tiled roof and low, hazy fields outside the gate.
  const neighbor=group(scene,[-9,0,-14]);box(neighbor,brick,[0,1.7,0],[5,3.4,4]);for(const sign of [-1,1]){const o=box(neighbor,mat('#75685d'),[sign*1.3,3.8,0],[2.9,.12,4.8]);o.rotation.z=-sign*.36}
  // Ground detail uses instancing, not hundreds of separate meshes.
  const stoneGeo=g(new T.DodecahedronGeometry(.055)),stoneMat=mat('#8c8a77'),stones=new T.InstancedMesh(stoneGeo,stoneMat,150),dummy=new T.Object3D()
  for(let i=0;i<150;i++){dummy.position.set(-5.8+rand()*11.4,.018,-6.9+rand()*14.2);dummy.scale.set(.5+rand()*1.8,.25+rand()*.4,.5+rand());dummy.rotation.set(rand(),rand()*6,rand());dummy.updateMatrix();stones.setMatrixAt(i,dummy.matrix)}scene.add(stones);stones.receiveShadow=true
  // Short branching cracks in the old concrete, not a decorative paving grid.
  for(const [x,z] of [[2,-1],[.6,4.7],[4.8,-3],[-.7,-4.8]]){
    const points=[];for(let i=0;i<6;i++)points.push(new T.Vector3(x+(rand()-.5)*.2,.008,z+i*.23));
    mesh(scene,g(new T.TubeGeometry(new T.CatmullRomCurve3(points),12,.007,3,false)),dark);
  }
  const mossMat=mat('#67704d'),mossGeo=g(new T.PlaneGeometry(.15,.08)),moss=new T.InstancedMesh(mossGeo,mossMat,180)
  for(let i=0;i<180;i++){dummy.position.set(i%2?-5.85+rand()*.4:4.95+rand()*.5,.025,-6.8+rand()*14);dummy.rotation.set(-Math.PI/2,0,rand()*6);dummy.scale.set(.5+rand()*2,1,1);dummy.updateMatrix();moss.setMatrixAt(i,dummy.matrix)}scene.add(moss)
  const bird=group(scene,[-6.2,2.35,-2.75]);ball(bird,mat('#66584b'),[0,.12,0],[.09,.14,.08]);ball(bird,mat('#8d765a'),[0,.25,-.025],[.065,.06,.065]);rod(bird,[0,.1,.04],[0,-.1,.2],.028,mat('#986d3e'));rod(bird,[.025,0,0],[.025,.07,0],.007,dark)
  // Neutral photo texture patches retain the actual window appearance. No AI
  // generation, uploaded photos, or guessed indoor imagery is used.
  try {
    const photo=await new T.TextureLoader().loadAsync('/home-photos/01-house.jpg');extraTextures.add(photo);photo.colorSpace=T.SRGBColorSpace
    // Restrict the sample to curtain/glass, excluding the photographed blue
    // garment. The garment and mullions already exist as separate geometry.
    const patch=photo.clone();patch.repeat.set(152/1440,280/1080);patch.offset.set(441/1440,1-310/1080);patch.anisotropy=Math.min(8,renderer.capabilities.getMaxAnisotropy());extraTextures.add(patch)
    const pm=new T.MeshStandardMaterial({map:patch,roughness:1});extraMats.add(pm);face(house,pm,[-3.1,2.32,.285],[houseWindows[0].width-.14,2.28])
  }catch { report({warning:'原照片纹理未加载，已使用几何窗框。'}) }
  const orbit=new OrbitControls(camera,canvas);orbit.enabled=false;orbit.enableDamping=true;orbit.minDistance=6;orbit.maxDistance=26;orbit.maxPolarAngle=Math.PI*.47;orbit.target.set(0,1,0)
  let mode='walk',enabled=false,disposed=false,last=0,yaw=0,pitch=0,drag=null,journey=null,previousReport=0
  const keys=new Set(),held=new Set(),motion=matchMedia('(prefers-reduced-motion: reduce)')
  function pose(index,instant=false){const p=homePhotos[index],target=new T.Vector3(...p.target),end=new T.Vector3(...p.position),d=target.sub(end);const endYaw=Math.atan2(-d.x,-d.z),endPitch=Math.atan2(d.y,Math.hypot(d.x,d.z));mode='walk';orbit.enabled=false;keys.clear();held.clear()
    if(instant||motion.matches){camera.position.copy(end);yaw=endYaw;pitch=endPitch;journey=null}else{let delta=endYaw-yaw;delta=Math.atan2(Math.sin(delta),Math.cos(delta));journey={start:camera.position.clone(),end,startYaw:yaw,endYaw:yaw+delta,startPitch:pitch,endPitch,t:0}}
    camera.rotation.set(pitch,yaw,0,'YXZ');report({mode,view:index})
  }
  pose(0,true)
  function clear(){keys.clear();held.clear();drag=null;last=0}
  function down(e){if(!enabled||mode!=='walk'||e.button!==0)return;journey=null;canvas.focus({preventScroll:true});canvas.setPointerCapture(e.pointerId);drag={id:e.pointerId,x:e.clientX,y:e.clientY}}
  function move(e){if(!drag||drag.id!==e.pointerId)return;yaw-=(e.clientX-drag.x)*.003;pitch=T.MathUtils.clamp(pitch-(e.clientY-drag.y)*.003,-1.1,1.1);drag.x=e.clientX;drag.y=e.clientY}
  function up(){drag=null}
  const allowed=['KeyW','KeyA','KeyS','KeyD','ArrowUp','ArrowDown','ArrowLeft','ArrowRight']
  function keydown(e){if(!enabled||mode!=='walk'||!allowed.includes(e.code)||/INPUT|TEXTAREA|SELECT/.test(e.target.tagName))return;e.preventDefault();journey=null;keys.add(e.code)}
  function keyup(e){keys.delete(e.code)}
  canvas.addEventListener('pointerdown',down);canvas.addEventListener('pointermove',move);canvas.addEventListener('pointerup',up);canvas.addEventListener('pointercancel',up)
  window.addEventListener('keydown',keydown);window.addEventListener('keyup',keyup);window.addEventListener('blur',clear);document.addEventListener('visibilitychange',clear)
  function resize(){const w=host.clientWidth,h=host.clientHeight;if(!w||!h)return;camera.aspect=w/h;camera.updateProjectionMatrix();renderer.setSize(w,h,false)}
  const observer=new ResizeObserver(resize);observer.observe(host);resize()
  await renderer.compileAsync(scene,camera).catch(()=>{})
  renderer.shadowMap.autoUpdate=false;renderer.shadowMap.needsUpdate=true
  renderer.setAnimationLoop(time=>{
    if(disposed||document.hidden)return
    const dt=last?Math.min((time-last)/1000,.04):.016;last=time
    if(mode==='overview')orbit.update()
    else{
      if(journey){journey.t=Math.min(1,journey.t+dt/1.15);const s=journey.t*journey.t*(3-2*journey.t);camera.position.lerpVectors(journey.start,journey.end,s);yaw=T.MathUtils.lerp(journey.startYaw,journey.endYaw,s);pitch=T.MathUtils.lerp(journey.startPitch,journey.endPitch,s);if(journey.t===1)journey=null}
      else if(enabled){const has=(...k)=>k.some(v=>keys.has(v)||held.has(v));let forward=Number(has('KeyW','ArrowUp'))-Number(has('KeyS','ArrowDown')),side=Number(has('KeyD','ArrowRight'))-Number(has('KeyA','ArrowLeft'));const length=Math.hypot(forward,side);if(length){forward/=length;side/=length;const step=dt*2.0,p=moveWithinYard(camera.position,(-Math.sin(yaw)*forward+Math.cos(yaw)*side)*step,(-Math.cos(yaw)*forward-Math.sin(yaw)*side)*step);camera.position.x=p.x;camera.position.z=p.z}}
      camera.rotation.set(pitch,yaw,0,'YXZ')
    }
    renderer.render(scene,camera)
    if(time-previousReport>180){previousReport=time;report({position:{x:camera.position.x,z:camera.position.z,yaw},calls:renderer.info.render.calls})}
  })
  return {
    enter(){enabled=true;canvas.focus({preventScroll:true})},
    pause(value){enabled=!value;orbit.enabled=!value&&mode==='overview';clear()},
    view(index){pose(index);enabled=true;canvas.focus({preventScroll:true})},
    overview(){clear();mode='overview';journey=null;camera.position.set(-14,15,-18);orbit.enabled=true;orbit.target.set(0,1,1);orbit.update();report({mode})},
    hold(key,value){journey=null;if(value)held.add(key);else held.delete(key)},
    step(key){if(!enabled||mode!=='walk')return;journey=null;const f=key==='KeyW'?1:key==='KeyS'?-1:0,s=key==='KeyD'?1:key==='KeyA'?-1:0,p=moveWithinYard(camera.position,(-Math.sin(yaw)*f+Math.cos(yaw)*s)*.55,(-Math.cos(yaw)*f-Math.sin(yaw)*s)*.55);camera.position.x=p.x;camera.position.z=p.z},
    dispose(){if(disposed)return;disposed=true;renderer.setAnimationLoop(null);observer.disconnect();orbit.dispose();clear();canvas.removeEventListener('pointerdown',down);canvas.removeEventListener('pointermove',move);canvas.removeEventListener('pointerup',up);canvas.removeEventListener('pointercancel',up);window.removeEventListener('keydown',keydown);window.removeEventListener('keyup',keyup);window.removeEventListener('blur',clear);document.removeEventListener('visibilitychange',clear);shadowHelper.dispose();sun.shadow.dispose();geos.forEach(o=>o.dispose());extraTextures.forEach(o=>o.dispose());extraMats.forEach(o=>o.dispose());M.dispose();environment.dispose();renderer.dispose();canvas.remove()},
  }
}
