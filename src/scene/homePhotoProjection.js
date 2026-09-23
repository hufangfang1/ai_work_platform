import * as T from 'three'

// Destination UV -> source-photo UV. Four independently measured image
// corners define a planar projective transform, not an affine crop.
export function photoHomography(corners,imageWidth,imageHeight){
  if(corners.length!==4||![imageWidth,imageHeight].every(n=>Number.isFinite(n)&&n>0)||!corners.every(p=>p.length===2&&p.every(Number.isFinite)))throw new RangeError('Four finite photo corners and positive image dimensions required');
  const uv=[[0,1],[1,1],[1,0],[0,0]],rows=[];
  corners.forEach(([px,py],i)=>{
    const [x,y]=uv[i],u=px/imageWidth,v=1-py/imageHeight;
    rows.push([x,y,1,0,0,0,-u*x,-u*y,u],[0,0,0,x,y,1,-v*x,-v*y,v]);
  });
  for(let col=0;col<8;col++){
    let pivot=col;for(let row=col+1;row<8;row++)if(Math.abs(rows[row][col])>Math.abs(rows[pivot][col]))pivot=row;
    if(Math.abs(rows[pivot][col])<1e-12)throw new RangeError('Degenerate photo projection');
    [rows[col],rows[pivot]]=[rows[pivot],rows[col]];
    const divisor=rows[col][col];for(let j=col;j<9;j++)rows[col][j]/=divisor;
    for(let row=0;row<8;row++)if(row!==col){const factor=rows[row][col];for(let j=col;j<9;j++)rows[row][j]-=factor*rows[col][j]}
  }
  const h=rows.map(r=>r[8]);return new T.Matrix3().set(h[0],h[1],h[2],h[3],h[4],h[5],h[6],h[7],1);
}

export function createPhotoProjectionMaterial(texture,corners,referenceSize,{feather=.025,opacity=1,repeatUv=false,gain=1}={}){
  return new T.ShaderMaterial({
    uniforms:{photo:{value:texture},projection:{value:photoHomography(corners,...referenceSize)},feather:{value:feather},opacity:{value:opacity},repeatUv:{value:repeatUv},gain:{value:gain}},
    transparent:true,depthWrite:false,polygonOffset:true,polygonOffsetFactor:-2,polygonOffsetUnits:-2,toneMapped:false,
    vertexShader:`varying vec2 surfaceUv; void main(){surfaceUv=uv;gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.0);}`,
    fragmentShader:`uniform sampler2D photo;uniform mat3 projection;uniform float feather;uniform float opacity;uniform float gain;uniform bool repeatUv;varying vec2 surfaceUv;
      void main(){
        vec2 sampleUv=repeatUv?fract(surfaceUv):surfaceUv;
        vec3 q=projection*vec3(sampleUv,1.0);
        if(abs(q.z)<0.00001)discard;
        vec2 sourceUv=q.xy/q.z;
        if(any(lessThan(sourceUv,vec2(0.0)))||any(greaterThan(sourceUv,vec2(1.0))))discard;
        float edge=min(min(sampleUv.x,1.0-sampleUv.x),min(sampleUv.y,1.0-sampleUv.y));
        vec4 pixel=texture2D(photo,sourceUv);
        gl_FragColor=vec4(pixel.rgb*gain,pixel.a*opacity*(repeatUv?1.0:smoothstep(0.0,max(feather,0.00001),edge)));
        #include <colorspace_fragment>
      }`,
  });
}

// Coordinates are measured on the displayed reference dimensions, so the
// same mapping works on the full-resolution original. Clockwise from top left.
export const photoSources={
  main:{url:'/home-photos/01-house.jpg',size:[1440,1080]},
  west:{url:'/home-photos/references/west-facade-2015.jpg',size:[1824,1368]},
  passage:{url:'/home-photos/references/passage-2015.jpg',size:[1824,1368]},
  doorway:{url:'/home-photos/references/door-timber-2015.jpg',size:[1824,1368]},
  entry:{url:'/home-photos/references/main-entry-2015.jpg',size:[1824,1368]},
  yard:{url:'/home-photos/02-yard.jpg',size:[1440,1080]},
};
export const photoRegions={
  yardEarth:{source:'yard',corners:[[280,872],[631,850],[615,1004],[245,1034]],scale:[1.3,1.7],strength:.78,gain:.72,grain:.12},
  // Interior of the broken cement patch in photo 01, excluding its intact
  // perimeter and the orange date. Used only on local wear, not intact slabs.
  yardAggregate:{source:'main',corners:[[938,920],[998,930],[1033,950],[944,946]],scale:[6,8],strength:.85,mineralMix:true,gain:.73,grain:.10,eroded:true},
  // User-marked concrete in the doorway photograph (unannotated original):
  // dusty courtyard strip to the child's right, and the gray foreground slab.
  // Keep the two finishes distinct; samples exclude the date, people and litter.
  yardCement:{source:'doorway',corners:[[1408,1000],[1518,1010],[1520,1172],[1398,1160]],scale:[4,3],strength:.88,mineralMix:true,gain:.68,grain:.045},
  porchCement:{source:'doorway',corners:[[810,1190],[1025,1201],[1037,1320],[800,1306]],scale:[3,3],strength:.88,mineralMix:true,gain:.72,grain:.035},
  entryUpperGlass:{source:'entry',corners:[[879,136],[899,136],[899,211],[879,211]],feather:.015},
  entryLowerGlass:{source:'entry',corners:[[878,249],[899,249],[899,309],[878,309]],feather:.015},
  westWindow:{source:'west',corners:[[532,718],[821,643],[851,1206],[510,1235]],feather:.004},
  westWall:{source:'west',corners:[[894,632],[1218,527],[1274,1130],[902,1235]],feather:.065},
  floral:{source:'west',corners:[[506,483],[703,398],[706,417],[508,503]],feather:.002},
  mainPier:{source:'main',corners:[[860,34],[1018,53],[1002,227],[843,211]],feather:.12},
  mainLowerWall:{source:'main',corners:[[420,444],[792,459],[789,516],[413,501]],feather:.05},
  mainLimewash:{source:'main',corners:[[161,85],[376,93],[374,439],[156,425]],feather:.08},
  reusablePlaster:{source:'west',corners:[[918,660],[1133,595],[1166,1020],[924,1096]],scale:[1.8,1.3],strength:.7},
  doorLeafWood:{source:'doorway',corners:[[52,390],[219,452],[223,1035],[55,969]],scale:[1,1],strength:1,gain:.9},
  washerPlastic:{source:'passage',corners:[[105,990],[285,990],[285,1045],[105,1045]],scale:[1,1],strength:.88,gain:.96},
  passagePanel:{source:'passage',corners:[[488,1032],[600,1030],[605,1194],[490,1198]],scale:[1,1],strength:.92,gain:.95},
  reusableWood:{source:'doorway',corners:[[106,425],[191,460],[194,911],[110,871]],scale:[1.2,1],strength:.92},
  reusableConcrete:{source:'main',corners:[[682,609],[960,609],[960,650],[677,656]],scale:[2,5],strength:.56},
  reusableFrost:{source:'passage',corners:[[492,549],[610,516],[622,887],[500,916]],scale:[1.2,1],strength:.9},
  reusableClay:{source:'passage',corners:[[959,1088],[991,1078],[993,1092],[960,1102]],scale:[8,12],strength:.6,neutral:true},
};

// Reuse only the material sample, preserving the original mesh, light response,
// roughness and relief. Mirrored samples meet continuously at tile boundaries;
// a second offset sample softens the symmetry on mineral surfaces.
export function attachPhotoSurface(material,texture,corners,referenceSize,{scale=[1,1],strength=.7,neutral=false,blend=true,mineralMix=false,gain=1,grain=0,eroded=false}={}){
  if(!material.isMeshStandardMaterial||!material.map)throw new TypeError('Photo reuse requires a mapped standard material');
  const uniforms={
    reusePhoto:{value:texture},reuseProjection:{value:photoHomography(corners,...referenceSize)},
    reuseScale:{value:new T.Vector2(...scale)},reuseStrength:{value:strength},
    reuseEnabled:{value:1},reuseNeutral:{value:neutral},reuseBlend:{value:blend},reuseMineralMix:{value:mineralMix},reuseGain:{value:gain},reuseGrain:{value:grain},reuseEroded:{value:eroded},
  };
  const previousCompile=material.onBeforeCompile,previousKey=material.customProgramCacheKey();
  material.onBeforeCompile=function(shader,renderer){
    previousCompile.call(this,shader,renderer);Object.assign(shader.uniforms,uniforms);
    shader.fragmentShader=`uniform sampler2D reusePhoto;
      uniform mat3 reuseProjection;uniform vec2 reuseScale;
      uniform float reuseStrength;uniform float reuseEnabled;
      uniform float reuseGain;uniform float reuseGrain;uniform bool reuseEroded;
      float homeGroundHash(vec2 p){return fract(sin(dot(p,vec2(127.1,311.7)))*43758.5453);}
      float homeGroundNoise(vec2 p){
        vec2 i=floor(p),f=fract(p);f=f*f*(3.0-2.0*f);
        return mix(mix(homeGroundHash(i),homeGroundHash(i+vec2(1.,0.)),f.x),mix(homeGroundHash(i+vec2(0.,1.)),homeGroundHash(i+vec2(1.)),f.x),f.y);
      }
      uniform bool reuseNeutral;uniform bool reuseBlend;uniform bool reuseMineralMix;
      vec3 homeSamplePhoto(vec2 uv){
        vec2 mirrored=1.0-abs(mod(uv,2.0)-1.0);
        vec3 q=reuseProjection*vec3(mirrored,1.0);
        return texture2D(reusePhoto,q.xy/q.z).rgb;
      }\n`+shader.fragmentShader;
    shader.fragmentShader=shader.fragmentShader.replace('#include <map_fragment>',`
      #ifdef USE_MAP
        vec4 sampledDiffuseColor=texture2D(map,vMapUv);
        vec2 reuseUv=vMapUv*reuseScale;
        vec3 borrowed=homeSamplePhoto(reuseUv);
        if(reuseMineralMix){
          // Different orientations break the directional streaks produced by
          // mirroring a narrow perspective ground sample over the whole yard.
          borrowed=mix(borrowed,homeSamplePhoto(mat2(.8,-.6,.6,.8)*reuseUv*1.17+vec2(.71,.43)),.42);
          borrowed=mix(borrowed,homeSamplePhoto(mat2(.36,.93,-.93,.36)*reuseUv*.83+vec2(.27,.81)),.22);
          borrowed=mix(vec3(dot(borrowed,vec3(.2126,.7152,.0722))),borrowed,.7);
        }else if(reuseBlend)borrowed=mix(borrowed,homeSamplePhoto(reuseUv+vec2(.71,.43)),.35);
        if(reuseNeutral){
          float value=dot(borrowed,vec3(.2126,.7152,.0722));
          borrowed=sampledDiffuseColor.rgb*clamp(value*4.0+.52,.65,1.3);
        }
        sampledDiffuseColor.rgb=mix(sampledDiffuseColor.rgb,borrowed,reuseStrength*reuseEnabled);
        // Ground photographs already contain diffuse daylight. Reduce their
        // albedo energy before the scene lights them again; retain real shadows.
        sampledDiffuseColor.rgb*=mix(1.0,reuseGain,reuseEnabled);
        float grainFade=1.0-smoothstep(.30,.95,length(fwidth(vMapUv*700.0)));
        float grit=homeGroundNoise(vMapUv*700.0)-.5;
        float pores=smoothstep(.64,.88,homeGroundNoise(vMapUv*230.0));
        sampledDiffuseColor.rgb*=1.0+reuseGrain*reuseEnabled*(grit*grainFade*2.0-pores*.65);
        if(reuseEroded){
          float broken=homeGroundNoise(vMapUv*37.0)*.65+homeGroundNoise(vMapUv*113.0)*.35;
          diffuseColor.a*=smoothstep(.22,.61,broken);
        }
        diffuseColor*=sampledDiffuseColor;
      #endif
    `);
  };
  material.customProgramCacheKey=()=>`${previousKey}:photo-reuse-v1`;
  material.needsUpdate=true;
  return {setEnabled(value){uniforms.reuseEnabled.value=value?1:0}};
}
