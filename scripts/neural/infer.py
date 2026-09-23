"""VGGT local MPS feasibility run. Predictions are not a trained Gaussian scene."""
import os
os.environ.setdefault('PYTORCH_ENABLE_MPS_FALLBACK','1')
import sys,json,time,gc,argparse
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'artifacts/neural/vggt'))
import numpy as np
from PIL import Image
import torch
from vggt.models.vggt import VGGT
from vggt.utils.pose_enc import pose_encoding_to_extri_intri
from vggt.utils.geometry import unproject_depth_map_to_point_map
p=argparse.ArgumentParser();p.add_argument('--group',default='main',choices=['main','west','yard','all']);p.add_argument('--width',type=int,default=518);args=p.parse_args()
groups={
 'main':['01-house.jpg','references/main-entry-2015.jpg','references/passage-2015.jpg'],
 'west':['references/west-overview-2015.jpg','references/west-facade-2015.jpg','03-gate.jpg','04-summer.jpg'],
 'yard':['02-yard.jpg','references/door-timber-2015.jpg','03-gate.jpg'],
}
groups['all']=list(dict.fromkeys(groups['main']+groups['west']+groups['yard']))
paths=groups[args.group];out=ROOT/'artifacts/neural'/args.group;out.mkdir(exist_ok=True)
w=args.width;h=round(w*.75/14)*14
photos=np.stack([np.array(Image.open(ROOT/'public/home-photos'/name).convert('RGB').resize((w,h),Image.Resampling.LANCZOS)) for name in paths])
print('DEVICE MPS',torch.backends.mps.is_available(),'INPUT',photos.shape,flush=True)
start=time.time();torch.set_num_threads(4)
# Mmap the checkpoint to avoid holding a second complete weight copy in RAM.
with torch.device('meta'):model=VGGT(enable_point=False,enable_track=False)
state=torch.load(ROOT/'artifacts/neural/model.pt',map_location='cpu',mmap=True,weights_only=True)
state={k:v for k,v in state.items() if not k.startswith(('point_head.','track_head.'))}
model.load_state_dict(state,assign=True);del state
model.eval();model.aggregator.to(device='mps',dtype=torch.float16)
model.camera_head.to(device='mps',dtype=torch.float32);model.depth_head.to(device='mps',dtype=torch.float32)
gc.collect();torch.mps.empty_cache();print('LOADED',round(time.time()-start,1),flush=True)
images=torch.from_numpy(photos.copy()).permute(0,3,1,2).unsqueeze(0).to('mps',dtype=torch.float16)/255
with torch.inference_mode():
 tokens,patch_start=model.aggregator(images)
 print('AGGREGATED',round(time.time()-start,1),flush=True)
 tokens=[t.float() if t is not None else None for t in tokens]
 pose=model.camera_head(tokens)[-1]
 depth,conf=model.depth_head(tokens,images.float(),patch_start,frames_chunk_size=1)
 extr,intr=pose_encoding_to_extri_intri(pose.cpu(),(h,w))
 depth=depth[0].cpu().numpy();conf=conf[0].cpu().numpy();extr=extr[0].numpy();intr=intr[0].numpy()
points=unproject_depth_map_to_point_map(depth,extr,intr)
np.savez_compressed(out/'prediction.npz',depth=depth,confidence=conf,extrinsics=extr,intrinsics=intr,points=points,colors=photos)
summary={'group':args.group,'photos':paths,'width':w,'height':h,'seconds':round(time.time()-start,2),'method':'VGGT-1B camera and depth prediction; no Gaussian optimization','finite_points':int(np.isfinite(points).all(-1).sum()),'confidence_quantiles':np.quantile(conf,[.1,.5,.9]).tolist(),'depth_quantiles':np.quantile(depth,[.01,.5,.99]).tolist()}
(out/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2));print(json.dumps(summary,ensure_ascii=False),flush=True)
