const { spawnSync } = require('node:child_process');
const fs = require('node:fs');

const php = fs.existsSync('C:\\xampp\\php\\php.exe') ? 'C:\\xampp\\php\\php.exe' : 'php';
const result = spawnSync(php, ['-l', 'application/config.php'], { stdio: 'inherit' });
if (result.error) {
  console.error(result.error.message);
  process.exit(1);
}
process.exit(result.status ?? 1);
