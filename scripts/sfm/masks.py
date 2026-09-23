"""Conservative static-scene masks for the feasibility rerun.
Normalized exclusion rectangles are manually estimated from the ten originals.
Masks are analysis inputs only; no original photograph is edited.
"""
from PIL import Image, ImageDraw
EXCLUDE={
 '01-house.jpg':[(.75,.36,.87,.57)],
 '02-yard.jpg':[(.12,.38,.88,.79)],
 '03-gate.jpg':[(0,.025,.45,.77),(.535,.32,.585,.44)],
 '04-summer.jpg':[(0,.38,.48,.90),(.57,.44,.81,.90),(.58,.07,1,.37)],
 '05-bird.jpg':[(.40,.30,.48,.49)],
 'door-timber-2015.jpg':[(.62,.55,.74,.86),(.16,.40,.41,.77),(.41,.38,.74,.78)],
 'main-entry-2015.jpg':[(0,.32,.16,1),(.39,.245,.62,1),(.49,.70,.80,1)],
 'passage-2015.jpg':[(.61,.675,.72,.99),(.67,.04,1,1)],
 'west-facade-2015.jpg':[(.75,.66,1,1)],
 'west-overview-2015.jpg':[(.335,.60,.43,.925),(.405,.40,.80,1)],
}
def make_masks(images,out):
 out.mkdir(exist_ok=True,parents=True)
 for src in images.glob('*.jpg'):
  w,h=Image.open(src).size;mask=Image.new('L',(w,h),255);draw=ImageDraw.Draw(mask)
  for x0,y0,x1,y1 in EXCLUDE.get(src.name,[]):draw.rectangle((int(x0*w),int(y0*h),int(x1*w),int(y1*h)),fill=0)
  # Burned-in date text produces false cross-image matches at the same pixels.
  draw.rectangle((int(w*.76),int(h*.88),w,h),fill=0)
  mask.save(out/(src.name+'.png'))
 return out
