"""Local, masked 3D Gaussian optimization. Training metrics are not novel-view accuracy."""
import os
os.environ.setdefault('DEVELOPER_DIR','/Library/Developer/CommandLineTools')
os.environ.setdefault('CXX','/Library/Developer/CommandLineTools/usr/bin/clang++')
os.environ.setdefault('SDKROOT','/Library/Developer/CommandLineTools/SDKs/MacOSX.sdk')
os.environ.setdefault('CPLUS_INCLUDE_PATH','/Library/Developer/CommandLineTools/SDKs/MacOSX.sdk/usr/include/c++/v1')
import argparse,json,time,sys
from pathlib import Path
import numpy as np
import torch
from scipy.spatial import cKDTree
from PIL import Image
from metal_gauss import render
from metal_gauss.io import Splats,save_ply
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/sfm'));from masks import EXCLUDE
parser=argparse.ArgumentParser();parser.add_argument('--group',default='main');parser.add_argument('--steps',type=int,default=1200);parser.add_argument('--width',type=int,default=518);parser.add_argument('--budget',type=int,default=40000);parser.add_argument('--confidence-mask',action='store_true');args=parser.parse_args()
base=ROOT/'artifacts/neural'/args.group;a=np.load(base/'prediction.npz');meta=json.loads((base/'summary.json').read_text());photos=meta['photos'];n,h,w=a['colors'].shape[:3]
torch.manual_seed(923);torch.set_num_threads(4);rng=np.random.default_rng(923);device='mps'
out=ROOT/'public/gaussian-results';out.mkdir(exist_ok=True)
valid=np.isfinite(a['depth'][...,0])&(a['depth'][...,0]>0)
for i,name in enumerate(photos):
 for x0,y0,x1,y1 in EXCLUDE.get(Path(name).name,[]):valid[i,int(y0*h):int(y1*h),int(x0*w):int(x1*w)]=False
 valid[i,int(.88*h):,int(.76*w):]=False
if args.confidence_mask:
 valid &= a['confidence']>1.01
 yy,xx=np.mgrid[:h,:w];xx=xx/w;yy=yy/h
 for i,name in enumerate(photos):
  if name.endswith('west-overview-2015.jpg'):valid[i]&=yy>(.245+.065*xx)
  if name.endswith('west-facade-2015.jpg'):valid[i]&=yy>np.maximum(0,.48-.58*xx)
seed=valid&(a['confidence']>1.08)&(a['depth'][...,0]<np.quantile(a['depth'],.98))
pts=a['points'][seed];rgb=a['colors'][seed]/255.
sel=rng.choice(len(pts),min(args.budget,len(pts)),replace=False);pts=pts[sel];rgb=rgb[sel];count=len(pts)
sizes=cKDTree(pts).query(pts,k=4)[0][:,1:].mean(1);sizes=np.clip(sizes,.0003,np.quantile(sizes,.9))
def tensor(x):return torch.tensor(x,dtype=torch.float32,device=device)
p={'means':tensor(pts).requires_grad_(),'quats':tensor(np.tile([1,0,0,0],(count,1))).requires_grad_(),'scales':tensor(np.log(np.repeat(sizes[:,None],3,1))).requires_grad_(),'opacity':tensor(np.full(count,-.5)).requires_grad_(),'sh':tensor(((rgb-.5)/.28209479177387814)[:,None,:]).requires_grad_()}
initial=p['means'].detach().clone();initial_scales=p['scales'].detach().clone()
opt=torch.optim.Adam([{'params':[p[k]],'lr':lr} for k,lr in [('means',.00006),('quats',.001),('scales',.002),('opacity',.015),('sh',.012)]],eps=1e-8)
original_h,original_w=h,w;w=args.width;h=round(original_h*w/original_w)
photos_rgb=np.stack([np.array(Image.open(ROOT/'public/home-photos'/name).convert('RGB').resize((w,h),Image.Resampling.LANCZOS)) for name in photos])
images=tensor(photos_rgb/255.);masks=torch.nn.functional.interpolate(tensor(valid)[:,None],size=(h,w),mode='nearest').permute(0,2,3,1);views=[];intr=[]
for i in range(n):
 v=np.eye(4);v[:3]=a['extrinsics'][i];views.append(tensor(v));k=a['intrinsics'][i].copy();k[0]*=w/original_w;k[1]*=h/original_h;intr.append(torch.tensor(k,dtype=torch.float32))
def frame(i):
 return render(p['means'],torch.nn.functional.normalize(p['quats'],dim=-1),p['scales'].exp(),p['opacity'].sigmoid(),p['sh'],intr[i],views[i],w,h,sh_degree=0,backend='metal',near=.001,background=(.145,.169,.188))[0]
def measure(tag):
 errors=[]
 with torch.no_grad():
  for i in range(n):
   image=frame(i);err=(((image-images[i])**2)*masks[i]).sum()/(masks[i].sum()*3);errors.append(float(-10*torch.log10(err)))
   Image.fromarray((image.clamp(0,1).cpu().numpy()*255).astype(np.uint8)).save(out/f'{args.group}-{tag}-{i}.jpg')
 return errors
start=time.time();print('INITIALIZING',count,flush=True);before=measure('before');print('BEFORE',before,flush=True)
for step in range(args.steps):
 i=step%n;opt.zero_grad(set_to_none=True);image=frame(i)
 loss=((image-images[i]).abs()*masks[i]).sum()/(masks[i].sum()*3)
 # Keep the sparse-view optimization close to the depth initialization.
 loss=loss+2*((p['means']-initial)**2).mean()+.002*((p['scales']-initial_scales)**2).mean()
 if not torch.isfinite(loss):raise RuntimeError('Non-finite optimization loss')
 loss.backward();torch.nn.utils.clip_grad_norm_(list(p.values()),1);opt.step()
 with torch.no_grad():p['scales'].clamp_(-10,-2.7);p['opacity'].clamp_(-8,8)
 if (step+1)%100==0:print('STEP',step+1,'LOSS',round(float(loss),5),'SECONDS',round(time.time()-start,1),flush=True)
after=measure('after')
with torch.no_grad():
 splats=Splats(p['means'],torch.nn.functional.normalize(p['quats'],dim=-1),p['scales'].exp(),p['opacity'].sigmoid(),p['sh'],0);save_ply(splats,out/f'{args.group}.ply')
result={'confidence_mask':args.confidence_mask,'width':w,'height':h,'group':args.group,'steps':args.steps,'splats':count,'seconds':round(time.time()-start,1),'training_psnr_before':before,'training_psnr_after':after,'photos':photos,'camera_source':'VGGT estimated, fixed during splat training','evaluation':'Masked training-view PSNR only, NOT held-out novel-view quality','backend':'metal-gauss 0.2.1, native MPS/Metal','ply':f'{args.group}.ply'}
(out/f'{args.group}.json').write_text(json.dumps(result,indent=2));print(json.dumps(result),flush=True)
