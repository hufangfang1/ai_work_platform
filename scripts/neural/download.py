"""Resume the public checkpoint with validated HTTP byte ranges."""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import requests,time,shutil
ROOT=Path(__file__).resolve().parents[2];folder=ROOT/'artifacts/neural';target=folder/'model.pt';total=5026874952
start=target.stat().st_size
if start>=total:raise SystemExit('Already downloaded')
url='https://huggingface.co/facebook/VGGT-1B/resolve/main/model.pt?download=true'
size=(total-start+3)//4
parts=[(i,start+i*size,min(total-1,start+(i+1)*size-1)) for i in range(4)]
def download(item):
 i,a,b=item;path=folder/f'weights.part{i}';received=path.stat().st_size if path.exists() else 0
 if received==b-a+1:return path
 for attempt in range(4):
  try:
   received=path.stat().st_size if path.exists() else 0
   r=requests.get(url+f'&part={i}',headers={'Range':f'bytes={a+received}-{b}'},stream=True,timeout=60);r.raise_for_status()
   assert r.status_code==206 and r.headers['Content-Range'].startswith(f'bytes {a+received}-{b}/')
   with path.open('ab') as f:
    for chunk in r.iter_content(2**20):f.write(chunk)
   assert path.stat().st_size==b-a+1
   print('finished',i,flush=True);return path
  except Exception as e:
   print('retry',i,str(e),flush=True);time.sleep(2)
 raise RuntimeError('Incomplete range')
(folder/'download-ranges.json').write_text(__import__('json').dumps({'prefix':start,'total':total,'ranges':parts}))
with ThreadPoolExecutor(max_workers=4) as pool:files=list(pool.map(download,parts))
with target.open('ab') as f:
 for path in files:
  with path.open('rb') as src:shutil.copyfileobj(src,f)
assert target.stat().st_size==total
for path in files:path.unlink()
print('CHECKPOINT COMPLETE',total,flush=True)
