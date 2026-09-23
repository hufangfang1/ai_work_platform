"""Apply a CPU-only initialization fix for memory-mapped VGGT loading."""
from pathlib import Path
root=Path(__file__).resolve().parents[2]
p=root/'artifacts/neural/vggt/vggt/layers/vision_transformer.py'
s=p.read_text();old='torch.linspace(0, drop_path_rate, depth)';new='torch.linspace(0, drop_path_rate, depth, device="cpu")'
assert old in s or new in s
p.write_text(s.replace(old,new))
print('Meta initialization supported; inference equations unchanged')
