#!/usr/bin/env node

/**
 * SpiderNet OS — Migration → TypeScript Type Generator
 * Reads Laravel migration files and generates TypeScript type definitions.
 *
 * Usage: node tools/dev/codegen.js
 * Output: packages/sdk/src/types/generated.ts
 */

import { readFileSync, writeFileSync, readdirSync } from 'fs';
import { join, resolve } from 'path';

const ROOT = resolve(import.meta.dirname, '../..');
const MIGRATIONS_DIR = join(ROOT, 'backend/database/migrations');
const OUTPUT_FILE = join(ROOT, 'packages/sdk/src/types/generated.ts');

// Map Laravel Schema column types to TypeScript types
const TYPE_MAP = {
  'uuid': 'string',
  'string': 'string',
  'text': 'string',
  'longText': 'string',
  'integer': 'number',
  'bigInteger': 'number',
  'bigIncrements': 'number',
  'float': 'number',
  'decimal': 'string', // Decimals come as strings from DB
  'boolean': 'boolean',
  'jsonb': 'Record<string, unknown>',
  'json': 'Record<string, unknown>',
  'timestamp': 'string | null',
  'date': 'string',
  'vector': 'number[]',
};

/**
 * Parse a Laravel migration file and extract table/column definitions.
 */
function parseMigration(filePath) {
  const content = readFileSync(filePath, 'utf-8');
  const tables = [];

  // Match Schema::create blocks
  const createRegex = /Schema::create\(['"](\w+)['"],\s*function\s*\(Blueprint\s*\$table\)\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/g;
  let match;

  while ((match = createRegex.exec(content)) !== null) {
    const tableName = match[1];
    const body = match[2];
    const columns = [];

    // Match column definitions
    const colRegex = /\$table->(\w+)\(['"](\w+)['"](?:,\s*(\d+))?\)([^;]*);/g;
    let colMatch;

    while ((colMatch = colRegex.exec(body)) !== null) {
      const colType = colMatch[1];
      const colName = colMatch[2];
      const colSize = colMatch[3] || null;
      const modifiers = colMatch[4] || '';

      const nullable = modifiers.includes('nullable');
      const hasDefault = modifiers.includes('default');

      // Skip index-only definitions
      if (['index', 'unique', 'primary', 'foreign'].includes(colType)) continue;

      let tsType = TYPE_MAP[colType] || 'unknown';

      // Handle vector type with dimension
      if (colType === 'vector' && colSize) {
        tsType = 'number[]';
      }

      columns.push({
        name: colName,
        type: tsType,
        nullable: nullable || tsType === 'string | null',
        hasDefault,
        laravelType: colType,
        size: colSize,
      });
    }

    // Also capture timestamp pairs
    if (body.includes('$table->timestamps()')) {
      columns.push({ name: 'created_at', type: 'string | null', nullable: true, hasDefault: false, laravelType: 'timestamp' });
      columns.push({ name: 'updated_at', type: 'string | null', nullable: true, hasDefault: false, laravelType: 'timestamp' });
    }

    if (columns.length > 0) {
      tables.push({ name: tableName, columns });
    }
  }

  return tables;
}

/**
 * Convert snake_case table name to PascalCase interface name
 */
function toPascalCase(str) {
  return str
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join('');
}

/**
 * Generate TypeScript interface from table definition
 */
function generateInterface(table) {
  const name = toPascalCase(table.name);
  const lines = [`export interface ${name}Row {`];

  for (const col of table.columns) {
    const optionalMark = col.nullable && col.type !== 'string | null' ? ' | null' : '';
    lines.push(`  ${col.name}: ${col.type}${optionalMark};`);
  }

  lines.push('}');
  return lines.join('\n');
}

// ─── Main ───────────────────────────────────────────────────

function main() {
  console.log('SpiderNet OS — Migration Type Generator');
  console.log(`Reading migrations from: ${MIGRATIONS_DIR}`);

  let migrationFiles;
  try {
    migrationFiles = readdirSync(MIGRATIONS_DIR)
      .filter(f => f.endsWith('.php'))
      .sort();
  } catch (e) {
    console.error(`Error: Cannot read migrations directory: ${MIGRATIONS_DIR}`);
    console.error('Run this from the project root, or ensure backend/database/migrations/ exists.');
    process.exit(1);
  }

  console.log(`Found ${migrationFiles.length} migration files`);

  const allTables = [];
  for (const file of migrationFiles) {
    const tables = parseMigration(join(MIGRATIONS_DIR, file));
    allTables.push(...tables);
    if (tables.length > 0) {
      console.log(`  ${file} → ${tables.map(t => t.name).join(', ')}`);
    }
  }

  // Generate output
  const output = [
    '/**',
    ' * AUTO-GENERATED — Do not edit manually',
    ` * Generated from ${migrationFiles.length} Laravel migrations`,
    ` * Run: npm run codegen`,
    ` * Date: ${new Date().toISOString()}`,
    ' */',
    '',
    ...allTables.map(generateInterface),
  ].join('\n\n');

  writeFileSync(OUTPUT_FILE, output + '\n', 'utf-8');
  console.log(`\nGenerated ${allTables.length} interfaces → ${OUTPUT_FILE}`);
}

main();
