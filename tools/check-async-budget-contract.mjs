import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const php = fs.readFileSync(path.join(root, 'mcp-abilities-check-runner.php'), 'utf8');

for (const [pattern, description] of [
  [/'async_job_terminated'/, 'terminal shutdown receipt'],
  [/max\( 1, min\( 500, \(int\) \$input\['max_results'\]/, 'result details remain bounded'],
  [/Requires Plugins: plugin-check/, 'native Plugin Check dependency declaration'],
  [/requires WordPress core Abilities API support/, 'WordPress 6.9 core Abilities requirement'],
]) {
  if (!pattern.test(php)) throw new Error(`Contract assertion failed: ${description}`);
}

for (const [pattern, description] of [
  [/\$input\[['"](?:timeout|time_limit|execution_time)['"]\]/, 'caller-controlled execution ceiling'],
  [/\bset_time_limit\s*\(/, 'plugin-owned execution time mutation'],
  [/\bini_set\s*\(\s*['"]max_execution_time/, 'plugin-owned PHP execution time mutation'],
]) {
  if (pattern.test(php)) throw new Error(`Forbidden pattern found: ${description}`);
}

console.log(JSON.stringify({ success: true, assertions: 4, forbidden_checks: 3 }));
