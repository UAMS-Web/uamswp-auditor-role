#!/usr/bin/env node
// Write the checksum list for a release: one SHA-256 line per file the
// download keep-list ships, in the form `shasum -a 256 -c` reads.
//
// Paths are relative to mu-plugins/, so the list is checked from there after
// a copy. The loader stub is listed twice: inside the plugin folder, where the
// ZIP holds it, and at the top level of mu-plugins/, where it is copied to.
//
// Usage: node scripts/checksums.mjs [ref] [--version <version>]
//   ref        the commit-ish to archive (default HEAD)
//   --version  the version in the file name (default: the Version header of the main file)

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const slug = 'uamswp-auditor-role';
const loader = `${slug}-loader.php`;

const args = process.argv.slice(2);
const versionFlag = args.indexOf('--version');
const version = versionFlag >= 0 ? args.splice(versionFlag, 2)[1] : null;
const ref = args[0] ?? 'HEAD';

function headerVersion() {
  const source = readFileSync(join(root, `${slug}.php`), 'utf8');
  const match = source.match(/^\s*\*\s*Version:\s*(\S+)/m);

  if (!match) {
    throw new Error(`No Version header in ${slug}.php`);
  }

  return match[1];
}

function files(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);

    return statSync(path).isDirectory() ? files(path) : [path];
  });
}

function sha256(path) {
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

const work = mkdtempSync(join(tmpdir(), `${slug}-checksums-`));

try {
  const tar = join(work, 'archive.tar');

  execFileSync('git', ['-C', root, 'archive', '--worktree-attributes', '--format=tar', `--prefix=${slug}/`, '-o', tar, ref]);
  execFileSync('tar', ['-xf', tar, '-C', work]);

  const lines = files(join(work, slug))
    .map((path) => `${sha256(path)}  ${relative(work, path).split(sep).join('/')}`)
    .sort((a, b) => a.slice(66).localeCompare(b.slice(66)));

  const stub = join(work, slug, 'loader', loader);
  lines.push(`${sha256(stub)}  ${loader}`);

  const out = join(root, 'build');
  mkdirSync(out, { recursive: true });

  const file = join(out, `${slug}-${version ?? headerVersion()}.sha256`);
  writeFileSync(file, `${lines.join('\n')}\n`);

  console.log(`Wrote ${file} (${lines.length} files)`);
} finally {
  rmSync(work, { recursive: true, force: true });
}
