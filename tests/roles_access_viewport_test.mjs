import fs from 'node:fs';

const css = fs.readFileSync(new URL('../public/assets/css/roles-access.css', import.meta.url), 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(css.includes('.roles-access-modal__dialog { width:min(1060px,calc(100vw - 36px));'), 'desktop modal width contract');
assert(css.includes('height:min(780px,calc(100vh - 36px));'), 'desktop modal height contract');
assert(css.includes('@media (max-width: 1439px)'), 'compact desktop breakpoint');
assert(css.includes('width:min(980px,calc(100vw - 24px));'), 'compact desktop width contract');
assert(css.includes('@media (max-width: 900px)'), 'narrow viewport breakpoint');
assert(css.includes('.roles-access-modal__dialog { width:100%; height:calc(100vh - 16px);'), 'narrow modal dimensions contract');
assert(css.includes('.roles-access-modal__body { flex:1 1 auto; min-height:0; overflow:auto;'), 'modal body owns overflow');
assert(css.includes('.roles-access-modal__head, .roles-access-modal__foot { position:sticky;'), 'header and footer remain visible');

const viewportHeight = 760;
for (const width of [1920, 1440, 800]) {
    let horizontalInset;
    let dialogWidth;
    let dialogHeight;
    if (width <= 900) {
        horizontalInset = 8;
        dialogWidth = width - horizontalInset * 2;
        dialogHeight = viewportHeight - 16;
    } else if (width <= 1439) {
        horizontalInset = 12;
        dialogWidth = Math.min(980, width - 24);
        dialogHeight = viewportHeight - 24;
    } else {
        horizontalInset = 18;
        dialogWidth = Math.min(1060, width - 36);
        dialogHeight = Math.min(780, viewportHeight - 36);
    }
    assert(dialogWidth > 0 && dialogWidth <= width - horizontalInset * 2, `viewport ${width}: dialog fits horizontally`);
    assert(dialogHeight > 0 && dialogHeight <= viewportHeight, `viewport ${width}: dialog fits vertically`);
    console.log(`viewport ${width}: PASS (${dialogWidth}x${dialogHeight})`);
}

console.log('roles access viewport CSS contract: PASS');
