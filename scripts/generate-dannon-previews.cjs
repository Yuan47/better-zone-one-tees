// Reproduce the controlled sample illustrations. No database, network or uploaded-photo writes.
// Geometry and colors extend the store's original native-vector sample previews.
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const destination = path.join(root, 'public', 'catalog-previews', 'dannon');
const colors = {
  White: '#FCFCF9', Red: '#CF3843', Mandarin: '#F27B2B', Maroon: '#70263D', Batis: '#2B99B3',
  Teal: '#217E83', 'Light Blue': '#95CBE7', Fatigue: '#697047', Gray: '#92979E',
  'Pool Green': '#47C2A4', Gold: '#DDA628', Yellow: '#F6D437', Peach: '#F6B59D', 'Light Yellow': '#FFF2A5',
};
const styles = {
  'round-neck': {title: 'DANNON - ROUND NECK', kids: false, vneck: false},
  'round-neck-kids': {title: 'DANNON - ROUND NECK KIDS', kids: true, vneck: false},
  'v-neck': {title: 'DANNON - V-NECK', kids: false, vneck: true},
  'v-neck-kids': {title: 'DANNON - V-NECK KIDS', kids: true, vneck: true},
};
const xml = value => String(value).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;'}[c]));
const colorSlug = value => value.toLowerCase().replaceAll(' ', '-');

function garment(hex, kids, vneck) {
  const dark = '#' + hex.slice(1).match(/../g).map(component => Math.round(parseInt(component, 16) * .76).toString(16).padStart(2, '0')).join('');
  const outline = 'M111 54 L77 70 L25 119 L69 162 L95 140 L94 294 Q160 309 226 294 L225 140 L251 162 L295 119 L243 70 L209 54'
    + (vneck ? ' L160 94 L111 54Z' : ' Q160 82 111 54Z');
  const seam = vneck ? 'M112 57 L160 96 L208 57' : 'M112 57 Q160 110 208 57';
  const sizing = kids ? 'translate(15 42) scale(.91 .84)' : '';
  return `<g data-garment="${vneck ? 'v-neck' : 'round-neck'}" data-size-range="${kids ? 'kids' : 'adult'}" transform="translate(96 120) scale(1.65)">
<ellipse cx="160" cy="312" rx="100" ry="9" fill="#14202A" opacity=".10"/>
<g transform="${sizing}"><defs>
<linearGradient id="cloth" x1="0" y1="0" x2="1" y2=".3"><stop offset="0" stop-color="${hex}"/><stop offset=".5" stop-color="${hex}"/><stop offset="1" stop-color="${dark}"/></linearGradient>
<linearGradient id="shine" x1="0" x2="1"><stop offset="0" stop-color="#FFF" stop-opacity=".18"/><stop offset=".55" stop-color="#FFF" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".04"/></linearGradient>
</defs><path d="${outline}" fill="url(#cloth)" stroke="#16232F" stroke-opacity=".20" stroke-width="1.7"/>
<path d="${outline}" fill="url(#shine)"/>
<path d="${seam}" fill="none" stroke="#14202A" stroke-opacity=".19" stroke-width="5"/>
<path d="M95 140 L101 98 M225 140 L219 98 M104 285 Q162 298 216 285" fill="none" stroke="#14202A" stroke-opacity=".15" stroke-width="1.5"/>
<path d="M112 150 Q103 192 108 270 M211 169 Q218 221 211 280" fill="none" stroke="#FFF" stroke-opacity=".13" stroke-width="4"/>
${vneck ? '' : '<rect x="151" y="70" width="17" height="8" rx="1" fill="#EAE7DC" opacity=".72"/>'}
</g></g>`;
}

function illustration(style, color, hex) {
  const title = `${style.title} / ${color}`;
  return `<svg xmlns="http://www.w3.org/2000/svg" width="720" height="820" viewBox="0 0 720 820" role="img" aria-labelledby="title description">
<title id="title">${xml(title)}</title><desc id="description">One ${style.kids ? 'kids' : 'adult'} ${style.vneck ? 'V-neck' : 'round-neck'} shirt in ${xml(color)}. Sample color illustration; the actual garment shade may vary.</desc>
<rect width="720" height="820" fill="#F9FAF9"/><rect x="20" y="20" width="680" height="780" rx="26" fill="#FFF" stroke="#E8ECEA"/>
<g font-family="Arial, DejaVu Sans, sans-serif"><text x="360" y="65" text-anchor="middle" fill="#8F2118" font-size="17" font-weight="700" letter-spacing="3">ZONE ONE TEES</text>
<text x="360" y="104" text-anchor="middle" fill="#26313A" font-size="25" font-weight="600">${xml(style.title)}</text>
<text x="360" y="135" text-anchor="middle" fill="#76818A" font-size="15" letter-spacing="2">${style.kids ? 'KIDS' : 'ADULT'}</text>
${garment(hex, style.kids, style.vneck)}
<rect x="75" y="665" width="570" height="2" fill="#EDF0EE"/><circle cx="255" cy="714" r="14" fill="${hex}" stroke="#849099" stroke-opacity=".6"/>
<text x="285" y="722" fill="#25303A" font-size="28" font-weight="600">${xml(color)}</text>
<text x="360" y="765" text-anchor="middle" fill="#76818A" font-size="14" letter-spacing="1">COLOR PREVIEW · ILLUSTRATION · SHADE MAY VARY</text></g>
</svg>
`;
}

let count = 0;
for (const [name, style] of Object.entries(styles)) {
  const directory = path.join(destination, name);
  fs.mkdirSync(directory, {recursive: true});
  for (const [color, hex] of Object.entries(colors)) {
    const svg = illustration(style, color, hex);
    if (/<script|foreignObject|\shref=|\son[a-z]+\s*=/i.test(svg)) throw new Error('Unexpected active SVG content');
    fs.writeFileSync(path.join(directory, colorSlug(color) + '.svg'), svg, 'utf8');
    count++;
  }
}
console.log(JSON.stringify({generated_svg_files: count, colors: Object.keys(colors).length, styles: Object.keys(styles).length, database_writes: 0}));
