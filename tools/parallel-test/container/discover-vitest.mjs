// Owner execution only. Collect definitions/datasets (including skipped tests), never run test bodies.
import { createVitest } from 'vitest/node'
import { writeFile } from 'node:fs/promises'
const [output, ...files] = process.argv.slice(1)
const context = await createVitest({ watch: false, run: true, reporters: [], allowOnly: false })
try {
  const { testModules, unhandledErrors } = await context.collect(files, { staticParse: false })
  if (unhandledErrors.length || !testModules.length) throw new Error('Vitest collection failed or empty')
  const entries = []
  function checkSuite(suite) {
    if (suite.errors().length) throw new Error('Vitest suite collection failed')
    for (const child of suite.children) if (child.type === 'suite') checkSuite(child)
  }
  for (const module of testModules) {
    checkSuite(module)
    for (const test of module.children.allTests()) {
      entries.push({ file: test.module.moduleId, name: test.fullName })
    }
  }
  if (!entries.length || process.exitCode) throw new Error('Vitest collection incomplete')
  await writeFile(output, JSON.stringify(entries))
} finally {
  await context.close()
}
