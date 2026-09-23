"""Robust depth-guided camera/scale registration, with held-out feature validation.
Does not invent correspondences across disconnected photo components.
"""
import json, sqlite3
from pathlib import Path
import numpy as np
from scipy.ndimage import map_coordinates
from scipy.optimize import least_squares
from scipy.spatial.transform import Rotation
ROOT=Path(__file__).resolve().parents[2]
base=ROOT/'artifacts/neural/all';a=np.load(base/'prediction.npz')
meta=json.loads((base/'summary.json').read_text());names=meta['photos'];n=len(names)
depth=a['depth'][...,0];conf=a['confidence'];E=a['extrinsics'];K=a['intrinsics'];h,w=depth.shape[1:]
db=sqlite3.connect(ROOT/'artifacts/sfm/static-mask/database.db')
lookup={Path(name).name:i for i,name in enumerate(names)}
images={id:(lookup[name],cid) for id,name,cid in db.execute('select image_id,name,camera_id from images') if name in lookup}
keys={}
for id,(i,cid) in images.items():
 rows,cols,data=db.execute('select rows,cols,data from keypoints where image_id=?',(id,)).fetchone()
 cw,ch=db.execute('select width,height from cameras where camera_id=?',(cid,)).fetchone()
 keys[id]=np.frombuffer(data,np.float32).reshape(rows,cols)[:,:2]*[w/cw,h/ch]
centers=np.array([-e[:,:3].T@e[:,3] for e in E]);rot=np.array([e[:,:3].T for e in E]);p0=np.c_[Rotation.from_matrix(rot).as_rotvec(),centers,np.zeros(n)]
edges=[];rng=np.random.default_rng(924)
for pair,rows,data in db.execute('select pair_id,rows,data from two_view_geometries where rows>0'):
 ia,ib=divmod(pair,2147483647)
 if ia not in images or ib not in images:continue
 i,j=images[ia][0],images[ib][0];matches=np.frombuffer(data,np.uint32).reshape(rows,2)
 uv=[keys[id][matches[:,k]] for k,id in enumerate([ia,ib])];xyz=[];valid=np.ones(rows,bool)
 for idx,pixels in zip([i,j],uv):
  z=map_coordinates(depth[idx],pixels[:,::-1].T,order=1,mode='nearest')
  c=map_coordinates(conf[idx],pixels[:,::-1].T,order=1,mode='nearest')
  valid&=(z>0)&(c>1.01)&np.isfinite(z)
  xyz.append((np.c_[pixels,np.ones(rows)]@np.linalg.inv(K[idx]).T)*z[:,None])
 ids=np.flatnonzero(valid);rng.shuffle(ids)
 if len(ids)<15:continue
 test=ids[:max(3,len(ids)//5)];train=ids[len(test):]
 edges.append(dict(i=i,j=j,xyz=xyz,uv=uv,train=train,test=test))
# Anchor each disconnected component independently: no unsupported connection.
parent=list(range(n))
def find(i):
 while parent[i]!=i:i=parent[i]
 return i
for e in edges:parent[find(e['j'])]=find(e['i'])
anchors={min(i for i in range(n) if find(i)==root) for root in {find(i) for i in range(n)}}
free=[i for i in range(n) if i not in anchors];scale=float(np.median(depth))
def unpack(x):
 p=p0.copy();p[free]=x.reshape(-1,7);return p,Rotation.from_rotvec(p[:,:3]).as_matrix()
def worlds(p,r,e,k,ids):
 idx=e['i'] if k==0 else e['j'];return (e['xyz'][k][ids]*np.exp(p[idx,6]))@r[idx].T+p[idx,3:6]
def residual(x):
 p,r=unpack(x);res=[]
 for e in edges:res.extend(((worlds(p,r,e,0,e['train'])-worlds(p,r,e,1,e['train']))/scale).ravel())
 res.extend(((p[free,:6]-p0[free,:6])*.002).ravel());res.extend(p[free,6]*.01)
 return np.array(res)
x0=p0[free].ravel();lo=np.full((len(free),7),-np.inf);hi=-lo;lo[:,6]=-.7;hi[:,6]=.7
fit=least_squares(residual,x0,bounds=(lo.ravel(),hi.ravel()),loss='soft_l1',f_scale=.015,max_nfev=180)
def errors(x,e):
 p,r=unpack(x);out=[]
 for k in [0,1]:
  target=e['j'] if k==0 else e['i'];world=worlds(p,r,e,k,e['test']);cam=(world-p[target,3:6])@r[target];q=cam@K[target].T
  uv=q[:,:2]/np.maximum(q[:,2:],1e-8);out.extend(np.linalg.norm(uv-e['uv'][1-k][e['test']],axis=1))
 return np.array(out)
stats=[]
for e in edges:
 before=errors(x0,e);after=errors(fit.x,e)
 stats.append(dict(photos=[names[e['i']],names[e['j']]],training_matches=len(e['train']),held_out_matches=len(e['test']),before_median_px=float(np.median(before)),after_median_px=float(np.median(after))))
# Only keep components whose aggregate held-out error improves, never select on training error.
p,r=unpack(fit.x);accepted=[]
for root in {find(i) for i in range(n)}:
 component=[i for i in range(n) if find(i)==root];ee=[e for e in edges if e['i'] in component]
 before=np.concatenate([errors(x0,e) for e in ee]) if ee else np.array([0.])
 after=np.concatenate([errors(fit.x,e) for e in ee]) if ee else np.array([0.])
 ok=bool(ee and np.median(after)<.9*np.median(before))
 accepted.append(dict(photos=[names[i] for i in component],accepted=ok,before_median_px=float(np.median(before)),after_median_px=float(np.median(after))))
 if not ok:p[component]=p0[component];r[component]=rot[component]
newE=E.copy();newdepth=a['depth'].copy();pts=a['points'].copy()
for i in range(n):
 s=np.exp(p[i,6]);newE[i,:,:3]=r[i].T;newE[i,:,3]=-r[i].T@p[i,3:6];newdepth[i]*=s
 local=(a['points'][i]-centers[i])@rot[i];pts[i]=(local*s)@r[i].T+p[i,3:6]
out=ROOT/'artifacts/neural/refined';out.mkdir(exist_ok=True)
np.savez_compressed(out/'prediction.npz',depth=newdepth,confidence=conf,extrinsics=newE,intrinsics=K,points=pts,colors=a['colors'])
meta.update(group='refined',method='VGGT depth with robust camera/scale registration; held-out feature validation; disconnected components remain unverified',alignment=dict(components=accepted,pairs=stats,solver_success=bool(fit.success),evaluations=fit.nfev))
(out/'summary.json').write_text(json.dumps(meta,indent=2));print(json.dumps(meta['alignment'],indent=2))
