const fs = require('fs');
const path = require('path');

const root = process.argv[2] || 'artifacts';
const explicitSecrets = [
  process.env.QA_LOGIST_SESSION_ID,
  process.env.QA_OWNER_SESSION_ID,
  process.env.QA_LOGIST_CSRF_TOKEN,
].filter(Boolean);

const textExt = new Set(['.html', '.htm', '.txt', '.json', '.log']);

function redact(text) {
  let out = text;
  for (const secret of explicitSecrets) out = out.split(secret).join('[REDACTED]');
  out = out.replace(/(ERPV2SESSID=)(?!\[REDACTED\])[^;\s"'<]+/gi, '$1[REDACTED]');
  out = out.replace(/(<meta\b[^>]*\bname=["']csrf-token["'][^>]*\bcontent=["'])[^"']*/gi, '$1[REDACTED]');
  out = out.replace(/(<input\b[^>]*\bname=["']_csrf_token["'][^>]*\bvalue=["'])[^"']*/gi, '$1[REDACTED]');
  out = out.replace(/([?&]apikey=)(?!\[REDACTED\])[^&"'\s<]+/gi, '$1[REDACTED]');
  return out;
}

function walk(dir) {
  if (!fs.existsSync(dir)) return [];
  const out = [];
  for (const entry of fs.readdirSync(dir, {withFileTypes: true})) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...walk(full));
    else if (entry.isFile()) out.push(full);
  }
  return out;
}

fs.mkdirSync(root, {recursive: true});
const files = walk(root).filter(file => textExt.has(path.extname(file).toLowerCase()));
for (const file of files) {
  const before = fs.readFileSync(file, 'utf8');
  const after = redact(before);
  if (after !== before) fs.writeFileSync(file, after);
}

for (const file of files) {
  const text = fs.readFileSync(file, 'utf8');
  for (const secret of explicitSecrets) if (text.includes(secret)) throw new Error(`sensitive QA value remains in ${file}`);
  if (/(ERPV2SESSID=)(?!\[REDACTED\])[^;\s"'<]+/i.test(text)) throw new Error(`raw ERP session cookie remains in ${file}`);
  if (/([?&]apikey=)(?!\[REDACTED\])[^&"'\s<]+/i.test(text)) throw new Error(`raw Yandex API key remains in ${file}`);
}

console.log(`FEO_MAP_STAGE1_ARTIFACT_SANITIZE=PASS files=${files.length}`);
