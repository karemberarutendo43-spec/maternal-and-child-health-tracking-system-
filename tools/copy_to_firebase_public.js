const fs = require('fs');
const path = require('path');

function ensureDir(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function copyFileSafe(from, to) {
  if (!fs.existsSync(from)) {
    console.warn(`[firebase_public] Missing: ${from}`);
    return;
  }
  ensureDir(path.dirname(to));
  fs.copyFileSync(from, to);
}

function copyDirRecursive(fromDir, toDir) {
  if (!fs.existsSync(fromDir)) return;
  ensureDir(toDir);
  for (const entry of fs.readdirSync(fromDir, { withFileTypes: true })) {
    const from = path.join(fromDir, entry.name);
    const to = path.join(toDir, entry.name);
    if (entry.isDirectory()) {
      copyDirRecursive(from, to);
    } else if (entry.isFile()) {
      copyFileSafe(from, to);
    }
  }
}

const root = process.cwd();

copyFileSafe(
  path.join(root, 'assets', 'output.css'),
  path.join(root, 'firebase_public', 'mch_system', 'assets', 'output.css')
);

copyFileSafe(
  path.join(root, 'public', 'sw.js'),
  path.join(root, 'firebase_public', 'mch_system', 'public', 'sw.js')
);
copyFileSafe(
  path.join(root, 'public', 'offline.html'),
  path.join(root, 'firebase_public', 'mch_system', 'public', 'offline.html')
);
copyFileSafe(
  path.join(root, 'public', 'manifest.json'),
  path.join(root, 'firebase_public', 'mch_system', 'public', 'manifest.json')
);

copyDirRecursive(
  path.join(root, 'public', 'assets'),
  path.join(root, 'firebase_public', 'mch_system', 'public', 'assets')
);

console.log('[firebase_public] Prepared static files under firebase_public/mch_system/');

