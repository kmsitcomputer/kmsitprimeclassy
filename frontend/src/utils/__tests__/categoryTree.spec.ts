import { describe, expect, it } from 'vitest'
import { buildCategoryTree, flattenCategoryTree } from '@/utils/categoryTree'

const c = (id: number, name: string, parent_id: number | null, sort_order = 0) => ({ id, name, slug: name.toLowerCase(), parent_id, is_active: true, sort_order })
const names = (rows: { category: { name: string }; depth: number }[]) => rows.map((r) => `${r.depth}:${r.category.name}`)

describe('buildCategoryTree (REM-06)', () => {
  it('nests every child beneath its own parent across several levels, in a deterministic order', () => {
    const rows = flattenCategoryTree(buildCategoryTree([
      c(5, 'Roti Tawar', 2), c(1, 'Kue', null, 1), c(2, 'Roti', null, 0), c(3, 'Kue Kering', 1), c(4, 'Nastar', 3), c(6, 'Brownies', 1, 5), c(7, 'Donat', 1, 5),
    ]))
    expect(names(rows)).toEqual(['0:Roti', '1:Roti Tawar', '0:Kue', '1:Kue Kering', '2:Nastar', '1:Brownies', '1:Donat'])
  })

  it('is stable regardless of input order', () => {
    const base = [c(1, 'A', null), c(2, 'B', 1), c(3, 'C', 1), c(4, 'D', 2)]
    const a = names(flattenCategoryTree(buildCategoryTree(base)))
    const b = names(flattenCategoryTree(buildCategoryTree([...base].reverse())))
    expect(a).toEqual(b)
  })

  it('never promotes an orphan (missing/inactive parent) to a root and never duplicates a node', () => {
    const tree = buildCategoryTree([c(1, 'Kue', null), c(2, 'Yatim', 99), c(3, 'Anak Yatim', 2)])
    expect(names(flattenCategoryTree(tree))).toEqual(['0:Kue'])
  })

  it('survives corrupt cyclic data without looping and renders each node at most once', () => {
    const rows = flattenCategoryTree(buildCategoryTree([c(1, 'Root', null), c(2, 'X', 3), c(3, 'Y', 2), c(4, 'Self', 4)]))
    expect(names(rows)).toEqual(['0:Root'])
    const dup = flattenCategoryTree(buildCategoryTree([c(1, 'Root', null), c(2, 'Child', 1), c(2, 'Child', 1)]))
    expect(dup.filter((r) => r.category.id === 2)).toHaveLength(1)
  })
})
