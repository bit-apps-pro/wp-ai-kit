#!/usr/bin/env node
/**
 * Collect the English source strings: every `__('…')` / `__("…")` literal under
 * src/, written as a sorted, unique array to i18n/strings.en.json. A host feeds
 * that file to its POT pipeline (the package ships no translations).
 *
 * Usage: node scripts/extract-strings.mjs [--check]
 *   --check  exit 1 when the file is out of date instead of writing it.
 */
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync, existsSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const sourceDir = join(root, 'src')
const outFile = join(root, 'i18n', 'strings.en.json')
const check = process.argv.includes('--check')

function* walk(dir) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) yield* walk(path)
    else if (/\.(ts|tsx)$/.test(name) && !/\.test\.tsx?$/.test(name)) yield path
  }
}

// `__(` followed by one quoted literal: single or double quotes, backslash escapes inside.
const CALL = /\b__\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")\s*[,)]/g

const unescape = text => text.replace(/\\(['"\\nt])/g, (_m, c) => ({ n: '\n', t: '\t' }[c] ?? c))

const strings = new Set()
for (const file of walk(sourceDir)) {
  const source = readFileSync(file, 'utf8')
  for (const match of source.matchAll(CALL)) {
    const literal = match[1] ?? match[2]
    if (literal !== undefined && literal !== '') strings.add(unescape(literal))
  }
}

const sorted = [...strings].sort((a, b) => a.localeCompare(b, 'en'))
const output = `${JSON.stringify(sorted, null, 2)}\n`

if (check) {
  const current = existsSync(outFile) ? readFileSync(outFile, 'utf8') : ''
  if (current !== output) {
    console.error(`i18n/strings.en.json is out of date: run "pnpm i18n:extract".`)
    process.exit(1)
  }
  console.log(`i18n/strings.en.json is up to date (${sorted.length} strings).`)
} else {
  mkdirSync(dirname(outFile), { recursive: true })
  writeFileSync(outFile, output)
  console.log(`Wrote ${sorted.length} strings to i18n/strings.en.json`)
}
