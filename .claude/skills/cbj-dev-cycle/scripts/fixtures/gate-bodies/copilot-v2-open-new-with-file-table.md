<!-- ccr-overview-v2 -->

## Copilot review overview

### 🟡 Changes recommended

Resolve the outstanding tax-basis consistency, WooCommerce rounding, and integer-price handling concerns before approval.

*Get a fresh assessment by requesting another Copilot review.*

**Review effort:** Lite  
**Findings:** 3 <picture><source media="(prefers-color-scheme: dark)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-dark.svg"><source media="(prefers-color-scheme: light)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.svg"><img src="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.png" alt="Medium severity" width="62" height="18" align="texttop"></picture>

<details open>
<summary><strong>Open (3)</strong></summary>

- <picture><source media="(prefers-color-scheme: dark)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-dark.svg"><source media="(prefers-color-scheme: light)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.svg"><img src="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.png" alt="Medium severity" width="62" height="18" align="texttop"></picture> [Unconfirmed tax basis incorrectly maps option market price](#discussion_r4089899143) · New
- <picture><source media="(prefers-color-scheme: dark)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-dark.svg"><source media="(prefers-color-scheme: light)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.svg"><img src="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.png" alt="Medium severity" width="62" height="18" align="texttop"></picture> [Variation tax class mismatch causes inconsistent price conversion](#discussion_r4089899181) · New
- <picture><source media="(prefers-color-scheme: dark)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-dark.svg"><source media="(prefers-color-scheme: light)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.svg"><img src="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.png" alt="Medium severity" width="62" height="18" align="texttop"></picture> [Fractional converted prices are truncated during export](#discussion_r4089899212) · New
</details>

<details>
<summary><strong>What changed in this PR</strong></summary>

This PR normalizes tax-exclusive WooCommerce prices to tax-inclusive values and exports variation sale prices to ColorMe.

**Changes:**
- Adds tax-inclusive price conversion with fail-closed handling.
- Adds variation sale/list price propagation and warnings.
- Updates tests, documentation, review records, and export guidance.

| File | Summary |
|---|---|
| `tests/​unit/​Woo/​Support/​TaxInclusivePriceTest.php` | Tests tax-inclusive conversion. |
| `tests/​unit/​Woo/​Reader/​ProductReaderTest.php` | Tests normalized product and variation prices. |
| `tests/​unit/​Adapters/​ColorMe/​ColorMeExportPricesTest.php` | Tests end-to-end exported pricing. |
| `tests/​unit/​Adapters/​ColorMe/​ColorMeAdapterTest.php` | Tests variation price payloads. |
| `includes/​Woo/​WarningCode.php` | Adds pricing warning codes. |
| `includes/​Woo/​Support/​TaxInclusivePrice.php` | Converts prices using WooCommerce tax settings. |
| `includes/​Woo/​Reader/​ProductReader.php` | Reads and normalizes product and variation prices. |
| `includes/​Canonical/​CanonicalProduct.php` | Documents canonical price contracts. |
| `includes/​Adapters/​ColorMe/​ColorMeAdapter.php` | Sends variation sale and market prices. |
| `docs/​reviews/​fix/​59-export-price-tax-and-variation-sale/​R2.md` | Records verification review results. |
| `docs/​reviews/​fix/​59-export-price-tax-and-variation-sale/​R1.md` | Records initial review findings. |
| `docs/​reviews/​fix/​59-export-price-tax-and-variation-sale/​dev-cycle.md` | Records the development cycle. |
| `docs/​review-backlog.md` | Updates related backlog items. |
| `docs/​10-tasks.md` | Records task completion. |
| `docs/​03-design-decisions.md` | Documents pricing design decisions. |
| `.claude/​rules/​woocommerce-api.md` | Updates WooCommerce API guidance. |
| `.claude/​rules/​sync-export-tools.md` | Updates export guidance. |
</details>

---

💡 <a href="/artisanworkshop/cart-bridge-jp/new/main?filename=.github/skills/code-review/SKILL.md" class="Link--inTextBlock" target="_blank" rel="noopener noreferrer">Add a `code-review` agent skill</a> or configure MCP servers for context-aware, tailored reviews. <a href="https://docs.github.com/copilot/how-tos/use-copilot-agents/request-a-code-review/use-code-review?tool=webui#mcp-servers-and-agent-skills" class="Link--inTextBlock" target="_blank" rel="noopener noreferrer">Learn more in the docs.</a>
