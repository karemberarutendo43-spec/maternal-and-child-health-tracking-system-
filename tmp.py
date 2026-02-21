from pathlib import Path
path=Path('admin_portal/anc_defaulters.php')
data=path.read_text()
needle='<?php if (count() === 0): ?>'
print('found count', data.count(needle))
