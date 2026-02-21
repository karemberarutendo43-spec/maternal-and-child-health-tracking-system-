import re
from pathlib import Path
path = Path('admin_portal/anc_defaulters.php')
data = path.read_text()
pattern = re.compile(r"\s*<\?php if \(count\(\\) === 0\): \?>.*?<\?php endif; \?>", re.S)
match = pattern.search(data)
print('match', bool(match))
if match:
    print('span', match.span())
