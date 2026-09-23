import * as T from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js'
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js'
import { makeHomeMaterials } from './homeMaterials.js'
import { carBodyGeometry, corrugatedSheetGeometry, wornSkirtingGeometry } from './homeDetailGeometry.js'
import { bakeGroundOcclusion } from './homeOcclusion.js'
import { createFacadeGeometry } from './homeFacade.js'
import { createPorchGeometry, createRiserGeometry } from './homePorch.js'
import { createGroundCracks, createWallFootWear } from './homeGroundDetail.js'
import { createYardSoilGeometry } from './homeGroundRegions.js'
import { createWinterTreeGeometry } from './homeTree.js'
import { createIronGatePanel, setGroundOcclusionUV } from './homeGate.js'
import { createMasonryGeometry } from './homeMasonry.js'
import { createTimberGeometry, createWoodenDoorGeometry } from './homeJoinery.js'
import { createPlasterWearGeometry } from './homePlasterWear.js'
import { createGlazingGeometry, createCurtainGeometry, createPaperGeometry } from './homeWindowDetail.js'
import { createGableRoofDeck, createGableWallClosure, createEaveShade } from './homeEaves.js'
import { createRoofTileGeometry, createRoofTileLayout } from './homeRoof.js'
import { homePhotos, housePlacement, houseLocalMinX, houseLocalMaxX, houseWestX, houseDoorLocalX, houseEntry, houseWindows, houseWindowCenterY, houseWindowOpeningHeight, sideWallHeight, frontWallHeight, mainRoof, frontPorch, corridorCanopy, gateX, gateZ, gatePortico, gateApron, gateEastPillarX, gateWestPillarX, eastWall, yardGroundBoundary, courtyardTrees, shedPlacement, shedRoof as shedRoofLayout, shedClerestory, shedSideBrick, shedStepWall, shedBeamY, westWing, westCorridorCanopy, westGroundCorridor, westRoof, westFacade, westBoundary, westWallX, moveWithinYard } from './homeLayout.js'

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
  const clay=M.mapped(M.maps.firedClay,[1,1],{vertexColors:true,roughness:1});
  const joinery=M.mapped(M.maps.wood,[1,1],{vertexColors:true});
  const mortarMaterial=M.mapped(M.maps.firedClay,[1,1],{vertexColors:true,roughness:1,bumpScale:.003});
  function mesh(parent,geometry,material,p=[0,0,0],s=[1,1,1]){const o=new T.Mesh(geometry,material);o.position.set(...p);o.scale.set(...s);o.castShadow=o.receiveShadow=true;parent.add(o);return o}
  const box=(parent,m,p,s)=>{
    if(!m.map||!m.userData.unit)return mesh(parent,cube,m,p,s)
    const geometry=g(cube.clone()),uv=geometry.attributes.uv,n=geometry.attributes.normal,[u,v]=m.userData.unit
    for(let i=0;i<uv.count;i++){const axis=Math.abs(n.getX(i))>.5?'x':Math.abs(n.getY(i))>.5?'y':'z',a=axis==='x'?s[2]:s[0],b=axis==='y'?s[2]:s[1];uv.setXY(i,uv.getX(i)*a/u/m.map.repeat.x,uv.getY(i)*b/v/m.map.repeat.y)}
    return mesh(parent,geometry,m,p,s)
  }
  const ball=(parent,m,p,s)=>mesh(parent,sphere,m,p,s)
  let timberSeed=301;
  const timber=(parent,p,size,grainAxis='y')=>mesh(parent,g(createTimberGeometry(...size,{seed:timberSeed++,grainAxis})),joinery,p);
  const oldGlass=mat('#a1b5b5',{roughness:.28,metalness:0,envMapIntensity:.25,transparent:true,opacity:.19,depthWrite:false,vertexColors:true});
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
  sun.castShadow=true;sun.shadow.mapSize.set(2048,2048);Object.assign(sun.shadow.camera,{left:-17,right:17,top:17,bottom:-17,near:1,far:48});sun.shadow.normalBias=.04;sun.shadow.bias=-.00015
  const shadowHelper=new T.CameraHelper(sun.shadow.camera);shadowHelper.visible=false;scene.add(shadowHelper)
  const earth=mesh(scene,g(new T.PlaneGeometry(140,140)),M.mapped(M.maps.soil,[16,16]));earth.rotation.x=-Math.PI/2
  // The earlier courtyard top was below the infinite earth plane, hiding its
  // material. This surface sits just above it and has its own unscaled AO UVs.
  const groundWidth=houseWestX+6.3,groundFrontZ=gateZ-1.7,groundBackZ=8,groundDepth=groundBackZ-groundFrontZ,groundGeo=g(new T.PlaneGeometry(groundWidth,groundDepth));
  groundGeo.setAttribute('uv1',groundGeo.attributes.uv.clone());
  const groundAO=bakeGroundOcclusion([-6.3,houseWestX,groundFrontZ,groundBackZ]);extraTextures.add(groundAO);
  const courtyardGround=mesh(scene,groundGeo,M.mapped(M.maps.ground,[groundWidth/4,groundDepth/4],{aoMap:groundAO,aoMapIntensity:1.1}),[(houseWestX-6.3)/2,.001,(groundFrontZ+groundBackZ)/2]);
  courtyardGround.rotation.x=-Math.PI/2;courtyardGround.castShadow=false;
  // The hand-marked line is the material edge: poured cement toward the
  // entrance, bare winter soil toward the vehicle shelter and trees.
  const soilGeo=g(createYardSoilGeometry(yardGroundBoundary,{frontZ:groundFrontZ,backZ:groundBackZ,minX:-6.3,maxX:houseWestX}));
  const yardSoil=mesh(scene,soilGeo,M.mapped(M.maps.soil,[groundWidth/4,groundDepth/4],{aoMap:groundAO,aoMapIntensity:1.1}));
  yardSoil.castShadow=false;
  // Confirmed by the owner: the house faces the entrance across the yard.
  const house=group(scene,housePlacement.position);house.rotation.y=housePlacement.rotationY;house.scale.x=housePlacement.scaleX
  const doorX=houseDoorLocalX,doorW=houseEntry.woodWidth,doorH=2.65
  mesh(house,g(createFacadeGeometry({minX:houseLocalMinX,maxX:houseLocalMaxX,height:5,doorX,windows:houseWindows})),white);
  // A single building volume and continuous facade, not a lower side annex.
  box(house,white,[(houseLocalMaxX+houseLocalMinX)/2,2.5,-3.1],[houseLocalMaxX-houseLocalMinX,5,6]);box(house,mat('#242620'),[doorX,1.6,.16],[houseEntry.openingWidth-.1,3.05,.025])
  const porchConcrete=M.mapped(M.maps.ground,[1,1],{vertexColors:true,roughness:1});
  mesh(house,g(createPorchGeometry(frontPorch.width,frontPorch.depth,frontPorch.height)),porchConcrete,[frontPorch.centerX,0,frontPorch.centerZ]);box(house,mat('#817b6a'),[1.7,.06,1.82],[15.5,.13,.35])
  // Photo 1: exposed, uneven brick riser and a shallow drainage strip below it.
  const stepColors=['#817a69','#918273','#746f61','#a08c78'].map(c=>mat(c));
  mesh(house,g(createRiserGeometry()),mat('#ffffff',{vertexColors:true,roughness:1}),[1.7,0,1.75]);
  box(house,dark,[1.7,.014,2.01],[15.3,.022,.16]);
  mesh(house,g(createPorchGeometry(15.3,.16,.055,47)),porchConcrete,[1.7,0,2.17]);
  // Worn cement skirting in the reference, kept clear of the doorway.
  const wallCement=M.mapped(M.maps.plaster,[1,1],{color:'#a5a69f',bumpScale:.009,vertexColors:true});
  const flakingPlaster=mat('#ffffff',{vertexColors:true,transparent:true,depthWrite:false,roughness:1,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  const doorwayEdge=houseEntry.openingWidth/2+.06;
  for(const [a,b] of [[houseLocalMinX,doorX-doorwayEdge],[doorX+doorwayEdge,houseLocalMaxX]]){
    mesh(house,g(wornSkirtingGeometry(b-a,.44,a)),wallCement,[(a+b)/2,.28,.167]);
    const flakes=mesh(house,g(createPlasterWearGeometry(b-a,{seed:2192015+Math.round(a*100)})),flakingPlaster,[(a+b)/2,.28,.168]);flakes.castShadow=false;
  }
  const fu=M.writing('福','#792626','#b59351');fu.vertexColors=true;
  // Two full-height leaves read as a real main entrance, wider than the wing doors.
  for(const side of [-1,1]){
    const leafW=doorW/2,door=group(house,[doorX+side*doorW/2,.28,.25]);door.rotation.y=side*.035;
    mesh(door,g(createWoodenDoorGeometry(leafW,doorH,.075,{seed:2015+side})),joinery,[-side*leafW/2,doorH/2,0]);
    const handleX=-side*(leafW-.12);
    box(door,silver,[handleX,1.2,.075],[.035,.16,.035]);
    for(const y of [.42,2.14]){
      box(door,metal,[-side*.055,y,.061],[.11,.17,.018]);
      rod(door,[-side*.015,y-.12,.062],[-side*.015,y+.12,.062],.018,metal);
    }
    if(side===1)mesh(door,g(createPaperGeometry(.42,.55,2015)),fu,[-leafW/2,1.93,.042]).castShadow=false;
  }
  const couplet=M.writing('平安顺遂岁岁春','#852522','#bf9b46',true)
  couplet.vertexColors=true;
  for(const side of [-1,1])mesh(house,g(createPaperGeometry(.13,2.7,side+2015)),couplet,[doorX+side*(houseEntry.openingWidth/2+.12),1.63,.23]).castShadow=false;
  timber(house,[doorX,3.05,.2],[houseEntry.transomWidth+.1,.09,.15],'x');
  // Photo 1: a broad wooden transom and narrow glazed sidelights frame the door.
  const transomW=houseEntry.transomWidth,transomCellW=transomW/5;
  box(house,dark,[doorX,3.39,.205],[transomW,.6,.035]);
  for(const y of [3.07,3.72])timber(house,[doorX,y,.25],[transomW+.15,.07,.1],'x');
  for(let i=0;i<6;i++)timber(house,[doorX-transomW/2+i*transomCellW,3.39,.25],[.055,.64,.1]);
  for(let i=0;i<5;i++)glassPane(house,[doorX-transomW/2+(i+.5)*transomCellW,3.39,.231],transomCellW-.055,.60,i);
  for(const side of [-1,1]){
    const x=doorX+side*houseEntry.sideWindowOffset,w=houseEntry.sideWindowWidth,lowerRailY=houseWindowCenterY-houseWindowOpeningHeight/2+.04,upperRailY=3.0;
    const h=upperRailY-lowerRailY,centerY=(upperRailY+lowerRailY)/2;
    // The glazed sidelights now start on the same horizontal line as the two
    // large windows; solid plaster fills their former low glazed portion.
    box(house,white,[x,lowerRailY/2,.18],[w,lowerRailY,.06]);
    box(house,dark,[x,centerY,.2],[w,h,.04]);
    for(const dx of [-w/2-.015,w/2+.015])timber(house,[x+dx,centerY,.25],[.045,h+.08,.08]);
    for(const y of [lowerRailY,centerY,upperRailY])timber(house,[x,y,.26],[w+.05,.045,.07],'x');
    for(const y of [(lowerRailY+centerY)/2,(centerY+upperRailY)/2])glassPane(house,[x,y,.229],w-.03,h/2-.06,y+side);
    rod(house,[x,lowerRailY,.28],[x,upperRailY,.28],.012,metal);
  }
  // Old wooden windows, dark interiors and separate iron bars.
  const windowCurtains=[];
  function windowAt(x,width=2.5){
    const win=group(house,[x,houseWindowCenterY,0]);
    const halfH=houseWindowOpeningHeight/2,innerTop=halfH-.04,dividerY=.18;
    box(win,mat('#222c2e'),[0,0,-.045],[width,houseWindowOpeningHeight-.11,.018]);
    for(const side of [-1,1])timber(win,[side*(width/2+.025),0,.105],[.085,houseWindowOpeningHeight+.09,.16]);
    for(const y of [-halfH,halfH])timber(win,[0,y,.105],[width+.13,.085,.16],'x');
    for(let j=1;j<3;j++)timber(win,[-width/2+j*width/3,0,.073],[.055,houseWindowOpeningHeight,.11]);
    for(const y of [-innerTop,dividerY,innerTop])timber(win,[0,y,.073],[width,.06,.11],'x');
    for(let j=0;j<10;j++)rod(win,[-width/2+j*width/9,-halfH+.08,.19],[-width/2+j*width/9,halfH-.08,.19],.012,metal)
    box(win,concrete,[0,-halfH-.07,.13],[width+.23,.12,.38])
    // Thin wood beading catches daylight around the inset glazing.
    for(const y of [-innerTop+.03,dividerY-.035,innerTop-.03])timber(win,[0,y,.014],[width-.06,.014,.025],'x');
    const curtain=mesh(win,g(createCurtainGeometry(width-.14,houseWindowOpeningHeight-.12,x)),mat('#465055',{roughness:1}),[0,.025,-.024]);curtain.castShadow=false;windowCurtains.push(curtain);
    const lowerPane=[(-innerTop+dividerY)/2,innerTop+dividerY-.08],upperPane=[(dividerY+innerTop)/2,innerTop-dividerY-.08];
    for(let i=0;i<3;i++)for(const [y,h] of [lowerPane,upperPane])glassPane(win,[-width/2+(i+.5)*width/3,y,.006],width/3-.065,h,i+x);
  }
  for(const win of houseWindows)windowAt(win.x,win.width)
  const roofWidth=mainRoof.width;
  mesh(house,g(createGableWallClosure({minX:houseLocalMinX,maxX:houseLocalMaxX,wallTop:5,eaveY:mainRoof.eaveY,ridgeZ:mainRoof.centerZ,depth:mainRoof.depth,rise:mainRoof.rise,roofThickness:mainRoof.thickness})),white);
  const roofBed=M.mapped(M.maps.firedClay,[1,1],{color:'#77756b',bumpScale:.002,roughness:1});
  // Confirmed continuous, level concrete platform above the full corridor.
  // Owner corrected both ends to be flush with the full house side walls.
  const canopyConcrete=M.mapped(M.maps.plaster,[1,1],{color:'#bdc0b8',vertexColors:true,flatShading:true,roughness:1,bumpScale:.006});
  mesh(house,g(createPorchGeometry(corridorCanopy.width,corridorCanopy.depth,corridorCanopy.thickness,1985)),canopyConcrete,[corridorCanopy.centerX,corridorCanopy.baseY,corridorCanopy.centerZ]);
  const eaveShade=mat('#ffffff',{vertexColors:true,transparent:true,depthWrite:false,roughness:1,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  const shade=mesh(house,g(createEaveShade(corridorCanopy.width)),eaveShade,[corridorCanopy.centerX,corridorCanopy.baseY-.095,.154]);shade.castShadow=false;
  const roofClay=M.mapped(M.maps.firedClay,[1,1],{color:'#80796d',vertexColors:true,bumpScale:.0018,roughness:1});
  function tiledRoof(parent,width,depth,center,tilt){
    const layout=createRoofTileLayout(width,depth,{tilt,seed:Math.round(width*100+depth*10)}),pose=new T.Object3D(),color=new T.Color();
    for(const kind of ['pan','cap'])for(let variant=0;variant<3;variant++){
      const parts=layout[kind==='pan'?'pans':'caps'].filter(tile=>tile.variant===variant);if(!parts.length)continue;
      const first=parts[0],geometry=g(createRoofTileGeometry({kind,width:first.width,length:first.length,thickness:first.thickness,seed:variant+2015}));
      const tiles=new T.InstancedMesh(geometry,roofClay,parts.length);
      for(let i=0;i<parts.length;i++){
        const tile=parts[i];pose.position.set(center[0]+tile.position[0],center[1]+tile.position[1],center[2]+tile.position[2]);pose.rotation.set(...tile.rotation);pose.updateMatrix();tiles.setMatrixAt(i,pose.matrix);color.setRGB(...tile.color);tiles.setColorAt(i,color);
      }
      tiles.computeBoundingBox();tiles.computeBoundingSphere();tiles.castShadow=tiles.receiveShadow=true;parent.add(tiles);
    }
  }
  function gabledRoof(parent,roof){
    mesh(parent,g(createGableRoofDeck(roof.width,roof.depth,{rise:roof.rise,thickness:roof.thickness})),roofBed,[roof.centerX,roof.eaveY,roof.centerZ]);
    const half=roof.depth/2,tilt=Math.atan(roof.rise/half);
    for(const rotation of [0,Math.PI]){
      const slope=group(parent,[roof.centerX,roof.eaveY+roof.rise,roof.centerZ]);slope.rotation.y=rotation;
      tiledRoof(slope,roof.width,half,[0,-roof.rise/2,half/2],tilt);
    }
    const count=Math.ceil(roof.width/.33),length=.38,step=(roof.width-length)/(count-1),pose=new T.Object3D();
    const ridge=new T.InstancedMesh(g(createRoofTileGeometry({kind:'cap',width:.36,length,thickness:.014,seed:1938})),roofClay,count);
    for(let i=0;i<count;i++){
      pose.position.set(roof.centerX-roof.width/2+length/2+i*step,roof.eaveY+roof.rise-.015+(i%2)*.002,roof.centerZ);
      pose.rotation.set(0,Math.PI/2,0);pose.updateMatrix();ridge.setMatrixAt(i,pose.matrix);
    }
    ridge.castShadow=ridge.receiveShadow=true;parent.add(ridge);
  }
  gabledRoof(house,mainRoof);
  // Preserve the old random sequence used by unrelated courtyard details.
  for(let i=0;i<Math.ceil(6.8/.41)*Math.floor(roofWidth/.186)*2;i++)rand();
  // Washing machine, thermos and a blue garment under a sagging clothesline.
  // Keep the washing machine beside, rather than inside, the relocated doorway.
  const washer=group(house,[2.6,0,0]);
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
  const wing=group(scene,westWing.position);wing.rotation.y=westWing.rotationY;
  box(wing,white,[0,westWing.wallHeight/2,0],[westWing.width,westWing.wallHeight,westWing.depth]);
  mesh(wing,g(createGableWallClosure({minX:westRoof.wallMinX,maxX:westRoof.wallMaxX,backZ:-westWing.depth/2,frontZ:westWing.depth/2,wallTop:westWing.wallHeight,eaveY:westRoof.eaveY,ridgeZ:westRoof.centerZ,depth:westRoof.depth,rise:westRoof.rise,roofThickness:westRoof.thickness})),white);
  // Flat eave extension over the full western corridor, joined to the main
  // house platform at its rear end, with a shorter 0.9 m projection.
  mesh(wing,g(createPorchGeometry(westCorridorCanopy.width,westCorridorCanopy.depth,westCorridorCanopy.thickness,1986)),canopyConcrete,[westCorridorCanopy.centerX,westCorridorCanopy.baseY,westCorridorCanopy.centerZ]);
  const wingShade=mesh(wing,g(createEaveShade(westWing.width,{height:.16,seed:2})),eaveShade,[0,westCorridorCanopy.baseY+.01,1.604]);wingShade.castShadow=false;
  gabledRoof(wing,westRoof);
  for(let i=0;i<Math.ceil(3.6/.41)*Math.floor(7.35/.14)*2;i++)rand();
  // One raised west corridor replaces the old two disconnected brick lips.
  // Its top meets the main porch at the same 28 cm height; only the outward
  // reach is narrower, matching the west eave platform above it.
  const westStepFrontZ=westGroundCorridor.centerZ+westGroundCorridor.depth/2;
  mesh(wing,g(createPorchGeometry(westGroundCorridor.width,westGroundCorridor.depth,westGroundCorridor.height,1978)),porchConcrete,[westGroundCorridor.centerX,0,westGroundCorridor.centerZ]);
  mesh(wing,g(createRiserGeometry(westGroundCorridor.width,Math.round(westGroundCorridor.width/.31),1978)),mat('#ffffff',{vertexColors:true,roughness:1}),[westGroundCorridor.centerX,0,westStepFrontZ+.05]);
  box(wing,dark,[westGroundCorridor.centerX,.014,westStepFrontZ+.26],[westGroundCorridor.width,.022,.16]);
  mesh(wing,g(createPorchGeometry(westGroundCorridor.width,.16,.055,1977)),porchConcrete,[westGroundCorridor.centerX,0,westStepFrontZ+.42]);
  for(const opening of westFacade.doors){
    const x=opening.z-westWing.position[2],w=opening.width,leafW=w/2,leafH=westFacade.doorLeafHeight;
    const leafBaseY=.22,frameBottomY=.17,topY=westFacade.doorTopY,transomBottomY=leafBaseY+leafH+.05;
    const transomH=topY-transomBottomY,transomY=(topY+transomBottomY)/2,frameH=topY-frameBottomY;
    box(wing,dark,[x,(topY+frameBottomY)/2,1.614],[w+.13,frameH,.035]);
    for(const side of [-1,1]){
      const leaf=group(wing,[x+side*w/2,leafBaseY,1.67]);leaf.rotation.y=side*.025;
      mesh(leaf,g(createWoodenDoorGeometry(leafW,leafH,.055,{seed:Math.round(opening.z*100)+side})),joinery,[-side*leafW/2,leafH/2,0]);
      for(const y of [.43,leafH-.35])box(leaf,metal,[-side*.035,y,.038],[.065,.11,.016]);
      const handleX=-side*(leafW-.095);
      box(leaf,metal,[handleX,1.05,.045],[.026,.13,.022]);
    }
    for(const side of [-1,1])timber(wing,[x+side*(w/2+.04),(topY+frameBottomY)/2,1.72],[.075,frameH+.01,.13]);
    // Glazed light above the smaller paired leaves, within the same door frame.
    box(wing,dark,[x,transomY,1.65],[w,transomH,.025]);
    for(const side of [-1,1])glassPane(wing,[x+side*w/4,transomY,1.683],w/2-.065,transomH-.07,opening.z+side);
    timber(wing,[x,transomBottomY,1.73],[w+.1,.065,.12],'x');
    timber(wing,[x,topY,1.73],[w+.1,.075,.12],'x');
    timber(wing,[x,transomY,1.73],[.045,transomH,.1]);
  }
  for(const opening of westFacade.windows){
    const x=opening.z-westWing.position[2],w=opening.width,h=opening.height,y=westFacade.windowCenterY;
    box(wing,dark,[x,y,1.65],[w,h,.06]);
    for(let row=0;row<opening.rows;row++)for(let col=0;col<opening.columns;col++){
      glassPane(wing,[x-w/2+(col+.5)*w/opening.columns,y-h/2+(row+.5)*h/opening.rows,1.695],w/opening.columns-.055,h/opening.rows-.06,row*opening.columns+col);
    }
    for(let col=0;col<=opening.columns;col++)timber(wing,[x-w/2+col*w/opening.columns,y,1.73],[(col===0||col===opening.columns) ? .075 : .047,h+.1,.09]);
    for(let row=0;row<=opening.rows;row++)timber(wing,[x,y-h/2+row*h/opening.rows,1.73],[w+.09,(row===0||row===opening.rows) ? .075 : .047,.09],'x');
    box(wing,concrete,[x,y-h/2-.04,1.72],[w+.15,.1,.25]);
  }
  // The brick boundary meets the wing at its front corner, with both outside faces flush.
  const westBoundaryLength=westBoundary.endZ-westBoundary.startZ;
  const eastBoundaryLength=eastWall.endZ-eastWall.startZ;
  masonry(scene,[eastWall.centerX,sideWallHeight/2,(eastWall.startZ+eastWall.endZ)/2],[eastWall.thickness,sideWallHeight,eastBoundaryLength],1001);
  masonry(scene,[shedSideBrick.x,(shedSideBrick.bottomY+shedSideBrick.topY)/2,(shedSideBrick.startZ+shedSideBrick.endZ)/2],[shedSideBrick.thickness,shedSideBrick.topY-shedSideBrick.bottomY,shedSideBrick.endZ-shedSideBrick.startZ],1011);
  masonry(scene,[westWallX,sideWallHeight/2,(westBoundary.startZ+westBoundary.endZ)/2],[westBoundary.thickness,sideWallHeight,westBoundaryLength],1002);
  // Uneven exposed coping breaks the perfectly straight silhouette of the wall.
  const eastCapCount=Math.ceil((eastBoundaryLength-.3)/.254)+1,westCapCount=Math.ceil((westBoundaryLength-.3)/.254)+1;
  const capGeo=g(new RoundedBoxGeometry(.35,.09,.25,1,.013)),caps=new T.InstancedMesh(capGeo,stepColors[0],eastCapCount+westCapCount),capPose=new T.Object3D(),capColor=new T.Color();
  for(let i=0;i<eastCapCount+westCapCount;i++){
    const west=i>=eastCapCount,index=west?i-eastCapCount:i,side=west?westWallX:eastWall.centerX;
    const z=west?westBoundary.startZ+.15+index*(westBoundaryLength-.3)/(westCapCount-1):eastWall.startZ+.15+index*(eastBoundaryLength-.3)/(eastCapCount-1);
    capPose.position.set(side,sideWallHeight+.015+rand()*.02,z);capPose.rotation.set((rand()-.5)*.05,(rand()-.5)*.025,(rand()-.5)*.04);capPose.updateMatrix();caps.setMatrixAt(i,capPose.matrix);capColor.setHSL(.085,.12,.32+rand()*.13);caps.setColorAt(i,capColor)
  }caps.castShadow=caps.receiveShadow=true;scene.add(caps);
  const gateEastOuterX=gateEastPillarX-gatePortico.pillarWidth/2,gateWestOuterX=gateWestPillarX+gatePortico.pillarWidth/2;
  masonry(scene,[(-6.3+gateEastOuterX)/2,frontWallHeight/2,gateZ],[gateEastOuterX+6.3,frontWallHeight,.35],1003);masonry(scene,[(gateWestOuterX+westWallX)/2,frontWallHeight/2,gateZ],[westWallX-gateWestOuterX,frontWallHeight,.35],1004)
  const gate=group(scene,[gateX,0,gateZ])
  const gateHalf=gatePortico.halfPillarSpacing,gateLeafW=gatePortico.leafWidth;
  masonry(gate,[-gateHalf,1.95,gatePortico.centerLocalZ],[gatePortico.pillarWidth,3.9,gatePortico.depth],1005);masonry(gate,[gateHalf,1.95,gatePortico.centerLocalZ],[gatePortico.pillarWidth,3.9,gatePortico.depth],1006);
  const lintelConcrete=M.mapped(M.maps.ground,[1,1],{vertexColors:true,roughness:1,flatShading:true});
  mesh(gate,g(createPorchGeometry(gatePortico.outerWidth,gatePortico.depth,.35,1922)),lintelConcrete,[0,3.605,gatePortico.centerLocalZ]);
  const gateInside=M.mapped(M.maps.plaster,[.5,1]);for(const side of [-1,1])box(gate,gateInside,[side*(gateHalf-.24),1.72,gatePortico.centerLocalZ],[.04,3.42,gatePortico.depth-.1]);
  // The broad outer portal leads to a smaller, one-metre-deep inner doorway.
  const innerHalf=gatePortico.innerClearWidth/2,innerZ=gatePortico.leafLocalZ,innerTop=gatePortico.innerTopY;
  for(const side of [-1,1])box(gate,gateInside,[side*(innerHalf+gatePortico.recessSideInset/2),innerTop/2,innerZ+.16],[gatePortico.recessSideInset,innerTop,.32]);
  box(gate,gateInside,[0,innerTop+gatePortico.recessTopInset/2,innerZ+.16],[gatePortico.clearWidth,gatePortico.recessTopInset,.32]);
  const gateEnamel=M.mapped(M.maps.gatePaint,[1,1],{metalness:.12,vertexColors:true});
  const gateRail=M.mapped(M.maps.gatePaint,[1,1],{metalness:.18,color:'#b9b1a5'});
  const gateLeafH=innerTop-.08;
  for(const side of [-1,1]){
    const hingeX=side*innerHalf,leaf=group(gate,[hingeX,0,innerZ]);leaf.rotation.y=side===-1?.1:-.04;
    mesh(leaf,g(createIronGatePanel(gateLeafW,gateLeafH)),gateEnamel,[-side*gateLeafW/2,.035,0]);
    for(const faceZ of [-.045,.045]){
      for(const x of [-side*.018,-side*(gateLeafW-.018)])box(leaf,gateRail,[x,(gateLeafH+.07)/2,faceZ],[.036,gateLeafH,.025]);
      for(const y of [.055,.48,1.28,2.08,gateLeafH+.035])box(leaf,gateRail,[-side*gateLeafW/2,y,faceZ],[gateLeafW,.033,.025]);
    }
    for(const y of [.45,1.36,2.25]){
      box(leaf,gateRail,[-side*.065,y,.05],[.13,.12,.018]);
      rod(gate,[hingeX,y-.09,innerZ+.04],[hingeX,y+.09,innerZ+.04],.025,metal);
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
  // The separate, raised apron needs its own AO; the courtyard map below it
  // cannot shade this surface through a solid slab.
  const apronBounds=[gateX-gateApron.width/2,gateX+gateApron.width/2,gateApron.frontZ,gateApron.backZ],apronZ=gateApron.centerZ;
  const apronAO=bakeGroundOcclusion(apronBounds,undefined,64,.083);extraTextures.add(apronAO);
  const apronGeo=g(setGroundOcclusionUV(createPorchGeometry(gateApron.width,gateApron.depth,.065,1914),apronBounds,[gateX,0,apronZ]));
  mesh(scene,apronGeo,M.mapped(M.maps.ground,[1,1],{vertexColors:true,aoMap:apronAO,aoMapIntensity:1.1}),[gateX,0,apronZ]);
  // Long wooden poles leaning beside the gate.
  for(let i=0;i<12;i++)rod(scene,[gateEastPillarX-1.0-rand()*.6,.05,gateZ+.8+rand()*.4],[gateEastPillarX-.35-rand()*.6,2.0+rand()*1.1,gateZ+.5],.018+rand()*.015,wood)
  rod(scene,[gateWestPillarX+.5,.1,gateZ+.8],[gateWestPillarX+.85,2.1,gateZ+.4],.024,wood);const shovel=box(scene,silver,[gateWestPillarX+.49,.28,gateZ+.84],[.38,.48,.045]);shovel.rotation.x=-.2
  function basin(p,r=.38){const o=mesh(scene,g(new T.CylinderGeometry(r,r*.74,.14,24,1,true)),silver,p);mesh(scene,g(new T.CircleGeometry(r*.75,24)),dark,[p[0],p[1]-.062,p[2]]).rotation.x=-Math.PI/2;return o}
  basin([gateWestPillarX+.7,.09,gateZ+1.2],.4);basin([gateWestPillarX+1.2,.09,gateZ+2],.26)
  // The low sheet stops at the corridor front. The rear wash area stays under
  // the high house platform; a glazed band fills the height between the two.
  const shed=group(scene,shedPlacement.position),shedRoof=M.maps.roofMetal?M.mapped(M.maps.roofMetal,[1,1],{metalness:.32,roughness:.92,side:T.DoubleSide}):mat('#45473e',{metalness:.32,roughness:.92,side:T.DoubleSide})
  for(const [x,z] of shedPlacement.posts){const localZ=z-shedPlacement.position[2],top=z>shedRoofLayout.backZ?corridorCanopy.baseY:shedBeamY(localZ)-shedPlacement.beamThickness/2;rod(shed,[x-shedPlacement.position[0],0,localZ],[x-shedPlacement.position[0],top,localZ],.045,metal)}
  const sheetCenterY=shedPlacement.roofY+shedRoofLayout.centerLocalZ*shedRoofLayout.pitch;
  mesh(shed,g(corrugatedSheetGeometry(shedRoofLayout.width,shedRoofLayout.depth,.15,shedRoofLayout.pitch)),shedRoof,[shedRoofLayout.centerLocalX,sheetCenterY,shedRoofLayout.centerLocalZ]);
  const sheetWaveX=x=>Math.cos((x-shedRoofLayout.centerLocalX+shedRoofLayout.width/2)/.15*Math.PI*2)*shedRoofLayout.corrugation;
  for(const x of [-.92,.58]){const seam=box(shed,metal,[x,sheetCenterY+sheetWaveX(x)+.008,shedRoofLayout.centerLocalZ],[.018,.014,shedRoofLayout.depth]);seam.rotation.x=-Math.atan(shedRoofLayout.pitch)}
  const fasteners=new T.InstancedMesh(g(new T.SphereGeometry(.014,6,4)),metal,39),fastenerPose=new T.Object3D();
  for(let i=0;i<39;i++){const x=-1.8+(i%13)*.30,z=[-3,1.3,shedRoofLayout.backZ-shedPlacement.position[2]-.06][Math.floor(i/13)];fastenerPose.position.set(x,shedPlacement.roofY+sheetWaveX(x)+z*shedRoofLayout.pitch+.012,z);fastenerPose.scale.set(1,.35,1);fastenerPose.updateMatrix();fasteners.setMatrixAt(i,fastenerPose.matrix)}shed.add(fasteners);
  for(const z of [-3,1.3,shedRoofLayout.backZ-shedPlacement.position[2]-.06])box(shed,wood,[shedRoofLayout.centerLocalX,shedBeamY(z),z],[shedRoofLayout.width,shedPlacement.beamThickness,.07]);
  const glassZ=shedClerestory.z-shedPlacement.position[2]-.025;
  const glassBottom=shedClerestory.bottomY+.045,glassTop=shedClerestory.topY-.045;
  const glassHeight=glassTop-glassBottom,glassCenterY=(glassTop+glassBottom)/2;
  const frameMetal=mat('#393d39',{metalness:.36,roughness:.7});
  const upperGlass=mat('#60747a',{metalness:.08,roughness:.24,transparent:true,opacity:.47,depthWrite:false,side:T.DoubleSide});
  box(shed,frameMetal,[shedClerestory.centerLocalX,glassBottom,glassZ],[shedClerestory.width,.065,.065]);
  box(shed,frameMetal,[shedClerestory.centerLocalX,glassTop,glassZ],[shedClerestory.width,.065,.065]);
  const glassCount=5,glassCellW=shedClerestory.width/glassCount;
  for(let i=0;i<=glassCount;i++){
    const x=shedClerestory.centerLocalX-shedClerestory.width/2+i*glassCellW;
    box(shed,frameMetal,[x,glassCenterY,glassZ],[.055,glassHeight,.065]);
    if(i<glassCount){const pane=face(shed,upperGlass,[x+glassCellW/2,glassCenterY,glassZ-.036],[glassCellW-.065,glassHeight-.07],Math.PI);pane.castShadow=false;pane.receiveShadow=false}
  }
  const stepWallCement=M.mapped(M.maps.plaster,[1,1],{color:'#a5a69f',roughness:1,bumpScale:.009});
  box(scene,stepWallCement,[(shedStepWall.minX+shedStepWall.maxX)/2,(shedStepWall.bottomY+shedStepWall.topY)/2,shedStepWall.z],[shedStepWall.maxX-shedStepWall.minX,shedStepWall.topY-shedStepWall.bottomY,shedStepWall.thickness]);
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
    dispose(){if(disposed)return;disposed=true;renderer.setAnimationLoop(null);observer.disconnect();orbit.dispose();clear();canvas.removeEventListener('pointerdown',down);canvas.removeEventListener('pointermove',move);canvas.removeEventListener('pointerup',up);canvas.removeEventListener('pointercancel',up);window.removeEventListener('keydown',keydown);window.removeEventListener('keyup',keyup);window.removeEventListener('blur',clear);document.removeEventListener('visibilitychange',clear);scene.traverse(o=>{if(o.isInstancedMesh)o.dispose()});shadowHelper.dispose();sun.shadow.dispose();geos.forEach(o=>o.dispose());extraTextures.forEach(o=>o.dispose());extraMats.forEach(o=>o.dispose());M.dispose();environment.dispose();renderer.dispose();canvas.remove()},
  }
}
