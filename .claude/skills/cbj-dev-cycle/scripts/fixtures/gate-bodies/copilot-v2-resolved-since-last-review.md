<!-- ccr-overview-v2 -->

## Copilot review overview

### 🔵 Needs a closer look

Add CI coverage for the regression test and assert that unknown details sections are preserved.

**Review effort:** Lite  
**Findings:** None

<details>
<summary><strong>Resolved since last review (1)</strong></summary>

- <picture><source media="(prefers-color-scheme: dark)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/low-v2-dark.svg"><source media="(prefers-color-scheme: light)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/low-v2-light.svg"><img src="https://github.githubassets.com/static/images/icons/copilot-code-review/low-v2-light.png" alt="Low severity" width="62" height="18" align="texttop"></picture> [「スレット」を「スレッド」に修正](#discussion_r4092049277)
</details>

<details>
<summary><strong>Previously missed (1)</strong></summary>

In code that hasn't changed since last review

<details>
<summary><picture><source media="(prefers-color-scheme: dark)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-dark.svg"><source media="(prefers-color-scheme: light)" srcset="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.svg"><img src="https://github.githubassets.com/static/images/icons/copilot-code-review/medium-v2-light.png" alt="Medium severity" width="62" height="18" align="texttop"></picture> 回帰テストがCIの品質ゲートに組み込まれていない</summary>

`.claude/​skills/​cbj-dev-cycle/​scripts/​test-gate-bodies.sh:3`

この回帰テストは `quality.sh` にも `.github/workflows/ci.yml` にも組み込まれていないため、現状は手動実行しない限り `format_bodies` の退行をCIが検出できません。`gate-bodies.sh` の今回の重要な取りこぼし防止を継続的に保証するため、このテストを品質ゲートまたは専用のCI stepから実行してください。
</details>
</details>
