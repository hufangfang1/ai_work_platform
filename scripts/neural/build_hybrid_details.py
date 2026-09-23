"""Constrain trained Gaussian facade details to the corrected house plane.
Uses learned Gaussian colours/opacities and projected footprints, not a photo overlay.
"""
import json
from pathlib import Path
import numpy as np
from scipy.spatial.transform import Rotation
from plyfile import PlyData,PlyElement
ROOT=Path(__file__).resolve().parents[2]
fit=json.loads((ROOT/'src/scene/photoCameraFit.json').read_text());marks=fit['landmarks']
opening=json.loads((ROOT/'src/scene/neuralOpeningFit.json').read_text());offset=opening.get('windowOffsetX',0)
# Fit the original facade, then warp only the west window region. Keep the
# doorway and east side fixed instead of stretching all trained detail globally.
A=[];b=[]
for m in marks:
 u,v=np.array(m['photo'])/[1440,1080];x,y=m['local'][:2]
 if m['name'].startswith('window '):x-=offset
 A.extend([[u,v,1,0,0,0,-x*u,-x*v],[0,0,0,u,v,1,-y*u,-y*v]]);b.extend([x,y])
H=np.r_[np.linalg.lstsq(A,b,rcond=None)[0],1].reshape(3,3)
a=np.load(ROOT/'artifacts/neural/main/prediction.npz');E=a['extrinsics'][0];K=a['intrinsics'][0];h,w=a['depth'].shape[1:3]
raw=PlyData.read(ROOT/'public/gaussian-results/main.ply')['vertex'].data
P=np.stack([raw[k] for k in ['x','y','z']],1);cam=P@E[:,:3].T+E[:,3];q=cam@K.T;uv=q[:,:2]/q[:,2:]
def project(p):
 c=p@E[:,:3].T+E[:,3];q=c@K.T;u=q[:,:2]/q[:,2:]/[w,h];v=np.c_[u,np.ones(len(u))]@H.T;xy=v[:,:2]/v[:,2:]
 left=opening['windowX']-offset+opening['windowWidth']/2
 right=opening['doorCenterX']-opening['openingWidth']/2
 xy[:,0]+=offset*np.clip((right-xy[:,0])/(right-left),0,1)
 return xy
xy=project(P);x,y=xy.T;u=np.clip(uv[:,0].astype(int),0,w-1);v=np.clip(uv[:,1].astype(int),0,h-1)
valid=(cam[:,2]>0)&(uv[:,0]>0)&(uv[:,0]<w-1)&(uv[:,1]>0)&(uv[:,1]<h-1)
valid&=np.abs(cam[:,2]-a['depth'][0,v,u,0])/a['depth'][0,v,u,0]<.045
valid&=(x>-5.7+offset)&(x<3.8)&(y>.77)&(y<4.5)
# Model windows/door retain their real openings and joinery. Exclude their borders.
opening=json.loads((ROOT/'src/scene/neuralOpeningFit.json').read_text())
wx,ww=opening['windowX'],opening['windowWidth'];dx,dw=opening['doorCenterX'],opening['openingWidth']
for left,right,bottom,top in [(wx-ww/2-.10,wx+ww/2+.10,opening['windowSill']-.12,opening['windowTop']+.10),(dx-dw/2-.08,dx+dw/2+.08,0,3.9),(-2.55+offset,-1.83+offset,1.45,3.15)]:
 valid&=~((x>left)&(x<right)&(y>bottom)&(y<top))
dc=np.stack([raw[f'f_dc_{k}'] for k in range(3)],1)*.28209479177387814+.5
observed=a['colors'][0,v,u]/255.
valid&=(np.abs(dc-observed).mean(1)<.13)&(dc.min(1)>=0)&(dc.max(1)<=1)
ids=np.flatnonzero(valid);p=P[ids];xy=xy[ids];data=raw[ids].copy();n=len(ids)
# Propagate trained ellipsoid covariance through the calibrated projection.
J=np.stack([(project(p+np.eye(3)[k]*1e-5)-project(p-np.eye(3)[k]*1e-5))/2e-5 for k in range(3)],axis=-1)
quat=np.stack([data[f'rot_{k}'] for k in range(4)],1);R=Rotation.from_quat(quat[:,[1,2,3,0]]).as_matrix();s=np.exp(np.stack([data[f'scale_{k}'] for k in range(3)],1));cov=(R*s[:,None,:]**2)@R.transpose(0,2,1);cov2=J@cov@J.transpose(0,2,1)
eig,V=np.linalg.eigh(cov2);sc=np.clip(np.sqrt(np.maximum(eig,1e-8)),.002,.025)
# Planar ellipsoid axes, with a thin normal extent and small stand-off from plaster.
rot=np.zeros((n,3,3));rot[:,:2,:2]=V;rot[:,2,2]=np.linalg.det(V);qq=Rotation.from_matrix(rot).as_quat()[:,[3,0,1,2]]
colour=dc[ids];colour=np.where(colour<=.04045,colour/12.92,((colour+.055)/1.055)**2.4)
for k in range(3):data[f'f_dc_{k}']=(colour[:,k]-.5)/.28209479177387814
data['x']=xy[:,0];data['y']=xy[:,1];data['z']=.193
for k in range(3):data[f'scale_{k}']=np.log(sc[:,k] if k<2 else np.full(n,.003))
for k in range(4):data[f'rot_{k}']=qq[:,k]
op=1/(1+np.exp(-data['opacity']));edge=np.minimum.reduce([xy[:,0]+5.7-offset,3.8-xy[:,0],xy[:,1]-.77,4.5-xy[:,1]])
op=np.clip(op*.18*np.clip(edge/.25,0,1),.001,.95);data['opacity']=np.log(op/(1-op))
out=ROOT/'public/gaussian-results';PlyData([PlyElement.describe(data,'vertex')]).write(out/'main-facade-details.ply')
res={'splats':n,'source':'main.ply','method':'trained Gaussians projected to corrected facade using photo landmarks; covariance propagated; openings excluded','local_bounds':[xy.min(0).tolist(),xy.max(0).tolist()],'planeZ':.193,'landmark_rms_local':float(np.sqrt(np.mean((np.array([[m['local'][0]-(offset if m['name'].startswith('window ') else 0),m['local'][1]] for m in marks])-np.array([(lambda z:z[:2]/z[2])(H@np.r_[np.array(m['photo'])/[1440,1080],1]) for m in marks]))**2)))}
(out/'hybrid.json').write_text(json.dumps(res,indent=2));print(res)
