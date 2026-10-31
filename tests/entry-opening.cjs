const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const assert = require('node:assert/strict');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'sdc-opening-'));
const web = path.join(tmp, 'www'), priv = path.join(tmp, 'private-sdc-entry');
fs.mkdirSync(path.join(web, 'en', 'entry'), {recursive:true});
fs.mkdirSync(priv);
// Freeze only this isolated fixture; the production code has no clock override.
fs.writeFileSync(path.join(web, 'en', 'entry', 'lib.php'), fs.readFileSync('en/entry/lib.php', 'utf8').replace("new DateTimeImmutable('now',", "new DateTimeImmutable('2026-11-01 00:00:00',"));
const cfg = path.join(priv, 'config.php');
fs.writeFileSync(cfg, `<?php return ['enabled'=>false,'storage_dir'=>'${priv.replaceAll('\\','/')}','notification_sender'=>'test@example.invalid'];`);
const php = process.env.PHP_BIN || path.resolve('.implementation/php-runtime/php.exe');
function run(receipt = '') {
  const r = spawnSync(php, ['-n','-d','extension_dir='+path.join(path.dirname(php),'ext'),'-d','extension=pdo_sqlite','tools/open-overseas-entry.php',web,receipt], {encoding:'utf8'});
  assert.equal(r.status, 0, String(r.error || r.stderr || r.stdout));
  return r.stdout;
}
assert.match(run(), /enabled=true/);
assert(fs.existsSync(cfg+'.before-20261101-opening'));
assert.match(run(), /enabled=true/);
fs.writeFileSync(cfg, fs.readFileSync(cfg,'utf8').replace("'enabled' => true", "'enabled' => false"));
assert.match(run(), /enabled=false/);
console.log('PASS: opening migration, private backup, repeat deployment, later administrator closure preserved');
