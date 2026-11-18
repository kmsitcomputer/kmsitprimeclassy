import type { Category } from '@/api/types'

export interface CategoryNode {
  category: Category
  depth: number
  children: CategoryNode[]
}

const byOrder = (a: Category, b: Category) => a.sort_order - b.sort_order || a.name.localeCompare(b.name, 'id') || a.id - b.id

/**
 * REM-06: parents first, every child directly beneath its OWN parent, deeper levels nested under their
 * immediate parent. Deterministic order (sort_order, name, id). Safe by construction:
 *  - a node is rendered at most once (no duplicates, even with corrupt data);
 *  - a cycle can never recurse forever (visited set);
 *  - an ORPHAN (parent missing from the list, e.g. inactive parent) is NOT promoted to a root — it is
 *    simply unreachable, exactly like its hidden parent.
 */
export function buildCategoryTree(categories: Category[]): CategoryNode[] {
  const children = new Map<number | null, Category[]>()
  for (const c of categories) {
    const list = children.get(c.parent_id) ?? []
    list.push(c)
    children.set(c.parent_id, list)
  }
  const visited = new Set<number>()
  const walk = (parent: number | null, depth: number): CategoryNode[] => {
    const out: CategoryNode[] = []
    for (const c of [...(children.get(parent) ?? [])].sort(byOrder)) {
      if (visited.has(c.id)) continue
      visited.add(c.id)
      out.push({ category: c, depth, children: walk(c.id, depth + 1) })
    }
    return out
  }
  return walk(null, 0)
}

/** Depth-first flat list (for selects / filter chips) with the same guarantees. */
export function flattenCategoryTree(nodes: CategoryNode[]): { category: Category; depth: number }[] {
  const rows: { category: Category; depth: number }[] = []
  const visit = (list: CategoryNode[]) => {
    for (const n of list) {
      rows.push({ category: n.category, depth: n.depth })
      visit(n.children)
    }
  }
  visit(nodes)
  return rows
}

/**
 * Root → … → target path for a category slug, taken from the real parent chain (empty when the slug is unknown).
 * Drives the breadcrumb and which ancestors stay open in the sidebar.
 */
export function categoryPath(nodes: CategoryNode[], slug: string): Category[] {
  if (!slug) return []
  const walk = (list: CategoryNode[], trail: Category[]): Category[] | null => {
    for (const n of list) {
      const next = [...trail, n.category]
      if (n.category.slug === slug) return next
      const hit = walk(n.children, next)
      if (hit) return hit
    }
    return null
  }
  return walk(nodes, []) ?? []
}
