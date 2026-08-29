// Unit test for the QR-destination-resolving logic used by public/index.php's
// scanner page. Extracts the real `resolveDestination` function straight out
// of the PHP file (instead of re-implementing it) so the test breaks if the
// shipped logic changes.
'use strict';

const fs = require('fs');
const path = require('path');

const SITE_ORIGIN = 'http://127.0.0.1:8098';
const indexPath = path.join(__dirname, '..', 'public', 'index.php');
const source = fs.readFileSync(indexPath, 'utf8');

const match = source.match(/function resolveDestination\(text\) \{[\s\S]*?\n  \}/);
if (!match) {
  console.error('FAIL: could not find resolveDestination() in public/index.php — has it been renamed/moved?');
  process.exit(1);
}

// eslint-disable-next-line no-new-func
const resolveDestination = new Function(
  'window',
  'URL',
  `${match[0]}\n  return resolveDestination;`
)({ location: { href: SITE_ORIGIN + '/', origin: SITE_ORIGIN } }, URL);

let pass = 0;
let fail = 0;

function check(label, input, expected) {
  const actual = resolveDestination(input);
  const ok = actual === expected;
  if (ok) {
    pass++;
    console.log(`PASS  ${label}`);
  } else {
    fail++;
    console.log(`FAIL  ${label}\n      input:    ${JSON.stringify(input)}\n      expected: ${JSON.stringify(expected)}\n      actual:   ${JSON.stringify(actual)}`);
  }
}

// Same-origin absolute URL from a QR code -> accepted as-is.
check(
  'same-origin absolute URL is accepted',
  SITE_ORIGIN + '/tree.php?id=2',
  SITE_ORIGIN + '/tree.php?id=2'
);

// Same-origin pretty URL -> accepted as-is.
check(
  'same-origin pretty /tree/{id} URL is accepted',
  SITE_ORIGIN + '/tree/2',
  SITE_ORIGIN + '/tree/2'
);

// Relative path scanned from a QR (resolves against current page origin) -> accepted.
check(
  'relative path resolves against current origin and is accepted',
  '/tree/3',
  SITE_ORIGIN + '/tree/3'
);

// Cross-origin URL (e.g. a malicious/foreign QR code) -> must be rejected.
check(
  'cross-origin URL is rejected',
  'https://evil.example.com/tree/2',
  null
);

// Plain non-URL text (e.g. a QR code that isn't a link at all) is treated as
// a relative path against the current origin — new URL(text, base) resolves
// it rather than throwing, so it is NOT rejected. Downstream routing (no
// matching route) is what ultimately 404s it, not resolveDestination.
check(
  'non-URL text resolves as a same-origin relative path (not rejected here)',
  'just some text',
  SITE_ORIGIN + '/just%20some%20text'
);

// Scheme-mismatched but same-host URL (still cross-origin per URL.origin) -> rejected.
check(
  'same host but different scheme is rejected',
  'https://127.0.0.1:8098/tree/2',
  null
);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
