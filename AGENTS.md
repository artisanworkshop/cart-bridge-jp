# Agent Instructions

This repository's coding conventions, architecture, security requirements, and
workflow rules are documented in [`CLAUDE.md`](./CLAUDE.md). Read it before
making changes, opening a pull request, or reviewing code in this repository.

`CLAUDE.md` is the single source of truth; this file exists only so that
agent tools which look for `AGENTS.md` (Codex, Cursor, and others) find it.

## Topic rules in `.claude/rules/` (required reading for the files you touch)

The long list of pitfalls (verified against real WooCommerce and ColorMe behavior)
is split by topic into [`.claude/rules/`](./.claude/rules/). They carry the same
weight as `CLAUDE.md`. **Claude Code loads them automatically when it reads a matching
file; other tools do not, so open the ones that match the files you change or review:**

| Rule file | Applies to |
|---|---|
| [`adapters-colorme.md`](./.claude/rules/adapters-colorme.md) | `includes/Adapters/**`, `includes/Canonical/**`, `AddressMapper`, `tests/fixtures/**` |
| [`woocommerce-api.md`](./.claude/rules/woocommerce-api.md) | `includes/Woo/**` |
| [`sync-export-tools.md`](./.claude/rules/sync-export-tools.md) | `includes/Sync/**`, `includes/Woo/Tools/**`, `includes/Woo/Export/**`, `includes/Woo/Reader/**` |
| [`frontend.md`](./.claude/rules/frontend.md) | `src/**`, `includes/Admin/Assets.php` |

The exact `paths` for each file are in its YAML frontmatter.
