from pathlib import Path
path=Path('admin_portal/anc_defaulters.php')
data=path.read_text()
needle='    <?php if (count() === 0): ?>'
print('count', data.count(needle))
start=data.index(needle)
print('start', start)
print('snippet', repr(data[start:start+80]))
