import * as T from 'three'
import photoCameraFit from './photoCameraFit.json'
import { houseClothesline, porchRamps } from './homeLayout.js'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js'
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js'
import { makeHomeMaterials } from './homeMaterials.js'
import { carBodyGeometry, corrugatedSheetGeometry, wornSkirtingGeometry } from './homeDetailGeometry.js'
import { bakeGroundOcclusion } from './homeOcclusion.js'
import { createFacadeGeometry } from './homeFacade.js'
import { createPorchGeometry, createRiserGeometry, createBrokenStepGeometry, createPorchRampGeometry } from './homePorch.js'
import { createGroundCracks, createWallFootWear } from './homeGroundDetail.js'
import { createYardSoilGeometry, createGroundRemnants, createYardWearGeometry } from './homeGroundRegions.js'
import { createWinterTreeGeometry } from './homeTree.js'
import { createIronGatePanel, setGroundOcclusionUV } from './homeGate.js'
import { createMasonryGeometry } from './homeMasonry.js'
import { createTimberGeometry, createWoodenDoorGeometry } from './homeJoinery.js'
import { createPlasterWearGeometry } from './homePlasterWear.js'
import { createGlazingGeometry, createCurtainGeometry, createPaperGeometry } from './homeWindowDetail.js'
import { createGableRoofDeck, createGableWallClosure, createEaveShade } from './homeEaves.js'
import { createRoofTileGeometry, createRoofTileLayout } from './homeRoof.js'
import { createDrainedRim, createSpoutGeometry } from './homeDrain.js'
import { createEaveWeatherTexture, createBeamTileTexture, createTaperedEaveBeam, createFrostedPatternTexture, createPeelingWallSkin, createClearCurtainGeometry } from './homePhotoDetails.js'
import { createSurfaceAging, createWornVarnishTexture } from './homeSurfaceAging.js'
import { createPhotoProjectionMaterial, attachPhotoSurface, photoSources, photoRegions } from './homePhotoProjection.js'
import { homePhotos, housePlacement, houseLocalMinX, houseLocalMaxX, houseWestX, houseDoorLocalX, houseEntry, houseWindows, houseWindowCenterY, houseWindowOpeningHeight, houseWindowRailY, sideWallHeight, frontWallHeight, mainRoof, frontPorch, corridorCanopy, gateX, gateZ, gatePortico, gateApron, gateEastPillarX, gateWestPillarX, eastWall, yardGroundBoundary, courtyardTrees, shedPlacement, shedRoof as shedRoofLayout, shedClerestory, shedSideBrick, shedStepWall, shedBeamY, westWing, westCorridorCanopy, westGroundCorridor, canopyRimSegments, westRoof, westFacade, westBoundary, westWallX, moveWithinYard } from './homeLayout.js'

export async function createChildhoodScene(host, report) {
  const renderer=new T.WebGLRenderer({antialias:true,powerPreference:'default'})
  renderer.setPixelRatio(Math.min(devicePixelRatio,matchMedia('(pointer:fine)').matches?2:1.6));renderer.outputColorSpace=T.SRGBColorSpace
  renderer.toneMapping=T.ACESFilmicToneMapping;renderer.toneMappingExposure=1.05
  renderer.shadowMap.enabled=true;renderer.shadowMap.type=T.PCFSoftShadowMap
  const canvas=renderer.domElement;canvas.tabIndex=0;canvas.setAttribute('aria-label','老家院子三维场景，拖动环顾，WASD或方向按钮行走');host.appendChild(canvas)
  const scene=new T.Scene();scene.background=new T.Color('#cbd0cb');scene.fog=new T.Fog('#cbd0cb',22,65)
  const pmrem=new T.PMREMGenerator(renderer),studio=new RoomEnvironment(),environment=pmrem.fromScene(studio,.06);scene.environment=environment.texture;scene.environmentIntensity=.45;studio.dispose();pmrem.dispose()
  const camera=new T.PerspectiveCamera(64,1,.08,130);camera.rotation.order='YXZ'
  const M=makeHomeMaterials(renderer),geos=new Set(),extraTextures=new Set(),extraMats=new Set()
  const g=value=>{geos.add(value);return value}
  const cube=g(new T.BoxGeometry()),sphere=g(new T.SphereGeometry(1,12,8)),plane=g(new T.PlaneGeometry(1,1))
  const mat=M.mat,wood=M.mapped(M.maps.wood),metal=mat('#4c4940',{metalness:.45,roughness:.7}),dark=mat('#252821'),red=mat('#722d2b',{metalness:.2}),rubber=mat('#252824'),silver=mat('#9c9d8f',{metalness:.65,roughness:.5})
  const white=M.mapped(M.maps.plaster),brick=M.mapped(M.maps.brick,[2,1]),concrete=M.mapped(M.maps.ground,[2,1])
  const clay=M.mapped(M.maps.firedClay,[1,1],{vertexColors:true,roughness:1});
  const varnishTexture=createWornVarnishTexture();extraTextures.add(varnishTexture);
  const joinery=mat('#ffffff',{map:varnishTexture,vertexColors:true,roughness:.82});
  const doorLeafMaterial=mat('#ffffff',{map:varnishTexture,roughness:.88});
  const plainAlbedo=new T.DataTexture(new Uint8Array([255,255,255,255]),1,1);plainAlbedo.needsUpdate=true;extraTextures.add(plainAlbedo);
  const surfacePatina=mat('#ffffff',{vertexColors:true,transparent:true,depthWrite:false,roughness:1,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  function ageSurface(parent,width,height,position,{rotationY=0,rotationX=0,...options}={}){
    const marks=mesh(parent,g(createSurfaceAging(width,height,options)),surfacePatina,position);
    marks.rotation.set(rotationX,rotationY,0);marks.castShadow=false;return marks;
  }
  const mortarMaterial=M.mapped(M.maps.firedClay,[1,1],{vertexColors:true,roughness:1,bumpScale:.003});
  function mesh(parent,geometry,material,p=[0,0,0],s=[1,1,1]){const o=new T.Mesh(geometry,material);o.position.set(...p);o.scale.set(...s);o.castShadow=o.receiveShadow=true;parent.add(o);return o}
  const box=(parent,m,p,s)=>{
    if(!m.map||!m.userData.unit)return mesh(parent,cube,m,p,s)
    const geometry=g(cube.clone()),uv=geometry.attributes.uv,n=geometry.attributes.normal,[u,v]=m.userData.unit
    for(let i=0;i<uv.count;i++){const axis=Math.abs(n.getX(i))>.5?'x':Math.abs(n.getY(i))>.5?'y':'z',a=axis==='x'?s[2]:s[0],b=axis==='y'?s[2]:s[1];uv.setXY(i,uv.getX(i)*a/u/m.map.repeat.x,uv.getY(i)*b/v/m.map.repeat.y)}
    return mesh(parent,geometry,m,p,s)
  }
  const ball=(parent,m,p,s)=>mesh(parent,sphere,m,p,s)
  function exposedJamb(parent,x,y,z,width=.13,height=.48,flip=1){
    const shape=new T.Shape();shape.moveTo(0,0);shape.lineTo(width*.75,.04);shape.lineTo(width,.17);shape.lineTo(width*.56,.28);shape.lineTo(width*.79,height*.86);shape.lineTo(width*.27,height);shape.lineTo(0,height*.93);shape.closePath();
    const geometry=g(new T.ShapeGeometry(shape)),p=geometry.attributes.position,uv=geometry.attributes.uv;
    for(let i=0;i<p.count;i++)uv.setXY(i,(x+p.getX(i))/1.04,(y+p.getY(i))/.64);
    const chip=mesh(parent,geometry,brick,[x,y,z]);chip.castShadow=false;
    if(flip<0){for(let i=0;i<p.count;i++)p.setX(i,-p.getX(i));const indices=geometry.index;for(let i=0;i<indices.count;i+=3){const a=indices.getX(i+1);indices.setX(i+1,indices.getX(i+2));indices.setX(i+2,a)}geometry.computeVertexNormals()}
  }
  let timberSeed=301;const frameNails=[];
  const timber=(parent,p,size,grainAxis='y')=>{
    const length=grainAxis==='x'?size[0]:size[1];
    if(length>.4)for(const side of [-1,1])frameNails.push({parent,position:[p[0]+(grainAxis==='x'?side*(length/2-.06):0),p[1]+(grainAxis==='y'?side*(length/2-.06):0),p[2]+size[2]/2+.002]});
    return mesh(parent,g(createTimberGeometry(...size,{seed:timberSeed++,grainAxis})),joinery,p);
  }
  const oldGlass=mat('#a1b5b5',{roughness:.28,metalness:0,envMapIntensity:.25,transparent:true,opacity:.19,depthWrite:false,vertexColors:true});
  const fadedCurtain=mat('#45545b',{roughness:1});
  function insetCurtain(parent,p,width,height,seed){
    const curtain=mesh(parent,g(createCurtainGeometry(width,height,seed)),fadedCurtain,p);
    curtain.castShadow=false;return curtain;
  }
  function glassPane(parent,p,width,height,seed=0){const o=mesh(parent,g(createGlazingGeometry(width,height,seed)),oldGlass,p);o.castShadow=false;return o}
  function masonry(parent,p,size,seed){
    const parts=createMasonryGeometry(...size,{seed}),origin=[p[0],p[1]-size[1]/2,p[2]];
    mesh(parent,g(parts.bricks),clay,origin);mesh(parent,g(parts.mortar),mortarMaterial,origin);
  }
  function group(parent,p=[0,0,0]){const o=new T.Group();o.position.set(...p);parent.add(o);return o}
  function rod(parent,a,b,r,m){const av=new T.Vector3(...a),bv=new T.Vector3(...b),d=bv.clone().sub(av),o=mesh(parent,g(new T.CylinderGeometry(r*.85,r,d.length(),7)),m,av.clone().add(bv).multiplyScalar(.5).toArray());o.quaternion.setFromUnitVectors(new T.Vector3(0,1,0),d.normalize());return o}
  function face(parent,m,p,s,rotation=0){const o=mesh(parent,plane,m,p,[s[0],s[1],1]);o.rotation.y=rotation;return o}
  let seed=2192015;const rand=()=>{seed=(seed*1664525+1013904223)>>>0;return seed/4294967296}
  const hemi=new T.HemisphereLight('#dce3e6','#77786f',2.1);scene.add(hemi)
  const sun=new T.DirectionalLight('#f2f2e9',.95);sun.position.set(-10,16,3);sun.target.position.set(0,0,0);scene.add(sun,sun.target)
  sun.castShadow=true;sun.shadow.mapSize.set(4096,4096);Object.assign(sun.shadow.camera,{left:-12,right:12,top:12,bottom:-12,near:1,far:48});sun.shadow.normalBias=.04;sun.shadow.bias=-.00015
  const shadowHelper=new T.CameraHelper(sun.shadow.camera);shadowHelper.visible=false;scene.add(shadowHelper)
  const earth=mesh(scene,g(new T.PlaneGeometry(140,140)),M.mapped(M.maps.soil,[16,16]));earth.rotation.x=-Math.PI/2
  // The earlier courtyard top was below the infinite earth plane, hiding its
  // material. This surface sits just above it and has its own unscaled AO UVs.
  const groundWidth=houseWestX+6.3,groundFrontZ=gateZ-1.7,groundBackZ=8,groundDepth=groundBackZ-groundFrontZ,groundGeo=g(new T.PlaneGeometry(groundWidth,groundDepth));
  groundGeo.setAttribute('uv1',groundGeo.attributes.uv.clone());
  const groundAO=bakeGroundOcclusion([-6.3,houseWestX,groundFrontZ,groundBackZ]);extraTextures.add(groundAO);
  const courtyardGround=mesh(scene,groundGeo,M.mapped(M.maps.ground,[groundWidth/4,groundDepth/4],{aoMap:groundAO,aoMapIntensity:.8,bumpScale:.003}),[(houseWestX-6.3)/2,.001,(groundFrontZ+groundBackZ)/2]);
  courtyardGround.rotation.x=-Math.PI/2;courtyardGround.castShadow=false;
  // The hand-marked line is the material edge: poured cement toward the
  // entrance, bare winter soil toward the vehicle shelter and trees.
  const soilGeo=g(createYardSoilGeometry(yardGroundBoundary,{frontZ:groundFrontZ,backZ:groundBackZ,minX:-6.3,maxX:houseWestX}));
  const yardSoil=mesh(scene,soilGeo,M.mapped(M.maps.soil,[groundWidth/4,groundDepth/4],{aoMap:groundAO,aoMapIntensity:1.1}));
  yardSoil.castShadow=false;
  const yardWearMaterial=M.mapped(M.maps.soil,[groundWidth/4,groundDepth/4],{vertexColors:true,transparent:true,depthWrite:false,roughness:1,bumpScale:.008,aoMap:groundAO,aoMapIntensity:1.1,polygonOffset:true,polygonOffsetFactor:-1});
  const yardWear=mesh(scene,g(createYardWearGeometry(yardGroundBoundary,{frontZ:groundFrontZ,backZ:groundBackZ,minX:-6.3,maxX:houseWestX})),yardWearMaterial);yardWear.castShadow=false;
  const groundRemnants=mesh(scene,g(createGroundRemnants(yardGroundBoundary)),mat('#ffffff',{vertexColors:true,transparent:true,depthWrite:false,roughness:1,polygonOffset:true,polygonOffsetFactor:-1}));groundRemnants.castShadow=false;
  // A partly buried brick strip beside the shelter, with missing bricks and
  // irregular joints. It is not a new, complete paved path across the yard.
  const buriedBricks=new T.InstancedMesh(g(new RoundedBoxGeometry(.235,.035,.115,1,.004)),mat('#ffffff',{roughness:1}),34);
  const buriedPose=new T.Object3D(),buriedColor=new T.Color();
  for(let i=0;i<34;i++){
    const row=Math.floor(i/2),side=i%2;
    buriedPose.position.set(-5.65+side*.26+Math.sin(i*2.17)*.035,-.008+(i%4)*.002,-.75+row*.30);
    buriedPose.rotation.set(Math.sin(i)*.035,Math.sin(i*1.73)*.12,Math.cos(i)*.025);buriedPose.updateMatrix();buriedBricks.setMatrixAt(i,buriedPose.matrix);
    buriedColor.set(['#887b69','#97836f','#8e8271','#a08c76'][i%4]);buriedBricks.setColorAt(i,buriedColor);
  }
  buriedBricks.receiveShadow=true;scene.add(buriedBricks);
  // Confirmed by the owner: the house faces the entrance across the yard.
  const house=group(scene,housePlacement.position);house.rotation.y=housePlacement.rotationY;house.scale.x=housePlacement.scaleX
  const doorX=houseDoorLocalX,doorW=houseEntry.woodWidth,doorH=2.65
  mesh(house,g(createFacadeGeometry({minX:houseLocalMinX,maxX:houseLocalMaxX,height:5,doorX,windows:houseWindows})),white);
  const facadeMid=(houseLocalMinX+houseLocalMaxX)/2;
  const facadeOpenings=[{left:doorX-houseEntry.openingWidth/2-facadeMid,right:doorX+houseEntry.openingWidth/2-facadeMid,bottom:0,top:3.78},...houseWindows.map(w=>({left:w.x-w.width/2-.08-facadeMid,right:w.x+w.width/2+.08-facadeMid,bottom:houseWindowCenterY-houseWindowOpeningHeight/2-.12,top:houseWindowCenterY+houseWindowOpeningHeight/2+.1}))];
  ageSurface(house,houseLocalMaxX-houseLocalMinX,4.88,[facadeMid,0,.174],{seed:21915,openings:facadeOpenings});
  for(const side of [-1,1])ageSurface(house,6,4.9,[side<0?houseLocalMinX-.006:houseLocalMaxX+.006,0,-3.1],{rotationY:side*Math.PI/2,seed:21916+side,strength:.65});
  // A single building volume and continuous facade, not a lower side annex.
  // Hollow shell: the former solid box filled every opening behind the facade.
  // Keep the established exterior footprint and give the open door real depth.
  const houseWidth=houseLocalMaxX-houseLocalMinX,wallThickness=.30;
  box(house,white,[facadeMid,2.5,-5.95],[houseWidth,5,wallThickness]);
  for(const x of [houseLocalMinX+wallThickness/2,houseLocalMaxX-wallThickness/2]){
    box(house,white,[x,2.5,-3.10],[wallThickness,5,6]);
  }
  const roomFloor=M.mapped(M.maps.ground,[houseWidth/4,1.45],{color:'#777369',roughness:1,bumpScale:.003});
  box(house,roomFloor,[facadeMid,frontPorch.height-.06,-3.0],[houseWidth-wallThickness*2,.12,5.80]);
  box(house,mat('#85847a',{roughness:1}),[facadeMid,4.90,-3.0],[houseWidth-wallThickness*2,.20,5.80]);
  // The photographs do not establish furniture or a room plan; leave the
  // interior unpartitioned rather than inventing objects behind the doorway.
  box(house,mat('#57534b',{roughness:1}),[facadeMid,2.55,-5.788],[houseWidth-wallThickness*2,4.50,.025]);
  const porchConcrete=M.mapped(M.maps.ground,[1,1],{vertexColors:true,roughness:1,bumpScale:.003});
  const porchFront=frontPorch.centerZ+frontPorch.depth/2;
  mesh(house,g(createPorchGeometry(frontPorch.width,frontPorch.depth,frontPorch.height)),porchConcrete,[frontPorch.centerX,0,frontPorch.centerZ]);
  ageSurface(house,frontPorch.width,frontPorch.depth,[frontPorch.centerX,frontPorch.height+.003,frontPorch.centerZ+frontPorch.depth/2],{rotationX:-Math.PI/2,seed:3315,strength:.6});
  // Photos 01 and the west-room overview show a shallow broken lower course,
  // not the former full-width black groove and two ruler-straight raised rails.
  const stepBrickMaterial=mat('#ffffff',{vertexColors:true,roughness:1});
  let stepStart=frontPorch.centerX-frontPorch.width/2;
  for(const end of [...porchRamps.map(r=>({left:r.centerX-r.width/2,right:r.centerX+r.width/2})),{left:frontPorch.centerX+frontPorch.width/2}]){
    const width=end.left-stepStart,center=(stepStart+end.left)/2;
    if(width>0){
      mesh(house,g(createRiserGeometry(width)),stepBrickMaterial,[center,0,porchFront-.065]);
      mesh(house,g(createBrokenStepGeometry(width,.40,.085,47)),porchConcrete,[center,0,porchFront+.19]);
      const lowerBrick=mesh(house,g(createRiserGeometry(width,Math.max(1,Math.round(width/.14)),2026)),stepBrickMaterial,[center,0,porchFront+.285]);lowerBrick.scale.y=.31;
    }
    stepStart=end.right;
  }
  for(const [i,ramp] of porchRamps.entries()){
    mesh(house,g(createPorchRampGeometry(ramp.width,ramp.run,frontPorch.height,2415+i)),porchConcrete,[ramp.centerX,0,porchFront+ramp.run/2-.008]);
  }
  // Worn cement skirting in the reference, kept clear of the doorway.
  const wallCement=M.mapped(M.maps.plaster,[1,1],{color:'#a5a69f',bumpScale:.009,vertexColors:true});
  const flakingPlaster=mat('#ffffff',{vertexColors:true,transparent:true,depthWrite:false,roughness:1,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  const doorwayEdge=houseEntry.openingWidth/2+.06;
  for(const [a,b] of [[houseLocalMinX,doorX-doorwayEdge],[doorX+doorwayEdge,houseLocalMaxX]]){
    mesh(house,g(wornSkirtingGeometry(b-a,.44,a)),wallCement,[(a+b)/2,.28,.167]);
    const flakes=mesh(house,g(createPlasterWearGeometry(b-a,{seed:2192015+Math.round(a*100)})),flakingPlaster,[(a+b)/2,.28,.168]);flakes.castShadow=false;
  }
  const fu=M.writing('福','#792626','#b59351');fu.vertexColors=true;
  // A flush, worn sill connects the porch to the doorway, without adding a
  // raised obstacle beneath the closed leaves.
  mesh(house,g(createPorchGeometry(doorW+.08,.36,.022,21937,.004)),porchConcrete,[doorX,frontPorch.height-.022,.20]);
  // Two full-height leaves read as a real main entrance, wider than the wing doors.
  for(const side of [-1,1]){
    const leafW=doorW/2,door=group(house,[doorX+side*doorW/2,.28,.25]);door.rotation.y=0;
    const doorGeometry=g(createWoodenDoorGeometry(leafW,doorH,.075,{seed:2016}));
    const doorPositions=doorGeometry.attributes.position,doorUv=doorGeometry.attributes.uv;
    for(let i=0;i<doorUv.count;i++)doorUv.setXY(i,doorPositions.getX(i)/leafW+.5,doorPositions.getY(i)/doorH+.5);
    mesh(door,doorGeometry,doorLeafMaterial,[-side*leafW/2,doorH/2,0]);
    const handleX=-side*(leafW-.12);
    box(door,silver,[handleX,1.2,.075],[.035,.16,.035]);
    for(const y of [.42,2.14]){
      box(door,metal,[-side*.055,y,.061],[.11,.17,.018]);
      rod(door,[-side*.015,y-.12,.062],[-side*.015,y+.12,.062],.018,metal);
    }
    mesh(door,g(createPaperGeometry(.42,.55,2015)),fu,[-side*leafW/2,1.93,.042]).castShadow=false;
  }
  const couplet=M.writing('平安顺遂岁岁春','#852522','#bf9b46',true)
  couplet.vertexColors=true;
  for(const side of [-1,1])mesh(house,g(createPaperGeometry(.105,1.75,side+2015)),couplet,[doorX+side*(doorW/2+.03),2.11,.302]).castShadow=false;
  timber(house,[doorX,3.05,.2],[houseEntry.transomWidth+.1,.09,.15],'x');
  // Photo 1: a broad wooden transom and narrow glazed sidelights frame the door.
  const transomW=houseEntry.transomWidth,transomCellW=transomW/5;
  box(house,dark,[doorX,3.39,.205],[transomW,.6,.035]);
  for(const y of [3.07,3.72])timber(house,[doorX,y,.25],[transomW+.15,.07,.1],'x');
  for(let i=0;i<6;i++)timber(house,[doorX-transomW/2+i*transomCellW,3.39,.25],[.055,.64,.1]);
  timber(house,[doorX,3.42,.25],[transomW,.035,.075],'x');
  for(let i=0;i<5;i++){
    const x=doorX-transomW/2+(i+.5)*transomCellW;
    insetCurtain(house,[x,3.39,.226],transomCellW-.065,.58,91+i);
    glassPane(house,[x,3.39,.247],transomCellW-.055,.60,i);
  }
  for(let i=0;i<15;i++){const x=doorX-transomW/2+(i+.5)*transomW/15;rod(house,[x,3.11,.322],[x,3.68,.322],.01,metal)}
  for(const side of [-1,1]){
    const x=doorX+side*houseEntry.sideWindowOffset,w=houseEntry.sideWindowWidth,lowerRailY=houseWindowCenterY-houseWindowOpeningHeight/2+.04,upperRailY=3.0;
    const h=upperRailY-lowerRailY,centerY=(upperRailY+lowerRailY)/2;
    // The glazed sidelights now start on the same horizontal line as the two
    // large windows; solid plaster fills their former low glazed portion.
    box(house,white,[x,lowerRailY/2,.18],[w,lowerRailY,.06]);
    box(house,dark,[x,centerY,.2],[w,h,.04]);
    insetCurtain(house,[x,centerY,.228],w-.065,h-.07,side+94);
    for(const dx of [-w/2-.015,w/2+.015])timber(house,[x+dx,centerY,.25],[.045,h+.08,.08]);
    for(const y of [lowerRailY,centerY,upperRailY])timber(house,[x,y,.26],[w+.05,.045,.07],'x');
    for(const y of [(lowerRailY+centerY)/2,(centerY+upperRailY)/2])glassPane(house,[x,y,.252],w-.03,h/2-.06,y+side);
    for(const dx of [-w/3,0,w/3])rod(house,[x+dx,lowerRailY,.30],[x+dx,upperRailY,.30],.009,metal);
  }
  // Old wooden windows, dark interiors and separate iron bars.
  const windowCurtains=[];
  function windowAt(x,width=2.5){
    const win=group(house,[x,houseWindowCenterY,0]);
    const halfH=houseWindowOpeningHeight/2,innerTop=halfH-.04,dividerY=houseWindowRailY-houseWindowCenterY;
    box(win,mat('#222c2e'),[0,0,-.045],[width,houseWindowOpeningHeight-.11,.018]);
    for(const side of [-1,1])timber(win,[side*(width/2+.025),0,.105],[.085,houseWindowOpeningHeight+.09,.16]);
    for(const y of [-halfH,halfH])timber(win,[0,y,.105],[width+.13,.085,.16],'x');
    for(let j=1;j<3;j++)timber(win,[-width/2+j*width/3,0,.073],[.055,houseWindowOpeningHeight,.11]);
    for(const y of [-innerTop,dividerY,innerTop])timber(win,[0,y,.073],[width,.06,.11],'x');
    for(let j=0;j<10;j++)rod(win,[-width/2+j*width/9,-halfH+.08,.19],[-width/2+j*width/9,halfH-.08,.19],.012,metal)
    mesh(win,g(createPorchGeometry(width+.23,.38,.12,Math.round(x*100)+2900)),porchConcrete,[0,-halfH-.13,.13]);
    // Thin wood beading catches daylight around the inset glazing.
    for(const y of [-innerTop+.03,dividerY-.035,innerTop-.03])timber(win,[0,y,.014],[width-.06,.014,.025],'x');
    const curtain=mesh(win,g(createCurtainGeometry(width-.14,houseWindowOpeningHeight-.12,x)),mat('#465055',{roughness:1}),[0,.025,-.024]);curtain.castShadow=false;windowCurtains.push(curtain);
    const lowerPane=[(-innerTop+dividerY)/2,innerTop+dividerY-.08],upperPane=[(dividerY+innerTop)/2,innerTop-dividerY-.08];
    for(let i=0;i<3;i++)for(const [y,h] of [lowerPane,upperPane])glassPane(win,[-width/2+(i+.5)*width/3,y,.006],width/3-.065,h,i+x);
  }
  for(const win of houseWindows)windowAt(win.x,win.width)
  for(const side of [-1,1])exposedJamb(house,doorX+side*(houseEntry.openingWidth/2+.01),.29,.179,.12,.57,side);
  for(const w of houseWindows)exposedJamb(house,w.x-w.width/2-.055,houseWindowCenterY-houseWindowOpeningHeight/2-.10,.179,.095,.30,-1);
  const entryPlaque=M.writing('主恩常在','#a32625','#ead26c');
  mesh(house,g(new T.PlaneGeometry(.5,.11)),entryPlaque,[doorX,3.085,.312]).castShadow=false;
  const roofWidth=mainRoof.width;
  mesh(house,g(createGableWallClosure({minX:houseLocalMinX,maxX:houseLocalMaxX,wallTop:5,eaveY:mainRoof.eaveY,ridgeZ:mainRoof.centerZ,depth:mainRoof.depth,rise:mainRoof.rise,roofThickness:mainRoof.thickness})),white);
  const roofBed=M.mapped(M.maps.firedClay,[1,1],{color:'#626866',bumpScale:.002,roughness:1});
  // Confirmed continuous, level concrete platform above the full corridor.
  // Owner corrected both ends to be flush with the full house side walls.
  const canopyConcrete=M.mapped(M.maps.plaster,[1,1],{color:'#bdc0b8',vertexColors:true,flatShading:true,roughness:1,bumpScale:.006});
  mesh(house,g(createPorchGeometry(corridorCanopy.width,corridorCanopy.depth,corridorCanopy.thickness,1985)),canopyConcrete,[corridorCanopy.centerX,corridorCanopy.baseY,corridorCanopy.centerZ]);
  const eaveShade=mat('#ffffff',{vertexColors:true,transparent:true,depthWrite:false,roughness:1,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  const shade=mesh(house,g(createEaveShade(corridorCanopy.width)),eaveShade,[corridorCanopy.centerX,corridorCanopy.baseY-.095,.154]);shade.castShadow=false;
  const roofClay=M.mapped(M.maps.firedClay,[1,1],{color:'#656e70',vertexColors:true,bumpScale:.0018,roughness:1});
  const mainRoofClay=M.mapped(M.maps.firedClay,[1,1],{color:'#93674c',vertexColors:true,bumpScale:.0018,roughness:1});
  const mainRoofBed=M.mapped(M.maps.firedClay,[1,1],{color:'#79543f',bumpScale:.002,roughness:1});
  function tiledRoof(parent,width,depth,center,tilt,tileMaterial){
    const layout=createRoofTileLayout(width,depth,{tilt,seed:Math.round(width*100+depth*10)}),pose=new T.Object3D(),color=new T.Color();
    for(const kind of ['pan','cap'])for(let variant=0;variant<3;variant++){
      const parts=layout[kind==='pan'?'pans':'caps'].filter(tile=>tile.variant===variant);if(!parts.length)continue;
      const first=parts[0],geometry=g(createRoofTileGeometry({kind,width:first.width,length:first.length,thickness:first.thickness,seed:variant+2015}));
      const tiles=new T.InstancedMesh(geometry,tileMaterial,parts.length);
      for(let i=0;i<parts.length;i++){
        const tile=parts[i];pose.position.set(center[0]+tile.position[0],center[1]+tile.position[1],center[2]+tile.position[2]);pose.rotation.set(...tile.rotation);pose.updateMatrix();tiles.setMatrixAt(i,pose.matrix);color.setRGB(...tile.color);tiles.setColorAt(i,color);
      }
      tiles.computeBoundingBox();tiles.computeBoundingSphere();tiles.castShadow=tiles.receiveShadow=true;parent.add(tiles);
    }
  }
  function gabledRoof(parent,roof,tileMaterial=roofClay,bedMaterial=roofBed){
    mesh(parent,g(createGableRoofDeck(roof.width,roof.depth,{rise:roof.rise,thickness:roof.thickness})),bedMaterial,[roof.centerX,roof.eaveY,roof.centerZ]);
    const half=roof.depth/2,tilt=Math.atan(roof.rise/half);
    for(const rotation of [0,Math.PI]){
      const slope=group(parent,[roof.centerX,roof.eaveY+roof.rise,roof.centerZ]);slope.rotation.y=rotation;
      tiledRoof(slope,roof.width,half,[0,-roof.rise/2,half/2],tilt,tileMaterial);
    }
    const count=Math.ceil(roof.width/.33),length=.38,step=(roof.width-length)/(count-1),pose=new T.Object3D();
    const ridge=new T.InstancedMesh(g(createRoofTileGeometry({kind:'cap',width:.36,length,thickness:.014,seed:1938})),tileMaterial,count);
    for(let i=0;i<count;i++){
      pose.position.set(roof.centerX-roof.width/2+length/2+i*step,roof.eaveY+roof.rise-.015+(i%2)*.002,roof.centerZ);
      pose.rotation.set(0,Math.PI/2,0);pose.updateMatrix();ridge.setMatrixAt(i,pose.matrix);
      const tone=.9+.045*Math.sin(i*2.17)+.025*Math.sin(i*.51);
      ridge.setColorAt(i,new T.Color().setRGB(tone,tone*.992,tone*.98));
    }
    ridge.castShadow=ridge.receiveShadow=true;parent.add(ridge);
    // The old ridge has raised end closures and a thin mortar bed beneath
    // the cap tiles, visible in the side-house roof reference.
    const ridgeMortar=M.mapped(M.maps.plaster,[1,1],{color:'#92897a',roughness:1});
    box(parent,ridgeMortar,[roof.centerX,roof.eaveY+roof.rise-.032,roof.centerZ],[roof.width,.048,.16]);
    for(const side of [-1,1]){
      const tip=new T.Shape();tip.moveTo(-.16,0);tip.lineTo(.16,0);tip.lineTo(.11,.075);tip.lineTo(.055,.19);tip.lineTo(-.015,.23);tip.lineTo(-.035,.09);tip.closePath();
      const geometry=g(new T.ExtrudeGeometry(tip,{depth:.07,bevelEnabled:true,bevelSize:.006,bevelThickness:.006,bevelSegments:1,steps:1}));
      mesh(parent,geometry,bedMaterial,[roof.centerX+side*(roof.width/2-.16),roof.eaveY+roof.rise-.01,roof.centerZ-.035]);
    }
  }
  gabledRoof(house,mainRoof,mainRoofClay,mainRoofBed);
  // Preserve the old random sequence used by unrelated courtyard details.
  for(let i=0;i<Math.ceil(6.8/.41)*Math.floor(roofWidth/.186)*2;i++)rand();
  // Washing machine, thermos and a blue garment under a sagging clothesline.
  // Keep the washing machine beside, rather than inside, the relocated doorway.
  // Photo-estimated twin-tub proportions. Compensate for the house's .8 X
  // scale and resize about the appliance centre at the porch floor.
  const washerScale=[1.4,.8,.85];
  // In neural.html's 01-house / passage views it sits below the east
  // sidelight, against the facade, to the left of the passage door.
  // Sampled visible appliance surfaces are around local Z .46–.67;
  // keep the back 4 cm clear of the facade plane at Z .15.
  const washerCenter=[houseDoorLocalX+houseEntry.sideWindowOffset+.46,frontPorch.height,.15+.04+.68*washerScale[2]/2];
  const washer=group(house,[washerCenter[0]-1.38*washerScale[0],washerCenter[1]*(1-washerScale[1]),washerCenter[2]-.7*washerScale[2]]);
  washer.scale.set(...washerScale);
  const washerPlastic=mat('#ffffff',{map:plainAlbedo,roughness:.65}),washerTrim=mat('#c8c8c6',{roughness:.58});
  mesh(washer,g(new RoundedBoxGeometry(.78,1,.68,3,.045)),washerPlastic,[1.38,.79,.7]);
  mesh(washer,g(new RoundedBoxGeometry(.81,.08,.72,3,.035)),washerTrim,[1.38,1.30,.7]);
  for(const [x,w] of [[1.23,.45],[1.62,.25]])mesh(washer,g(new RoundedBoxGeometry(w,.035,.52,2,.025)),mat('#e6e5e2',{roughness:.48}),[x,1.35,.76]);
  for(const x of [1.1,1.39,1.64])mesh(washer,g(new T.CylinderGeometry(.035,.037,.025,12)),washerTrim,[x,1.355,.43]);
  // The reference has neutral off-white plastic, with a recessed front seam.
  const washerSeam=mat('#aaa9a6',{roughness:.8});
  box(washer,washerSeam,[1.38,.58,1.041],[.69,.012,.004]);
  box(washer,washerTrim,[1.38,.35,1.042],[.68,.06,.008]);
  const coverGeometry=g(new T.PlaneGeometry(.92,1.0,24,28)),coverVertices=coverGeometry.attributes.position;
  for(let i=0;i<coverVertices.count;i++){
    const x=coverVertices.getX(i),z=coverVertices.getY(i)+.13;
    const drape=Math.max(0,z-.35);
    coverVertices.setXYZ(i,x,1.405-drape*.95+Math.sin(x*38)*(.004+drape*.035),.7+Math.min(z,.35)+drape*.12);
  }
  coverGeometry.computeVertexNormals();
  mesh(washer,coverGeometry,mat('#bb97b3',{roughness:1,side:T.DoubleSide}),[1.38,0,0]);
  for(const x of [1.09,1.67])for(const z of [.46,.95])box(washer,washerTrim,[x,.3,z],[.10,.045,.09]);
  const hosePath=[[1.79,.85,.45],[1.87,.38,.5],[1.95,.32,.85],[2.02,.30,1.05]].map(p=>new T.Vector3(...p));
  mesh(washer,g(new T.TubeGeometry(new T.CatmullRomCurve3(hosePath),16,.018,6,false)),washerTrim);
  const thermos=mesh(house,g(new T.CylinderGeometry(.095,.105,.48,16)),mat('#e2ddc5'),[houseClothesline.garmentX,.53,.87]);mesh(house,g(new T.CylinderGeometry(.052,.065,.1,12)),silver,[houseClothesline.garmentX,.82,.87])
  rod(house,[-2.08,.42,.87],[-2.02,.47,.87],.014,silver);rod(house,[-2.02,.47,.87],[-2.02,.68,.87],.014,silver);rod(house,[-2.02,.68,.87],[-2.08,.72,.87],.014,silver);
  const ropeMaterial=mat('#943d49',{roughness:1});
  const clothesline=new T.QuadraticBezierCurve3(...[houseClothesline.start,houseClothesline.control,houseClothesline.end].map(p=>new T.Vector3(...p)));
  mesh(house,g(new T.TubeGeometry(clothesline,48,.006,6,false)),ropeMaterial);
  const [tieX,tieY,tieZ]=houseClothesline.end;
  // Two wraps and a short loose tail make the attachment to the sidelight's
  // central iron bar visible without introducing a line across the door.
  for(const dy of [-.009,.009])mesh(house,g(new T.TorusGeometry(.014,.004,5,16)),ropeMaterial,[tieX,tieY+dy,tieZ]).rotation.x=Math.PI/2;
  ball(house,ropeMaterial,[tieX-.012,tieY,tieZ+.01],[.013,.014,.01]);
  rod(house,[tieX-.012,tieY,tieZ+.01],[tieX-.045,tieY-.10,tieZ+.018],.004,ropeMaterial);
  const garmentT=(houseClothesline.garmentX-houseClothesline.start[0])/(houseClothesline.end[0]-houseClothesline.start[0]);
  const garmentTop=clothesline.getPoint(garmentT);
  // Two unequal hanging halves fold over the line. Each upper vertex follows
  // the rope, so its changing sag does not leave the fabric floating nearby.
  const laundryMaterial=mat('#23768c',{side:T.DoubleSide,roughness:1});
  for(const [length,side] of [[1.2,1],[.94,-1]]){
    const geometry=g(new T.PlaneGeometry(.37,length,18,28)),p=geometry.attributes.position;
    for(let i=0;i<p.count;i++){
      const x=p.getX(i),drop=(length/2-p.getY(i))/length;
      const t=(garmentTop.x+x-houseClothesline.start[0])/(houseClothesline.end[0]-houseClothesline.start[0]);
      const top=clothesline.getPoint(t),free=Math.sin(drop*Math.PI/2);
      p.setXYZ(i,x+Math.sin(drop*2)*.022,top.y-garmentTop.y-drop*length+Math.sin(x*14)*drop*.018,
        top.z-garmentTop.z+side*(.009+free*.022)+Math.sin(x*39+drop*1.8)*free*.021);
    }
    geometry.computeVertexNormals();mesh(house,geometry,laundryMaterial,garmentTop.toArray());
  }
  const foldGeometry=g(new T.PlaneGeometry(.37,1,18,8)),foldPositions=foldGeometry.attributes.position;
  for(let i=0;i<foldPositions.count;i++){
    const x=foldPositions.getX(i),angle=(foldPositions.getY(i)+.5)*Math.PI;
    const top=clothesline.getPoint((garmentTop.x+x-houseClothesline.start[0])/(houseClothesline.end[0]-houseClothesline.start[0]));
    foldPositions.setXYZ(i,x,top.y-garmentTop.y+Math.sin(angle)*.009,top.z-garmentTop.z+Math.cos(angle)*.009);
  }
  foldGeometry.computeVertexNormals();mesh(house,foldGeometry,laundryMaterial,garmentTop.toArray());
  // Western rooms belong to this house: a continuous wing, not separate annexes.
  const wing=group(scene,westWing.position);wing.rotation.y=westWing.rotationY;
  box(wing,white,[0,westWing.wallHeight/2,0],[westWing.width,westWing.wallHeight,westWing.depth]);
  const wingOpenings=[...westFacade.doors.map(d=>({left:d.z-westWing.position[2]-d.width/2-.12,right:d.z-westWing.position[2]+d.width/2+.12,bottom:0,top:westFacade.doorTopY+.1})),...westFacade.windows.map(w=>({left:w.z-westWing.position[2]-w.width/2-.1,right:w.z-westWing.position[2]+w.width/2+.1,bottom:westFacade.windowCenterY-w.height/2-.14,top:westFacade.windowCenterY+w.height/2+.1}))];
  ageSurface(wing,westWing.width,4.88,[0,0,westWing.depth/2+.012],{seed:22915,openings:wingOpenings,strength:1.25});
  ageSurface(wing,westWing.depth,4.9,[-westWing.width/2-.006,0,0],{rotationY:-Math.PI/2,seed:22916,strength:.8});
  mesh(wing,g(createGableWallClosure({minX:westRoof.wallMinX,maxX:westRoof.wallMaxX,backZ:-westWing.depth/2,frontZ:westWing.depth/2,wallTop:westWing.wallHeight,eaveY:westRoof.eaveY,ridgeZ:westRoof.centerZ,depth:westRoof.depth,rise:westRoof.rise,roofThickness:westRoof.thickness})),white);
  // Flat eave extension over the full western corridor, joined to the main
  // house platform at its rear end, with a shorter 0.9 m projection.
  mesh(wing,g(createPorchGeometry(westCorridorCanopy.width,westCorridorCanopy.depth,westCorridorCanopy.thickness,1986)),canopyConcrete,[westCorridorCanopy.centerX,westCorridorCanopy.baseY,westCorridorCanopy.centerZ]);
  const canopyRimConcrete=M.mapped(M.maps.plaster,[1,1],{color:'#bdc0b8',roughness:1,bumpScale:.006});
  const tileCanvas=document.createElement('canvas');tileCanvas.width=384;tileCanvas.height=192;
  const tileContext=tileCanvas.getContext('2d');
  tileContext.fillStyle='#867665';tileContext.fillRect(0,0,384,192);
  for(let row=0;row<3;row++)for(let col=0;col<6;col++){
    tileContext.fillStyle=(row+col)%2?'#b28a50':'#64483d';
    tileContext.fillRect(col*64+1,row*64+1,62,62);
    tileContext.strokeStyle=(row+col)%2?'#c69d63':'#78574a';tileContext.lineWidth=.7;tileContext.strokeRect(col*64+2,row*64+2,60,60);
  }
  const tileMap=new T.CanvasTexture(tileCanvas);tileMap.colorSpace=T.SRGBColorSpace;
  tileMap.wrapS=tileMap.wrapT=T.RepeatWrapping;extraTextures.add(tileMap);
  const rimTileMaterial=mat('#ffffff',{map:tileMap,roughness:.38,metalness:0});
  // The close references show a muted plum-brown floral fascia,
  // with pale ornaments and narrow cream borders below the checkerboard.
  const porcelainCanvas=document.createElement('canvas');porcelainCanvas.width=256;porcelainCanvas.height=180;
  const porcelain=porcelainCanvas.getContext('2d');
  porcelain.fillStyle='#82736a';porcelain.fillRect(0,0,256,180);
  porcelain.fillStyle='#866569';porcelain.fillRect(2,2,252,176);
  porcelain.strokeStyle='#d5c9bc';porcelain.lineWidth=2;
  porcelain.strokeRect(8,8,240,164);porcelain.strokeRect(12,12,232,156);
  porcelain.save();porcelain.translate(128,90);
  for(let petal=0;petal<8;petal++){
    porcelain.save();porcelain.rotate(petal*Math.PI/4);
    porcelain.beginPath();porcelain.moveTo(0,-7);porcelain.bezierCurveTo(-21,-23,-16,-47,0,-49);porcelain.bezierCurveTo(16,-47,21,-23,0,-7);
    porcelain.fillStyle=petal%2?'#d2c7b9':'#e1d8c7';porcelain.fill();porcelain.stroke();porcelain.restore();
  }
  porcelain.beginPath();porcelain.arc(0,0,9,0,Math.PI*2);porcelain.fillStyle='#d8c9b5';porcelain.fill();
  for(const side of [-1,1]){
    porcelain.save();porcelain.scale(side,1);porcelain.beginPath();porcelain.moveTo(45,16);porcelain.bezierCurveTo(89,24,61,-30,101,-34);porcelain.stroke();
    for(const [x,y,angle] of [[65,5,-.5],[79,-20,.5],[97,-35,-.6]]){
      porcelain.save();porcelain.translate(x,y);porcelain.rotate(angle);porcelain.beginPath();porcelain.ellipse(0,0,10,4,0,0,Math.PI*2);porcelain.fill();porcelain.restore();
    }porcelain.restore();
  }porcelain.restore();
  const porcelainMap=new T.CanvasTexture(porcelainCanvas);porcelainMap.colorSpace=T.SRGBColorSpace;
  porcelainMap.wrapS=porcelainMap.wrapT=T.RepeatWrapping;extraTextures.add(porcelainMap);
  const porcelainMaterial=mat('#ffffff',{map:porcelainMap,roughness:.43,metalness:0});
  function porcelainFace(parent,width,height,position,rotation){
    const geometry=g(new T.PlaneGeometry(width,height)),uv=geometry.attributes.uv;
    for(let i=0;i<uv.count;i++)uv.setX(i,uv.getX(i)*width/.2);
    const face=mesh(parent,geometry,porcelainMaterial,position);face.rotation.y=rotation;
  }
  const spoutMaterial=mat('#78452d',{roughness:.23,metalness:0});
  const spoutGeometry=g(createSpoutGeometry());
  const spoutJoint=mat('#7f786a',{roughness:1});
  const spoutJointGeometry=g(new T.RingGeometry(.079,.087,32));
  for(const [index,{position,size,drains}] of canopyRimSegments.entries()){
    const alongZ=size[2]>size[0],length=alongZ?size[2]:size[0],depth=alongZ?size[0]:size[2];
    const rim=group(scene,position);if(alongZ)rim.rotation.y=index===0?-Math.PI/2:Math.PI/2;
    const {geometry,centers,y}=drains?createDrainedRim(length,size[1],depth,drains):{geometry:new T.BoxGeometry(length,size[1],depth).toNonIndexed(),centers:[],y:0};
    // Wrap tiles onto segment end faces so butt joints and exposed corners
    // do not leave bare concrete strips. Keep the pipe bores and top concrete.
    geometry.clearGroups();
    const normals=geometry.attributes.normal,vertices=geometry.attributes.position,uv=geometry.attributes.uv;
    for(let i=0;i<vertices.count;i+=3){
      const front=normals.getZ(i)<-.99;
      const end=Math.abs(normals.getX(i))>.99&&[i,i+1,i+2].every(j=>Math.abs(Math.abs(vertices.getX(j))-length/2)<1e-5);
      const tiled=front||end;
      const materialIndex=tiled?1:0,lastGroup=geometry.groups.at(-1);
      if(lastGroup?.materialIndex===materialIndex)lastGroup.count+=3;
      else geometry.addGroup(i,3,materialIndex);
      if(tiled)for(let j=i;j<i+3;j++)uv.setXY(j,(front?vertices.getX(j)+length/2:vertices.getZ(j)+depth/2)/.4,(vertices.getY(j)+size[1]/2)/.2);
    }
    // The checker joints follow the wall's horizontal/vertical metre UVs.
    // Do not project a photographed strip here: its residual perspective
    // makes every repeated set of joints appear slanted.
    mesh(rim,g(geometry),[canopyRimConcrete,rimTileMaterial]);
    const fasciaHeight=corridorCanopy.thickness,fasciaY=-size[1]/2-fasciaHeight/2;
    porcelainFace(rim,length,fasciaHeight,[0,fasciaY,-depth/2-.004],Math.PI);
    for(const side of [-1,1])porcelainFace(rim,depth,fasciaHeight,[side*(length/2+.004),fasciaY,0],side*Math.PI/2);
    for(const x of centers){
      mesh(rim,spoutGeometry,spoutMaterial,[x,y,0]);
      const joint=mesh(rim,spoutJointGeometry,spoutJoint,[x,y,-depth/2-.001]);joint.rotation.y=Math.PI;
    }
  }
  const eaveWeather=createEaveWeatherTexture(),beamTile=createBeamTileTexture();
  extraTextures.add(eaveWeather);extraTextures.add(beamTile);
  const beamTileMaterial=mat('#ffffff',{map:beamTile,roughness:.48});
  const agedBeam=M.mapped(M.maps.plaster,[1,1],{color:'#c3beb0',roughness:1});
  function finishEave(parent,canopy,wallZ,count,scaleX=1){
    const edge=canopy.centerZ+canopy.depth/2;
    const weatherMap=eaveWeather.clone();weatherMap.wrapS=T.RepeatWrapping;weatherMap.repeat.x=canopy.width*scaleX/4;extraTextures.add(weatherMap);
    const eaveWearMaterial=mat('#ffffff',{map:weatherMap,transparent:true,depthWrite:false,roughness:1,polygonOffset:true,polygonOffsetFactor:-1});
    const wear=mesh(parent,g(new T.PlaneGeometry(canopy.width,.66)),eaveWearMaterial,[canopy.centerX,canopy.baseY-.33,wallZ+.008]);wear.castShadow=false;
    const underside=mesh(parent,g(new T.PlaneGeometry(canopy.width,canopy.depth)),eaveWearMaterial,[canopy.centerX,canopy.baseY-.004,canopy.centerZ]);underside.rotation.x=Math.PI/2;underside.castShadow=false;
    for(let i=0;i<count;i++){
      const x=canopy.centerX-canopy.width/2+.25/scaleX+(canopy.width-.5/scaleX)*i/(count-1);
      mesh(parent,g(createTaperedEaveBeam(.30/scaleX,edge-wallZ)),agedBeam,[x,canopy.baseY,(wallZ+edge)/2]);
      mesh(parent,g(new T.PlaneGeometry(.195/scaleX,.195)),beamTileMaterial,[x,canopy.baseY-.11,edge+.004]);
    }
  }
  finishEave(house,corridorCanopy,.15,4,housePlacement.scaleX);
  finishEave(wing,westCorridorCanopy,westWing.depth/2,3);
  const wingShade=mesh(wing,g(createEaveShade(westWing.width,{height:.16,seed:2})),eaveShade,[0,westCorridorCanopy.baseY+.01,1.604]);wingShade.castShadow=false;
  gabledRoof(wing,westRoof);
  for(let i=0;i<Math.ceil(3.6/.41)*Math.floor(7.35/.14)*2;i++)rand();
  // One raised west corridor replaces the old two disconnected brick lips.
  // Its top meets the main porch at the same 28 cm height; only the outward
  // reach is narrower, matching the west eave platform above it.
  const westStepFrontZ=westGroundCorridor.centerZ+westGroundCorridor.depth/2;
  mesh(wing,g(createPorchGeometry(westGroundCorridor.width,westGroundCorridor.depth,westGroundCorridor.height,1978)),porchConcrete,[westGroundCorridor.centerX,0,westGroundCorridor.centerZ]);
  ageSurface(wing,westGroundCorridor.width,westGroundCorridor.depth,[westGroundCorridor.centerX,westGroundCorridor.height+.003,westStepFrontZ],{rotationX:-Math.PI/2,seed:3316,strength:.8});
  mesh(wing,g(createRiserGeometry(westGroundCorridor.width,Math.round(westGroundCorridor.width/.13),1978)),mat('#ffffff',{vertexColors:true,roughness:1}),[westGroundCorridor.centerX,0,westStepFrontZ-.065]);
  mesh(wing,g(createBrokenStepGeometry(westGroundCorridor.width,.32,.065,1977)),porchConcrete,[westGroundCorridor.centerX,0,westStepFrontZ+.145]);
  for(const opening of westFacade.doors){
    const x=opening.z-westWing.position[2],w=opening.width,leafW=w/2,leafH=westFacade.doorLeafHeight;
    const leafBaseY=.22,frameBottomY=.17,topY=westFacade.doorTopY,transomBottomY=leafBaseY+leafH+.05;
    const transomH=topY-transomBottomY,transomY=(topY+transomBottomY)/2,frameH=topY-frameBottomY;
    for(const side of [-1,1])exposedJamb(wing,x+side*(w/2+.09),.30,1.618,.11,.53,side);
    box(wing,dark,[x,(topY+frameBottomY)/2,1.614],[w+.13,frameH,.035]);
    // Recessed jambs and a worn threshold remain visible when the leaves open.
    for(const side of [-1,1])box(wing,wood,[x+side*(w/2-.02),leafBaseY+leafH/2,1.65],[.045,leafH,.10]);
    mesh(wing,g(createPorchGeometry(w+.1,.24,.045,Math.round(opening.z*111))),porchConcrete,[x,leafBaseY-.035,1.72]);
    for(const side of [-1,1]){
      const leaf=group(wing,[x+side*w/2,leafBaseY,1.67]);leaf.rotation.y=0;
      mesh(leaf,g(createWoodenDoorGeometry(leafW,leafH,.055,{seed:Math.round(opening.z*100)+side})),joinery,[-side*leafW/2,leafH/2,0]);
      for(const y of [.43,leafH-.35])box(leaf,metal,[-side*.035,y,.038],[.065,.11,.016]);
      const handleX=-side*(leafW-.095);
      box(leaf,metal,[handleX,1.05,.045],[.026,.13,.022]);
    }
    for(const side of [-1,1])timber(wing,[x+side*(w/2+.04),(topY+frameBottomY)/2,1.72],[.075,frameH+.01,.13]);
    // Glazed light above the smaller paired leaves, within the same door frame.
    box(wing,dark,[x,transomY,1.65],[w,transomH,.025]);
    insetCurtain(wing,[x,transomY,1.67],w-.08,transomH-.07,opening.z);
    for(const side of [-1,1])glassPane(wing,[x+side*w/4,transomY,1.683],w/2-.065,transomH-.07,opening.z+side);
    timber(wing,[x,transomBottomY,1.73],[w+.1,.065,.12],'x');
    timber(wing,[x,topY,1.73],[w+.1,.075,.12],'x');
    timber(wing,[x,transomY,1.73],[.045,transomH,.1]);
    timber(wing,[x,transomY+.02,1.73],[w,.03,.075],'x');
    for(let i=1;i<6;i++)rod(wing,[x-w/2+i*w/6,transomBottomY+.035,1.81],[x-w/2+i*w/6,topY-.035,1.81],.01,metal);
    mesh(wing,g(new T.PlaneGeometry(.4,.10)),entryPlaque,[x,transomBottomY,1.802]).castShadow=false;
    for(const side of [-1,1])mesh(wing,g(createPaperGeometry(.105,Math.min(2.15,leafH),Math.round(opening.z*100)+side)),couplet,[x+side*(w/2+.095),leafBaseY+leafH/2,1.802]).castShadow=false;
  }
  for(const opening of westFacade.windows){
    const x=opening.z-westWing.position[2],w=opening.width,h=opening.height,y=westFacade.windowCenterY;
    exposedJamb(wing,x-w/2-.08,y-h/2-.1,1.618,.10,.39,-1);
    box(wing,dark,[x,y,1.65],[w,h,.06]);
    for(let row=0;row<opening.rows;row++)for(let col=0;col<opening.columns;col++){
      glassPane(wing,[x-w/2+(col+.5)*w/opening.columns,y-h/2+(row+.5)*h/opening.rows,1.695],w/opening.columns-.055,h/opening.rows-.06,row*opening.columns+col);
    }
    for(let col=0;col<=opening.columns;col++)timber(wing,[x-w/2+col*w/opening.columns,y,1.73],[(col===0||col===opening.columns) ? .075 : .047,h+.1,.09]);
    for(let row=0;row<=opening.rows;row++)timber(wing,[x,y-h/2+row*h/opening.rows,1.73],[w+.09,(row===0||row===opening.rows) ? .075 : .047,.09],'x');
    const barCount=Math.max(5,Math.round(w/.18));
    for(let i=0;i<=barCount;i++)rod(wing,[x-w/2+i*w/barCount,y-h/2+.04,1.80],[x-w/2+i*w/barCount,y+h/2-.04,1.80],.011,metal);
    mesh(wing,g(createPorchGeometry(w+.15,.25,.1,Math.round(opening.z*100))),porchConcrete,[x,y-h/2-.09,1.72]);
  }
  // The brick boundary meets the wing at its front corner, with both outside faces flush.
  const westBoundaryLength=westBoundary.endZ-westBoundary.startZ;
  const eastBoundaryLength=eastWall.endZ-eastWall.startZ;
  masonry(scene,[eastWall.centerX,sideWallHeight/2,(eastWall.startZ+eastWall.endZ)/2],[eastWall.thickness,sideWallHeight,eastBoundaryLength],1001);
  masonry(scene,[shedSideBrick.x,(shedSideBrick.bottomY+shedSideBrick.topY)/2,(shedSideBrick.startZ+shedSideBrick.endZ)/2],[shedSideBrick.thickness,shedSideBrick.topY-shedSideBrick.bottomY,shedSideBrick.endZ-shedSideBrick.startZ],1011);
  masonry(scene,[westWallX,sideWallHeight/2,(westBoundary.startZ+westBoundary.endZ)/2],[westBoundary.thickness,sideWallHeight,westBoundaryLength],1002);
  ageSurface(scene,eastBoundaryLength,.82,[eastWall.centerX+eastWall.thickness/2+.008,0,(eastWall.startZ+eastWall.endZ)/2],{rotationY:Math.PI/2,seed:10101,strength:.8});
  ageSurface(scene,westBoundaryLength,.85,[westWallX-westBoundary.thickness/2-.008,0,(westBoundary.startZ+westBoundary.endZ)/2],{rotationY:-Math.PI/2,seed:10102,strength:.9});
  // Uneven exposed coping breaks the perfectly straight silhouette of the wall.
  const eastCapCount=Math.ceil((eastBoundaryLength-.3)/.254)+1,westCapCount=Math.ceil((westBoundaryLength-.3)/.254)+1;
  const capGeo=g(new RoundedBoxGeometry(.35,.09,.25,1,.008)),caps=new T.InstancedMesh(capGeo,mat('#ffffff',{roughness:1}),eastCapCount+westCapCount),capPose=new T.Object3D(),capColor=new T.Color();
  for(let i=0;i<eastCapCount+westCapCount;i++){
    const west=i>=eastCapCount,index=west?i-eastCapCount:i,side=west?westWallX:eastWall.centerX;
    const z=west?westBoundary.startZ+.15+index*(westBoundaryLength-.3)/(westCapCount-1):eastWall.startZ+.15+index*(eastBoundaryLength-.3)/(eastCapCount-1);
    capPose.position.set(side,sideWallHeight+.015+rand()*.02,z);capPose.rotation.set((rand()-.5)*.05,(rand()-.5)*.025,(rand()-.5)*.04);capPose.updateMatrix();caps.setMatrixAt(i,capPose.matrix);capColor.setHSL(.085,.12,.43+rand()*.06);caps.setColorAt(i,capColor)
  }caps.castShadow=caps.receiveShadow=true;scene.add(caps);
  const gateEastOuterX=gateEastPillarX-gatePortico.pillarWidth/2,gateWestOuterX=gateWestPillarX+gatePortico.pillarWidth/2;
  masonry(scene,[(-6.3+gateEastOuterX)/2,frontWallHeight/2,gateZ],[gateEastOuterX+6.3,frontWallHeight,.35],1003);masonry(scene,[(gateWestOuterX+westWallX)/2,frontWallHeight/2,gateZ],[westWallX-gateWestOuterX,frontWallHeight,.35],1004)
  // The front boundary in the courtyard reference has a thin cement cap,
  // continuous above the brickwork but stopping at the taller gate pillars.
  for(const [left,right,seed] of [[-6.3,gateEastOuterX,1041],[gateWestOuterX,westWallX,1042]]){
    const width=right-left,center=(left+right)/2;
    mesh(scene,g(createPorchGeometry(width,.39,.055,seed,.006)),porchConcrete,[center,frontWallHeight,gateZ]);
    ageSurface(scene,width,.5,[center,0,gateZ+.181],{seed,strength:.6});
  }
  const gate=group(scene,[gateX,0,gateZ])
  const gateHalf=gatePortico.halfPillarSpacing,gateLeafW=gatePortico.leafWidth;
  masonry(gate,[-gateHalf,1.95,gatePortico.centerLocalZ],[gatePortico.pillarWidth,3.9,gatePortico.depth],1005);masonry(gate,[gateHalf,1.95,gatePortico.centerLocalZ],[gatePortico.pillarWidth,3.9,gatePortico.depth],1006);
  const lintelConcrete=M.mapped(M.maps.ground,[1,1],{vertexColors:true,roughness:1,flatShading:true});
  mesh(gate,g(createPorchGeometry(gatePortico.outerWidth,gatePortico.depth,gatePortico.roofThickness,1922)),lintelConcrete,[0,gatePortico.roofBaseY,gatePortico.centerLocalZ]);
  // A continuous 40 cm wide, 20 cm high concrete rim follows all four roof edges.
  const rimConcrete=M.mapped(M.maps.ground,[1,1],{roughness:1});
  const rimY=gatePortico.roofBaseY+gatePortico.roofThickness+gatePortico.rimHeight/2;
  for(const side of [-1,1]){
    box(gate,rimConcrete,[0,rimY,gatePortico.centerLocalZ+side*(gatePortico.depth-gatePortico.rimWidth)/2],[gatePortico.outerWidth,gatePortico.rimHeight,gatePortico.rimWidth]);
    box(gate,rimConcrete,[side*(gatePortico.outerWidth-gatePortico.rimWidth)/2,rimY,gatePortico.centerLocalZ],[gatePortico.rimWidth,gatePortico.rimHeight,gatePortico.depth-2*gatePortico.rimWidth]);
  }
  const gateInside=M.mapped(M.maps.plaster,[.5,1]);for(const side of [-1,1])box(gate,gateInside,[side*(gateHalf-.24),1.72,gatePortico.centerLocalZ],[.04,3.42,gatePortico.depth-.1]);
  for(const side of [-1,1]){
    ageSurface(gate,gatePortico.depth-.12,3.38,[side*(gateHalf-.265),0,gatePortico.centerLocalZ],{rotationY:-side*Math.PI/2,seed:11110+side,strength:.9});
    ageSurface(gate,gatePortico.pillarWidth,3.88,[side*gateHalf,0,gatePortico.backZ-gateZ+.007],{seed:11120+side,strength:.75});
  }
  ageSurface(gate,gatePortico.outerWidth,gatePortico.roofThickness,[0,gatePortico.roofBaseY,gatePortico.backZ-gateZ+.008],{seed:11130,strength:.75});
  // The broad outer portal leads to a smaller, one-metre-deep inner doorway.
  const innerHalf=gatePortico.innerClearWidth/2,innerZ=gatePortico.leafLocalZ,innerTop=gatePortico.innerTopY;
  for(const side of [-1,1])box(gate,gateInside,[side*(innerHalf+gatePortico.recessSideInset/2),innerTop/2,innerZ+.16],[gatePortico.recessSideInset,innerTop,.32]);
  box(gate,gateInside,[0,innerTop+gatePortico.recessTopInset/2,innerZ+.16],[gatePortico.clearWidth,gatePortico.recessTopInset,.32]);
  const gateEnamel=M.mapped(M.maps.gatePaint,[1,1],{metalness:.12,vertexColors:true});
  const gateRail=M.mapped(M.maps.gatePaint,[1,1],{metalness:.18,color:'#b9b1a5'});
  const gateLeafH=innerTop-.08;
  for(const side of [-1,1]){
    const hingeX=side*innerHalf,leaf=group(gate,[hingeX,-.033,innerZ]);leaf.rotation.y=side===-1?.1:-.04;
    mesh(leaf,g(createIronGatePanel(gateLeafW,gateLeafH)),gateEnamel,[-side*gateLeafW/2,.035,0]);
    for(const faceZ of [-.045,.045]){
      for(const x of [-side*.018,-side*(gateLeafW-.018)])box(leaf,gateRail,[x,(gateLeafH+.07)/2,faceZ],[.036,gateLeafH,.025]);
      for(const y of [.055,.48,1.28,2.08,gateLeafH+.035])box(leaf,gateRail,[-side*gateLeafW/2,y,faceZ],[gateLeafW,.033,.025]);
    }
    for(const y of [.45,1.36,2.25]){
      box(leaf,gateRail,[-side*.065,y,.05],[.13,.12,.018]);
      rod(gate,[hingeX,y-.09-.033,innerZ+.04],[hingeX,y+.09-.033,innerZ+.04],.025,metal);
    }
    const handleX=-side*(gateLeafW-.15);
    for(const z of [-.065,.065]){
      box(leaf,metal,[handleX,1.2,z],[.1,.16,.018]);
      const pull=mesh(leaf,g(new T.TorusGeometry(.042,.007,7,18)),metal,[handleX,1.11,z+Math.sign(z)*.018]);pull.rotation.x=.14;
    }
    if(side===1)for(const z of [-.052,.052]){
      const paper=mesh(leaf,g(createPaperGeometry(.39,.39,19)),fu,[-gateLeafW/2,1.68,z]);paper.rotation.y=z<0?Math.PI:0;paper.rotation.z=Math.PI/4;paper.castShadow=false;
    }
  }
  for(const side of [-1,1])box(gate,gateRail,[side*innerHalf,innerTop/2,innerZ-.055],[.05,innerTop,.07]);
  box(gate,gateRail,[0,innerTop,innerZ-.055],[gatePortico.innerClearWidth,.05,.07]);
  // The gate apron is flush with the yard: one continuous cement plane, no
  // raised lip. The slab is sunk so its top sits 1 mm above the courtyard
  // ground; only the top face remains visible. It keeps its own AO because
  // the courtyard map below cannot shade it through the solid slab.
  const apronBounds=[gateX-gateApron.width/2,gateX+gateApron.width/2,gateApron.frontZ,gateApron.backZ],apronZ=gateApron.centerZ;
  const apronAO=bakeGroundOcclusion(apronBounds,undefined,64,.083);extraTextures.add(apronAO);
  const apronGeo=g(setGroundOcclusionUV(createPorchGeometry(gateApron.width,gateApron.depth,.065,1914),apronBounds,[gateX,0,apronZ]));
  const gateFloorMaterial=M.mapped(M.maps.ground,[1,1],{vertexColors:true,aoMap:apronAO,aoMapIntensity:1.1});
  mesh(scene,apronGeo,gateFloorMaterial,[gateX,-.063,apronZ]);
  ageSurface(scene,gateApron.width,gateApron.depth,[gateX,.005,gateApron.backZ],{rotationX:-Math.PI/2,seed:11140,strength:.75});
  // Long wooden poles leaning beside the gate.
  for(let i=0;i<12;i++)rod(scene,[gateEastPillarX-1.0-rand()*.6,.05,gateZ+.8+rand()*.4],[gateEastPillarX-.35-rand()*.6,2.0+rand()*1.1,gateZ+.5],.018+rand()*.015,wood)
  rod(scene,[gateWestPillarX+.5,.1,gateZ+.8],[gateWestPillarX+.85,2.1,gateZ+.4],.024,wood);const shovel=box(scene,silver,[gateWestPillarX+.49,.28,gateZ+.84],[.38,.48,.045]);shovel.rotation.x=-.2
  function basin(p,r=.38){const o=mesh(scene,g(new T.CylinderGeometry(r,r*.74,.14,24,1,true)),silver,p);mesh(scene,g(new T.CircleGeometry(r*.75,24)),dark,[p[0],p[1]-.062,p[2]]).rotation.x=-Math.PI/2;return o}
  basin([gateWestPillarX+.7,.09,gateZ+1.2],.4);basin([gateWestPillarX+1.2,.09,gateZ+2],.26)
  // The low sheet stops at the corridor front. The rear wash area stays under
  // the high house platform; a glazed band fills the height between the two.
  const shed=group(scene,shedPlacement.position),shedRoof=M.maps.roofMetal?M.mapped(M.maps.roofMetal,[1,1],{metalness:.32,roughness:.92,side:T.DoubleSide}):mat('#45473e',{metalness:.32,roughness:.92,side:T.DoubleSide})
  const rustSteel=M.mapped(M.maps.gatePaint,[1,1],{color:'#704537',metalness:.18,roughness:.86});
  for(const [x,z] of shedPlacement.posts){const localZ=z-shedPlacement.position[2],top=z>shedRoofLayout.backZ?corridorCanopy.baseY:shedBeamY(localZ)-shedPlacement.beamThickness/2;rod(shed,[x-shedPlacement.position[0],0,localZ],[x-shedPlacement.position[0],top,localZ],.045,rustSteel)}
  const sheetCenterY=shedPlacement.roofY+shedRoofLayout.centerLocalZ*shedRoofLayout.pitch;
  mesh(shed,g(corrugatedSheetGeometry(shedRoofLayout.width,shedRoofLayout.depth,.15,shedRoofLayout.pitch)),shedRoof,[shedRoofLayout.centerLocalX,sheetCenterY,shedRoofLayout.centerLocalZ]);
  const sheetWaveX=x=>Math.cos((x-shedRoofLayout.centerLocalX+shedRoofLayout.width/2)/.15*Math.PI*2)*shedRoofLayout.corrugation;
  for(const x of [-.92,.58]){const seam=box(shed,metal,[x,sheetCenterY+sheetWaveX(x)+.008,shedRoofLayout.centerLocalZ],[.018,.014,shedRoofLayout.depth]);seam.rotation.x=-Math.atan(shedRoofLayout.pitch)}
  const fasteners=new T.InstancedMesh(g(new T.SphereGeometry(.014,6,4)),metal,39),fastenerPose=new T.Object3D();
  for(let i=0;i<39;i++){const x=-1.8+(i%13)*.30,z=[-3,1.3,shedRoofLayout.backZ-shedPlacement.position[2]-.06][Math.floor(i/13)];fastenerPose.position.set(x,shedPlacement.roofY+sheetWaveX(x)+z*shedRoofLayout.pitch+.012,z);fastenerPose.scale.set(1,.35,1);fastenerPose.updateMatrix();fasteners.setMatrixAt(i,fastenerPose.matrix)}shed.add(fasteners);
  for(const z of [-3,1.3,shedRoofLayout.backZ-shedPlacement.position[2]-.06])box(shed,wood,[shedRoofLayout.centerLocalX,shedBeamY(z),z],[shedRoofLayout.width,shedPlacement.beamThickness,.07]);
  const glassZ=shedClerestory.frameZ-shedPlacement.position[2];
  const glassBottom=shedClerestory.bottomY+.045,glassTop=shedClerestory.topY-.045;
  const glassHeight=glassTop-glassBottom,glassCenterY=(glassTop+glassBottom)/2;
  const frameMetal=mat('#c3c1bc',{metalness:.3,roughness:.56});
  const frostPattern=createFrostedPatternTexture();extraTextures.add(frostPattern);
  const upperGlass=mat('#ffffff',{map:frostPattern,metalness:.03,roughness:.75,transparent:true,opacity:.94,depthWrite:false,side:T.DoubleSide});
  box(shed,frameMetal,[shedClerestory.centerLocalX,glassBottom,glassZ],[shedClerestory.width,.065,.065]);
  box(shed,frameMetal,[shedClerestory.centerLocalX,glassTop,glassZ],[shedClerestory.width,.065,.065]);
  box(shed,frameMetal,[shedClerestory.centerLocalX,shedPlacement.roofY-.12,glassZ],[shedClerestory.width,.05,.055]);
  const glassCount=5,glassCellW=shedClerestory.width/glassCount;
  for(let i=0;i<=glassCount;i++){
    const x=shedClerestory.centerLocalX-shedClerestory.width/2+i*glassCellW;
    box(shed,frameMetal,[x,glassCenterY,glassZ],[.055,glassHeight,.065]);
    if(i<glassCount){const pane=face(shed,upperGlass,[x+glassCellW/2,glassCenterY,glassZ-.036],[glassCellW-.065,glassHeight-.07],Math.PI);pane.castShadow=false;pane.receiveShadow=false}
  }
  const stepWallCement=M.mapped(M.maps.plaster,[1,1],{color:'#a5a69f',roughness:1,bumpScale:.009});
  const washWallWidth=shedStepWall.maxX-shedStepWall.minX,wallMidX=(shedStepWall.minX+shedStepWall.maxX)/2;
  const wallBodyTop=shedStepWall.topY-shedStepWall.capThickness;
  masonry(scene,[wallMidX,(shedStepWall.bottomY+wallBodyTop)/2,shedStepWall.z],[washWallWidth,wallBodyTop-shedStepWall.bottomY,shedStepWall.thickness],20150219);
  // Solid plastered end, visibly as deep as the wall; exposed brick continues
  // along the lower long face. The cap is the wall top, not a projecting shelf.
  box(scene,stepWallCement,[shedStepWall.maxX+.006,wallBodyTop/2,shedStepWall.z],[.028,wallBodyTop,shedStepWall.thickness+.008]);
  for(const side of [-1,1]){const skin=mesh(scene,g(createPeelingWallSkin(washWallWidth)),stepWallCement,[wallMidX,wallBodyTop,shedStepWall.z+side*(shedStepWall.thickness/2-.005)]);if(side===-1)skin.rotation.y=Math.PI;}
  mesh(scene,g(createPorchGeometry(washWallWidth+.025,shedStepWall.thickness+.025,shedStepWall.capThickness,20150221)),M.mapped(M.maps.plaster,[1,1],{color:'#a5a299',vertexColors:true,roughness:1}),[wallMidX,wallBodyTop,shedStepWall.z]);
  box(scene,concrete,[shedPlacement.position[0],.13,6.6],[shedPlacement.width,.26,2]);
  // Hollow washbasin with rim and wall tap; corridor remains open beside it.
  const sink=group(scene,shedPlacement.sink),ceramic=mat('#c9cdc1');
  box(sink,concrete,[0,.42,0],[.66,.84,1.08]);
  box(sink,dark,[0,.88,0],[.65,.035,.91]);
  for(const x of [-.35,.35])box(sink,ceramic,[x,.98,0],[.1,.24,1.2]);
  for(const z of [-.55,.55])box(sink,ceramic,[0,.98,z],[.8,.24,.1]);
  rod(sink,[-.39,1.1,0],[-.39,1.4,0],.025,silver);rod(sink,[-.39,1.4,0],[-.12,1.4,0],.025,silver);rod(sink,[-.12,1.4,0],[-.12,1.32,0],.025,silver);
  // Photo reference: the wash passage has an aluminium-framed translucent
  // screen, a rough brick-and-cement lower face and everyday items on its ledge.
  const passageX=shedStepWall.maxX+.025,passageFront=shedStepWall.z+shedStepWall.thickness/2,passageBack=7.7;
  const passageWidth=passageBack-passageFront,passageMid=(passageBack+passageFront)/2;
  const passage=group(scene,[passageX,0,passageMid]);passage.rotation.y=Math.PI/2;
  const frostedGlass=mat('#ffffff',{map:frostPattern,roughness:.78,metalness:0,transparent:true,opacity:.83,side:T.DoubleSide});
  // Passage photo: pale neutral-grey ribbed infill, not weathered roof sheet.
  const lowerPanel=mat('#ffffff',{map:plainAlbedo,metalness:.08,roughness:.74});
  const screenBottom=frontPorch.height,screenTop=corridorCanopy.baseY-.08,screenSplit=1.12;
  box(passage,lowerPanel,[0,(screenBottom+screenSplit)/2,0],[passageWidth,screenSplit-screenBottom,.035]);
  const panelRibs=g(new T.PlaneGeometry(passageWidth-.05,screenSplit-screenBottom-.05,Math.ceil(passageWidth/.024)*8,1));
  const ribPoints=panelRibs.attributes.position;
  for(let i=0;i<ribPoints.count;i++)ribPoints.setZ(i,.0025*Math.cos((ribPoints.getX(i)+passageWidth/2)/.024*Math.PI*2));
  panelRibs.computeVertexNormals();
  for(const side of [-1,1]){
    const face=mesh(passage,panelRibs,lowerPanel,[0,(screenBottom+screenSplit)/2,side*.0205]);
    face.rotation.y=side<0?Math.PI:0;
  }
  for(let col=0;col<2;col++)for(let row=0;row<3;row++){
    const cellH=(screenTop-screenSplit)/3,x=-passageWidth/2+(col+.5)*passageWidth/2,y=screenSplit+(row+.5)*cellH;
    const pane=mesh(passage,g(new T.PlaneGeometry(passageWidth/2-.05,cellH-.05)),frostedGlass,[x,y,0]);pane.castShadow=false;
  }
  for(const x of [-passageWidth/2,0,passageWidth/2])box(passage,frameMetal,[x,(screenTop+screenBottom)/2,.022],[.045,screenTop-screenBottom,.055]);
  for(const y of [screenBottom,screenSplit,screenSplit+(screenTop-screenSplit)/3,screenSplit+2*(screenTop-screenSplit)/3,screenTop])box(passage,frameMetal,[0,y,.022],[passageWidth+.04,.045,.055]);
  box(passage,silver,[.065,1.30,.07],[.025,.19,.025]);
  const washFront=shedStepWall.z-shedStepWall.thickness/2;
  const brickW=washWallWidth-.08;
  const cementLoss=mesh(scene,g(createPlasterWearGeometry(brickW,{seed:20150220,height:.5})),flakingPlaster,[wallMidX,.66,washFront-.02]);cementLoss.rotation.y=Math.PI;cementLoss.castShadow=false;
  const endWear=mesh(scene,g(createPlasterWearGeometry(shedStepWall.thickness,{seed:20150222,height:.7})),flakingPlaster,[shedStepWall.maxX+.022,.18,shedStepWall.z]);endWear.rotation.y=Math.PI/2;endWear.castShadow=false;
  const ledgeY=shedStepWall.topY,ledgeZ=shedStepWall.z-.07;
  const bottleBlue=mat('#287aa2',{roughness:.32,transparent:true,opacity:.84});
  const bottle=group(scene,[shedStepWall.maxX-.42,ledgeY,ledgeZ]);
  mesh(bottle,g(new T.CylinderGeometry(.073,.08,.29,16)),bottleBlue,[0,.145,0]);
  mesh(bottle,g(new T.CylinderGeometry(.027,.073,.055,16)),bottleBlue,[0,.318,0]);
  mesh(bottle,g(new T.CylinderGeometry(.03,.03,.035,12)),mat('#236087'),[0,.363,0]);
  for(let i=0;i<5;i++)mesh(bottle,g(new T.TorusGeometry(.077,.004,5,16)),bottleBlue,[0,.055+i*.045,0]).rotation.x=Math.PI/2;
  const yellowPlastic=mat('#be962b',{roughness:.7});
  for(const [x,h] of [[shedStepWall.maxX-1.05,.29],[shedStepWall.maxX-1.38,.35]]){
    mesh(scene,g(new RoundedBoxGeometry(.18,h,.13,2,.024)),yellowPlastic,[x,ledgeY+h/2,ledgeZ]);
    mesh(scene,g(new T.CylinderGeometry(.023,.023,.03,10)),yellowPlastic,[x-.045,ledgeY+h+.015,ledgeZ]);
  }
  const redCord=mat('#a44b59',{roughness:1});
  const tie=group(scene,[-2.55,1.66,5.6]);
  mesh(tie,g(new T.TorusGeometry(.051,.009,6,20)),redCord).rotation.x=Math.PI/2;
  for(const side of [-1,1]){
    const points=[[0,0,0],[side*.07,-.13,-.025],[side*.04,-.44,.025],[side*.10,-.70,0]].map(v=>new T.Vector3(...v));
    mesh(tie,g(new T.TubeGeometry(new T.CatmullRomCurve3(points),16,.008,5,false)),redCord);
  }
  const wirePoints=[[-1.8,3.35,7.52],[-2.3,3.13,7.36],[-2.8,3.42,7.15],[-3.55,3.30,6.3]].map(v=>new T.Vector3(...v));
  mesh(scene,g(new T.TubeGeometry(new T.CatmullRomCurve3(wirePoints),30,.005,5,false)),mat('#b8b09a'));
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
  for(const x of [-.82,.82]){hangingTarp([x,1.68,.42],1.24,1.57,Math.sign(x)*Math.PI/2);for(const z of [-.87,.85])rod(trike,[x,.93,z],[x,2.48,z],.021,silver)}
  hangingTarp([0,1.71,1.04],1.67,1.57,0)
  const roofGeometry=g(new T.PlaneGeometry(1.72,2.04,32,8)),rp=roofGeometry.attributes.position
  for(let i=0;i<rp.count;i++){const x=rp.getX(i),z=-rp.getY(i);rp.setXYZ(i,x,Math.sqrt(Math.max(0,1-(x/.86)**2))*.21,z)}roofGeometry.computeVertexNormals()
  mesh(trike,roofGeometry,tarp,[0,2.45,.1])
  const canopyEnd=new T.Shape();canopyEnd.moveTo(-.86,0);for(let i=0;i<=24;i++){const x=-.86+i*1.72/24;canopyEnd.lineTo(x,Math.sqrt(Math.max(0,1-(x/.86)**2))*.21)}canopyEnd.lineTo(.86,0);canopyEnd.closePath();
  mesh(trike,g(new T.ShapeGeometry(canopyEnd)),tarp,[0,2.45,1.12]);
  // Clear, flexible wind curtain with teal sewn edging; the forward side
  // opening stays open, matching the canopy entrance in the courtyard photos.
  const windCurtain=group(trike,[0,1.78,-1.02]);windCurtain.rotation.x=.07;
  const clearVinyl=mat('#dce2d8',{transparent:true,opacity:.24,roughness:.2,metalness:0,envMapIntensity:1.1,side:T.DoubleSide,depthWrite:false});
  const vinyl=mesh(windCurtain,g(createClearCurtainGeometry()),clearVinyl);vinyl.castShadow=false;
  for(const side of [-1,1])box(windCurtain,tarp,[side*.605,0,.003],[.09,1.43,.022]);
  for(const y of [-.695,.695])box(windCurtain,tarp,[0,y,.003],[1.30,.09,.025]);
  const foldHighlight=mat('#e1e1d5',{transparent:true,opacity:.19,roughness:.45,depthWrite:false});
  for(let i=0;i<8;i++){
    const x=-.47+i*.13,points=[];
    for(let j=0;j<9;j++){const y=-.62+j*.155;points.push(new T.Vector3(x+Math.sin(i*3+j*1.4)*.011,y,-.015+Math.sin(j+i)*.009))}
    const crease=mesh(windCurtain,g(new T.TubeGeometry(new T.CatmullRomCurve3(points),18,.0018,3,false)),foldHighlight);crease.castShadow=false;
  }
  const rolledEdge=mesh(trike,g(new T.CylinderGeometry(.055,.055,1.28,12)),tarp,[0,2.49,-.94]);rolledEdge.rotation.z=Math.PI/2;
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
  const luggage=mesh(trike,g(new RoundedBoxGeometry(.52,.19,.46,3,.075)),mat('#245c78',{roughness:1}),[.31,1.04,.48]);luggage.rotation.y=.24;
  // Small local clutter visible beside the post, kept outside the walking route.
  const looseBottle=group(scene,[-2.77,.105,5.72]);looseBottle.rotation.z=Math.PI/2;looseBottle.rotation.x=.17;
  mesh(looseBottle,g(new T.CylinderGeometry(.055,.06,.26,14)),bottleBlue);
  mesh(looseBottle,g(new T.CylinderGeometry(.025,.05,.055,12)),bottleBlue,[0,.155,0]);
  mesh(looseBottle,g(new T.CylinderGeometry(.027,.027,.03,12)),mat('#295d7d'),[0,.194,0]);
  const coilPoints=[];for(let i=0;i<=100;i++){const a=i/100*Math.PI*8,r=.14+.045*Math.sin(i*.13);coilPoints.push(new T.Vector3(-2.69+Math.cos(a)*r,.015+i*.00035,5.48+Math.sin(a)*r*.65))}
  mesh(scene,g(new T.TubeGeometry(new T.CatmullRomCurve3(coilPoints),110,.006,4,false)),redCord);
  // Continuous curved limbs, embedded forks and a flared bole; one mesh per
  // tree. The smaller courtyard tree stands at the owner's marked spot.
  const barkMaterial=M.mapped(M.maps.bark,[1,1],{color:'#a4a098',bumpScale:.012});
  function tree(x,z,size=1,treeSeed=1979,detail='near'){
    return mesh(scene,g(createWinterTreeGeometry(treeSeed,{detail})),barkMaterial,[x,0,z],[size,size,size]);
  }
  for(const {x,z,size,seed:treeSeed} of courtyardTrees)tree(x,z,size,treeSeed)
  // The photos have scattered distant trunks, not a dense grove screening the gate.
  for(let i=0;i<10;i++){
    const x=-19+rand()*38,z=-14-rand()*12;
    if(Math.abs(x-gateX)>3.5)tree(x,z,.6+rand()*.5,41321+i,'far');
  }
  // A distant tiled roof and low, hazy fields outside the gate.
  const neighbor=group(scene,[-9,0,-14]);box(neighbor,brick,[0,1.7,0],[5,3.4,4]);for(const sign of [-1,1]){const o=box(neighbor,mat('#75685d'),[sign*1.3,3.8,0],[2.9,.12,4.8]);o.rotation.z=-sign*.36}
  // Ground detail uses instancing, not hundreds of separate meshes.
  const stoneGeo=g(new T.DodecahedronGeometry(.032)),stoneMat=mat('#8c887a'),stones=new T.InstancedMesh(stoneGeo,stoneMat,95),dummy=new T.Object3D()
  for(let i=0;i<95;i++){
    const x=i<58?-6+rand()*.55:-5.4+rand()*11.1;
    dummy.position.set(x,.006,-6.9+rand()*14.2);dummy.rotation.set(0,rand()*6,0);
    dummy.scale.set(.45+rand()*1.1,.12+rand()*.22,.45+rand());dummy.updateMatrix();stones.setMatrixAt(i,dummy.matrix);
  }scene.add(stones);stones.receiveShadow=true
  const crackMat=mat('#625f54',{vertexColors:true,transparent:true,opacity:.65,depthWrite:false});
  const cracks=mesh(scene,g(createGroundCracks()),crackMat);cracks.castShadow=false;
  const footWear=mesh(scene,g(createWallFootWear()),mat('#686957',{vertexColors:true,transparent:true,depthWrite:false}));footWear.castShadow=false;
  const bird=group(scene,[-6.2,2.35,-2.75]);ball(bird,mat('#66584b'),[0,.12,0],[.09,.14,.08]);ball(bird,mat('#8d765a'),[0,.25,-.025],[.065,.06,.065]);rod(bird,[0,.1,.04],[0,-.1,.2],.028,mat('#986d3e'));rod(bird,[.025,0,0],[.025,.07,0],.007,dark)
  // Small aged nail heads on long door/window frame members, batched globally.
  scene.updateMatrixWorld(true);
  const nailMesh=new T.InstancedMesh(g(new T.SphereGeometry(.005,6,4)),mat('#64594b',{metalness:.35,roughness:.8}),frameNails.length),nailPose=new T.Object3D();
  for(let i=0;i<frameNails.length;i++){
    const {parent,position}=frameNails[i];nailPose.position.set(...position);nailPose.rotation.set(0,0,0);nailPose.scale.set(1,1,.4);nailPose.updateMatrix();
    nailMesh.setMatrixAt(i,new T.Matrix4().multiplyMatrices(parent.matrixWorld,nailPose.matrix));
  }
  nailMesh.receiveShadow=true;scene.add(nailMesh);
  // Generic generated plaster is a bundled local asset, not an online request.
  // The private reference photos were not sent to the image generator.
  try {
    M.useAlbedo(M.maps.plaster,await new T.TextureLoader().loadAsync('/home-materials/limewash-albedo-v1.png'));
  }catch { report({warning:'石灰墙材质未加载，已使用本地程序纹理。'}) }
  try {
    M.useAlbedo(M.maps.gatePaint,await new T.TextureLoader().loadAsync('/home-materials/iron-gate-albedo-v1.png'));
  }catch { report({warning:'旧漆铁门材质未加载，已使用本地程序纹理。'}) }
  try {
    M.useAlbedo(M.maps.wood,await new T.TextureLoader().loadAsync('/home-materials/timber-albedo-v1.png'));
  }catch { report({warning:'旧木材质未加载，已使用本地程序纹理。'}) }
  // A bounded patch of the local photo retains the actual window appearance;
  // it contains no people and no guessed indoor imagery.
  try {
    const photo=await new T.TextureLoader().loadAsync('/home-photos/01-house.jpg');extraTextures.add(photo);photo.colorSpace=T.SRGBColorSpace
    // Restrict the sample to curtain/glass, excluding the photographed blue
    // garment. The garment and mullions already exist as separate geometry.
    const patch=photo.clone();patch.repeat.set(152/1440,280/1080);patch.offset.set(441/1440,1-310/1080);patch.anisotropy=Math.min(8,renderer.capabilities.getMaxAnisotropy());extraTextures.add(patch)
    const pm=new T.MeshStandardMaterial({map:patch,roughness:1});extraMats.add(pm);windowCurtains[0].material=pm;
  }catch { report({warning:'原照片纹理未加载，已使用几何窗框。'}) }
  const photoLayers=[],photoSwaps=[];
  const photoTextures={};
  await Promise.all(Object.entries(photoSources).map(async([key,source])=>{
    try{
      const texture=await new T.TextureLoader().loadAsync(source.url);texture.colorSpace=T.SRGBColorSpace;
      texture.anisotropy=Math.min(8,renderer.capabilities.getMaxAnisotropy());extraTextures.add(texture);photoTextures[key]=texture;
    }catch{report({warning:'部分照片纹理未加载，对应部位使用原模型材质。'})}
  }));
  // Transfer the neural page's surviving photo-textured triangles onto the
  // calibrated facade. The existing wall stays behind all unreconstructed holes.
  try{
    const response=await fetch('/neural-results/model-facade.json');
    if(!response.ok)throw new Error('Missing neural facade surface');
    const data=await response.json();
    if(!photoTextures.main)throw new Error('Missing facade photograph');
    const geometry=g(new T.BufferGeometry());
    geometry.setAttribute('position',new T.Float32BufferAttribute(data.positions,3));
    geometry.setAttribute('uv',new T.Float32BufferAttribute(data.uv,2));geometry.setIndex(data.indices);
    geometry.setAttribute('surfaceAlpha',new T.Float32BufferAttribute(data.alpha,1));
    const material=new T.MeshBasicMaterial({map:photoTextures.main,side:T.DoubleSide,transparent:true,depthWrite:false});
    material.onBeforeCompile=shader=>{
      shader.vertexShader='attribute float surfaceAlpha; varying float vSurfaceAlpha;\n'+shader.vertexShader;
      shader.vertexShader=shader.vertexShader.replace('#include <begin_vertex>','#include <begin_vertex>\nvSurfaceAlpha=surfaceAlpha;');
      shader.fragmentShader='varying float vSurfaceAlpha;\n'+shader.fragmentShader;
      shader.fragmentShader=shader.fragmentShader.replace('#include <color_fragment>','#include <color_fragment>\ndiffuseColor.a*=vSurfaceAlpha;');
    };
    material.customProgramCacheKey=()=> 'neural-facade-feather-v1';extraMats.add(material);
    const surface=mesh(house,geometry,material);surface.name='neural-photo-facade';
    surface.castShadow=false;surface.receiveShadow=false;photoLayers.push(surface);
  }catch(error){console.warn('Neural facade transfer unavailable',error);}
  const photoMaterial=(region,repeatUv=false)=>{
    const spec=photoRegions[region],texture=photoTextures[spec.source];if(!texture)return null;
    const material=createPhotoProjectionMaterial(texture,spec.corners,photoSources[spec.source].size,{feather:spec.feather,repeatUv,gain:region.startsWith('entry')?.42:1});extraMats.add(material);return material;
  };
  function projectPhoto(parent,region,width,height,position){
    const material=photoMaterial(region);if(!material)return;
    const layer=mesh(parent,g(new T.PlaneGeometry(width,height)),material,position);layer.name=`photo-projection:${region}`;layer.castShadow=false;layer.receiveShadow=false;photoLayers.push(layer);
  }
  // Full west window is a planar photo surface: the 3D frame remains behind
  // it for silhouette/depth. Source corners exclude the people to its right.
  const photoWindow=westFacade.windows[0],photoWindowX=photoWindow.z-westWing.position[2];
  projectPhoto(wing,'westWindow',photoWindow.width,photoWindow.height,[photoWindowX,westFacade.windowCenterY,1.826]);
  // Sample unobscured glass/curtain between the reference's iron bars. The
  // model supplies all mullions and bars, avoiding duplicated photographic
  // frames and leaving actual depth at oblique viewing angles.
  for(let i=0;i<5;i++){
    const x=doorX-transomW/2+(i+.5)*transomCellW;
    projectPhoto(house,'entryUpperGlass',transomCellW-.065,.24,[x,3.565,.239]);
    projectPhoto(house,'entryLowerGlass',transomCellW-.065,.29,[x,3.245,.239]);
  }
  for(const side of [-1,1]){
    const x=doorX+side*houseEntry.sideWindowOffset,w=houseEntry.sideWindowWidth;
    const bottom=houseWindowCenterY-houseWindowOpeningHeight/2+.04,h=3-bottom,mid=(bottom+3)/2;
    for(const y of [(bottom+mid)/2,(mid+3)/2])projectPhoto(house,'entryLowerGlass',w-.065,h/2-.06,[x,y,.240]);
  }
  for(const win of houseWindows){
    const inner=houseWindowOpeningHeight/2-.04,divider=houseWindowRailY-houseWindowCenterY;
    for(let col=0;col<3;col++){
      const x=win.x-win.width/2+(col+.5)*win.width/3;
      projectPhoto(house,'entryLowerGlass',win.width/3-.065,inner+divider-.08,[x,houseWindowCenterY+(-inner+divider)/2,0]);
      projectPhoto(house,'entryUpperGlass',win.width/3-.065,inner-divider-.08,[x,houseWindowCenterY+(divider+inner)/2,0]);
    }
  }
  // Continuous lit photo sampling below replaces the old rectangular wall
  // overlays, whose baked brightness left visible patch boundaries.
  const reusedSurfaces=[],reusedMaterials=new Set();
  scene.traverse(object=>{
    if(!object.isMesh)return;
    for(const material of Array.isArray(object.material)?object.material:[object.material]){
      if(reusedMaterials.has(material)||!material.map||!material.isMeshStandardMaterial)continue;
      const source=material.userData.source;
      const region=material===doorLeafMaterial?'doorLeafWood':material===washerPlastic?'washerPlastic':material===lowerPanel?'passagePanel':material===yardWearMaterial?'yardAggregate':material===yardSoil.material?'yardEarth':material===courtyardGround.material?'yardCement':(material===porchConcrete||material===gateFloorMaterial)?'porchCement':source===M.maps.plaster?'reusablePlaster':
        material===joinery||source===M.maps.wood?'reusableWood':
        source===M.maps.ground?'reusableConcrete':
        material===upperGlass||material===frostedGlass?'reusableFrost':
        material===clay?'reusableClay':null;
      if(!region)continue;
      const spec=photoRegions[region],texture=photoTextures[spec.source];if(!texture)continue;
      reusedMaterials.add(material);
      reusedSurfaces.push(attachPhotoSurface(material,texture,spec.corners,photoSources[spec.source].size,{...spec,blend:!['reusableWood','reusableFrost','doorLeafWood','washerPlastic','passagePanel'].includes(region)}));
    }
  });
  function setPhotoTextures(enabled){for(const layer of photoLayers)layer.visible=enabled;for(const swap of photoSwaps)swap.target.material=enabled?swap.projected:swap.original;for(const surface of reusedSurfaces)surface.setEnabled(enabled);}
  setPhotoTextures(true);report({photoReady:photoLayers.length+photoSwaps.length+reusedSurfaces.length});
  // Trained Gaussian detail shares the model's scene, depth buffer and house transform.
  // It is constrained to plaster; geometric openings and the courtyard remain solid.
  let hybridRenderer=null,hybridDetails=null;
  try{
    const {SparkRenderer,SplatMesh}=await import('@sparkjsdev/spark');
    hybridRenderer=new SparkRenderer({renderer});scene.add(hybridRenderer);
    hybridDetails=new SplatMesh({url:'/gaussian-results/main-facade-details.ply'});
    await hybridDetails.initialized;hybridDetails.name='trained-gaussian-facade';house.add(hybridDetails);
    report({hybridReady:true});
  }catch(error){console.warn('Gaussian facade detail unavailable',error);if(hybridDetails)house.remove(hybridDetails);hybridDetails?.dispose();hybridDetails=null;if(hybridRenderer)scene.remove(hybridRenderer);hybridRenderer?.dispose();hybridRenderer=null;report({warning:'高斯细节加载失败，暂时显示几何模型。'})}
  const orbit=new OrbitControls(camera,canvas);orbit.enabled=false;orbit.enableDamping=true;orbit.minDistance=6;orbit.maxDistance=26;orbit.maxPolarAngle=Math.PI*.47;orbit.target.set(0,1,0)
  let mode='walk',enabled=false,disposed=false,last=0,yaw=0,pitch=0,drag=null,journey=null,previousReport=0
  const keys=new Set(),held=new Set(),motion=matchMedia('(prefers-reduced-motion: reduce)')
  function pose(index,instant=false){const p=homePhotos[index],target=new T.Vector3(...p.target),end=new T.Vector3(...p.position),d=target.sub(end);const endYaw=Math.atan2(-d.x,-d.z),endPitch=Math.atan2(d.y,Math.hypot(d.x,d.z));mode='walk';orbit.enabled=false;keys.clear();held.clear()
    camera.fov=64;resize();
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
  function resize(){const w=host.clientWidth,h=host.clientHeight;if(!w||!h)return;renderer.setSize(w,h,false);camera.aspect=mode==='calibration'?4/3:w/h;camera.updateProjectionMatrix();if(mode==='calibration'){const vw=Math.min(w,h*4/3),vh=vw*.75;renderer.setViewport((w-vw)/2,(h-vh)/2,vw,vh)}else renderer.setViewport(0,0,w,h)}
  const observer=new ResizeObserver(resize);observer.observe(host);resize()
  await renderer.compileAsync(scene,camera).catch(()=>{})
  renderer.shadowMap.autoUpdate=false;renderer.shadowMap.needsUpdate=true
  renderer.setAnimationLoop(time=>{
    if(disposed||document.hidden)return
    const dt=last?Math.min((time-last)/1000,.04):.016;last=time
    if(mode==='overview')orbit.update()
    else if(mode!=='calibration'){
      if(journey){journey.t=Math.min(1,journey.t+dt/1.15);const s=journey.t*journey.t*(3-2*journey.t);camera.position.lerpVectors(journey.start,journey.end,s);yaw=T.MathUtils.lerp(journey.startYaw,journey.endYaw,s);pitch=T.MathUtils.lerp(journey.startPitch,journey.endPitch,s);if(journey.t===1)journey=null}
      else if(enabled){const has=(...k)=>k.some(v=>keys.has(v)||held.has(v));let forward=Number(has('KeyW','ArrowUp'))-Number(has('KeyS','ArrowDown')),side=Number(has('KeyD','ArrowRight'))-Number(has('KeyA','ArrowLeft'));const length=Math.hypot(forward,side);if(length){forward/=length;side/=length;const step=dt*2.0,p=moveWithinYard(camera.position,(-Math.sin(yaw)*forward+Math.cos(yaw)*side)*step,(-Math.cos(yaw)*forward-Math.sin(yaw)*side)*step);camera.position.x=p.x;camera.position.z=p.z}}
      camera.rotation.set(pitch,yaw,0,'YXZ')
    }
    renderer.render(scene,camera)
    if(time-previousReport>180){previousReport=time;report({position:{x:camera.position.x,z:camera.position.z,yaw},calls:renderer.info.render.calls})}
  })
  return {
    photoCompare(){
      clear();mode='calibration';enabled=false;journey=null;orbit.enabled=false;
      const fit=photoCameraFit,r=fit.worldToCameraCV,c=fit.cameraLocalScaled;
      camera.position.set(housePlacement.position[0]-c[0],c[1],housePlacement.position[2]-.15-c[2]);
      const cv=new T.Matrix4().set(...r[0],0,...r[1],0,...r[2],0,0,0,0,1).transpose();
      const worldRotation=new T.Matrix4().makeRotationY(Math.PI).multiply(cv).multiply(new T.Matrix4().makeRotationX(Math.PI));
      camera.quaternion.setFromRotationMatrix(worldRotation);camera.fov=fit.verticalFovDegrees;resize();report({mode});
    },
    photoTextures(value){setPhotoTextures(value)},
    gaussianDetails(value){if(hybridDetails)hybridDetails.visible=value},
    photoDetail(){clear();mode='walk';camera.fov=64;resize();orbit.enabled=false;journey=null;camera.position.set(1.8,1.8,1.3);const d=new T.Vector3(6.1,2.8,2.0).sub(camera.position);yaw=Math.atan2(-d.x,-d.z);pitch=Math.atan2(d.y,Math.hypot(d.x,d.z));camera.rotation.set(pitch,yaw,0);enabled=true;report({mode,photoDetail:true});canvas.focus({preventScroll:true})},
    enter(){enabled=true;canvas.focus({preventScroll:true})},
    pause(value){enabled=!value;orbit.enabled=!value&&mode==='overview';clear()},
    view(index){pose(index);enabled=true;canvas.focus({preventScroll:true})},
    overview(){clear();mode='overview';camera.fov=64;resize();journey=null;camera.position.set(-14,15,-18);orbit.enabled=true;orbit.target.set(0,1,1);orbit.update();report({mode})},
    hold(key,value){journey=null;if(value)held.add(key);else held.delete(key)},
    step(key){if(!enabled||mode!=='walk')return;journey=null;const f=key==='KeyW'?1:key==='KeyS'?-1:0,s=key==='KeyD'?1:key==='KeyA'?-1:0,p=moveWithinYard(camera.position,(-Math.sin(yaw)*f+Math.cos(yaw)*s)*.55,(-Math.cos(yaw)*f-Math.sin(yaw)*s)*.55);camera.position.x=p.x;camera.position.z=p.z},
    dispose(){if(disposed)return;disposed=true;renderer.setAnimationLoop(null);observer.disconnect();orbit.dispose();clear();canvas.removeEventListener('pointerdown',down);canvas.removeEventListener('pointermove',move);canvas.removeEventListener('pointerup',up);canvas.removeEventListener('pointercancel',up);window.removeEventListener('keydown',keydown);window.removeEventListener('keyup',keyup);window.removeEventListener('blur',clear);document.removeEventListener('visibilitychange',clear);scene.traverse(o=>{if(o.isInstancedMesh)o.dispose()});shadowHelper.dispose();sun.shadow.dispose();hybridDetails?.dispose();hybridRenderer?.dispose();geos.forEach(o=>o.dispose());extraTextures.forEach(o=>o.dispose());extraMats.forEach(o=>o.dispose());M.dispose();environment.dispose();renderer.dispose();canvas.remove()},
  }
}
