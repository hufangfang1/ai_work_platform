"""Rectify neural.html main-group landmarks into a level facade.
Keep the existing doorway height as scale anchor; no surveyed metre claim.
"""
import json
from pathlib import Path
import numpy as np
R=Path(__file__).resolve().parents[2]
f=json.loads((R/'src/scene/photoCameraFit.json').read_text());a=np.load(R/'artifacts/neural/main/prediction.npz');P=a['points'][0];h,w=P.shape[:2];d={}
for m in f['landmarks']:
 u,v=(np.array(m['photo'])*[w/1440,h/1080]).astype(int);d[m['name']]=np.median(P[v-2:v+3,u-2:u+3],axis=(0,1))
x=d['door head right']-d['door head left'];x/=np.linalg.norm(x)
up=(d['door head left']-d['door foot left']+d['door head right']-d['door foot right'])/2;up-=x*np.dot(x,up);up/=np.linalg.norm(up)
foot=(d['door foot left']+d['door foot right'])/2;head=(d['door head left']+d['door head right'])/2
scale=2.77/np.dot(head-foot,up);origin=(foot+head)/2
xy={k:[1.725+np.dot(p-origin,x)*scale/.8,1.665+np.dot(p-origin,up)*scale] for k,p in d.items()}
width=np.mean([xy['door '+part+' right'][0]-xy['door '+part+' left'][0] for part in ['head','foot']])
window=[xy['window '+part+' '+side] for part in ['sill','rail'] for side in ['left','right']]
windowX=np.mean([p[0] for p in window]);windowW=np.mean([xy['window '+part+' right'][0]-xy['window '+part+' left'][0] for part in ['sill','rail']]);sill=np.mean([xy['window sill '+side][1] for side in ['left','right']]);rail=np.mean([xy['window rail '+side][1] for side in ['left','right']])
span=xy['sidelight sill right'][0]-xy['sidelight sill left'][0]
r={'source':'neural.html: main group, 01-house.jpg, median 5x5 neighbourhood','scaleAnchor':'doorway head 3.05 and porch 0.28 retained as provisional scale','doorCenterX':1.725,'doorWidth':round(width,3),'sideWindowWidth':.5,'sideWindowOffset':round((span-.5)/2,3),'openingWidth':round(span+.09,3),'windowX':round(windowX,3),'windowWidth':round(windowW,3),'windowSill':round(sill,3),'windowRail':round(rail,3),'windowTop':3.715,'windowTopNote':'top cropped in source 01, previous height retained; rightmost window not measured','measuredLandmarks':xy}
# Correct the west window locally; the doorway remains the east-side anchor.
# A global facade translation incorrectly compressed the door-to-shed spacing.
shift=1.10
r['facadeOffsetX']=0.0
r['windowOffsetX']=shift
r['westCornerAnchor']={'photo':[125,350],'modelLocalX':-5.875,'note':'photo-estimated junction, not surveyed; ~1.38 m clear wall to window'}
r['windowX']=round(r['windowX']+shift,3)
for name,point in r['measuredLandmarks'].items():
 if name.startswith('window '):point[0]+=shift
(R/'src/scene/neuralOpeningFit.json').write_text(json.dumps(r,indent=2)+'\n');print(r)
