import * as T from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
import {SparkRenderer,SplatMesh} from '@sparkjsdev/spark';
const $=s=>document.querySelector(s),view=$('#view');
const renderer=new T.WebGLRenderer({antialias:false});renderer.setPixelRatio(Math.min(devicePixelRatio,1.5));view.append(renderer.domElement);
const scene=new T.Scene();scene.background=new T.Color('#252b30');
const camera=new T.PerspectiveCamera(60,1,.001,100);let controls=new OrbitControls(camera,renderer.domElement);controls.enableDamping=true;
const spark=new SparkRenderer({renderer});scene.add(spark);let center=new T.Vector3(0,0,-.5),radius=1,meta,splatMesh,generation=0;
function resize(){const r=view.getBoundingClientRect();renderer.setSize(r.width,r.height);camera.aspect=r.width/r.height;camera.updateProjectionMatrix()}resize();addEventListener('resize',resize);
function resetControls(target){controls.dispose();controls=new OrbitControls(camera,renderer.domElement);controls.enableDamping=true;controls.target.copy(target);controls.update()}
function photo(i){const c=meta.cameras[i],r=c.rotation,q=new T.Quaternion().setFromRotationMatrix(new T.Matrix4().set(...r[0],0,...r[1],0,...r[2],0,0,0,0,1));camera.position.set(...c.position);camera.up.set(0,1,0).applyQuaternion(q);camera.quaternion.copy(q);camera.fov=c.fov;const target=camera.position.clone().add(new T.Vector3(0,0,-c.targetDistance).applyQuaternion(q));camera.updateProjectionMatrix();resetControls(target)}
function overview(){camera.up.set(0,1,0);camera.fov=60;camera.position.copy(center).add(new T.Vector3(.32,.18,1).normalize().multiplyScalar(radius/Math.min(1,camera.aspect)));camera.updateProjectionMatrix();resetControls(center)}
$('#overview').onclick=overview;$('#camera').onchange=()=>photo(Number($('#camera').value));
const keys=new Set();addEventListener('keydown',e=>{if(e.target.closest('input,select,button'))return;if('wasdqe'.includes(e.key.toLowerCase())){keys.add(e.key.toLowerCase());e.preventDefault()}});addEventListener('keyup',e=>keys.delete(e.key.toLowerCase()));addEventListener('blur',()=>keys.clear());let previous=performance.now();
renderer.setAnimationLoop(now=>{const dt=Math.min((now-previous)/1000,.05);previous=now;if(keys.size){const direction=new T.Vector3(Number(keys.has('d'))-Number(keys.has('a')),Number(keys.has('e'))-Number(keys.has('q')),Number(keys.has('s'))-Number(keys.has('w'))).normalize().applyQuaternion(camera.quaternion).multiplyScalar(radius*.15*dt);camera.position.add(direction);controls.target.add(direction)}controls.update();renderer.render(scene,camera)});
async function json(url){const r=await fetch(url);if(!r.ok)throw Error(`结果尚未生成：${url}`);return r.json()}
async function load(){
 const token=++generation,group=$('#group').value;$('#status').textContent='正在载入训练结果…';$('#error').textContent='';
try{
 const [run,groups]=await Promise.all([json('/gaussian-results/'+group+'.json'),json('/neural-results/manifest.json')]);if(token!==generation)return;meta=groups.find(x=>x.group===group);$('#camera').replaceChildren();
 meta.photos.forEach((name,i)=>{const o=document.createElement('option');o.value=i;o.textContent=`${i+1} · ${name.split('/').at(-1)}`;$('#camera').append(o)});
 const mesh=new SplatMesh({url:'/gaussian-results/'+run.ply});mesh.rotation.x=Math.PI;await mesh.initialized;if(token!==generation){mesh.dispose();return}if(splatMesh){scene.remove(splatMesh);splatMesh.dispose()}splatMesh=mesh;scene.add(mesh);
 const points=[];mesh.forEachSplat((index,p,scales,quaternion,opacity)=>{if(opacity>.15)points.push([p.x,-p.y,-p.z])});if(!points.length)throw Error('高斯文件没有可见的点');const axis=[0,1,2].map(k=>points.map(p=>p[k]).sort((a,b)=>a-b));const lo=new T.Vector3(...axis.map(a=>a[Math.floor(a.length*.02)])),hi=new T.Vector3(...axis.map(a=>a[Math.floor(a.length*.98)]));center.copy(lo).add(hi).multiplyScalar(.5);radius=lo.distanceTo(hi);overview();
 $('#status').textContent=`已训练 ${run.steps.toLocaleString()} 次\n${run.splats.toLocaleString()} 个高斯 · ${run.photos.length} 张照片\n本机实际优化，未补造缺失照片。`;
}catch(e){if(token===generation){$('#error').textContent=e.message;$('#status').textContent='暂时无法读取高斯结果'}}
}
$('#group').onchange=load;await load();
