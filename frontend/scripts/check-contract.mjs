#!/usr/bin/env node
/**
 * The UI and the PHP kit must speak the same wire contract: `contract` in
 * package.json against `Contract::CONTRACT_VERSION` in src/Contract.php.
 * With a tag (`--tag vX.Y.Z`) it also checks package.json's version matches it.
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const pkg = JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8'))
const php = readFileSync(resolve(root, '../src/Contract.php'), 'utf8')
const match = php.match(/CONTRACT_VERSION\s*=\s*'([^']+)'/)

let failed = false
if (!match) {
  console.error('Could not read Contract::CONTRACT_VERSION from src/Contract.php.')
  failed = true
} else if (String(pkg.contract) !== match[1]) {
  console.error(`Contract mismatch: package.json says "${pkg.contract}", src/Contract.php says "${match[1]}".`)
  failed = true
}

const tagIndex = process.argv.indexOf('--tag')
if (tagIndex !== -1) {
  const tag = String(process.argv[tagIndex + 1] || '').replace(/^refs\/tags\//, '').replace(/^v/, '')
  if (tag !== pkg.version) {
    console.error(`Version mismatch: tag says "${tag}", package.json says "${pkg.version}".`)
    failed = true
  }
}

if (failed) process.exit(1)
console.log(`Contract ${pkg.contract} matches the PHP kit${tagIndex !== -1 ? `; version ${pkg.version} matches the tag` : ''}.`)
