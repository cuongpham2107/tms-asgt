Issue tracker — GitHub

Repository: https://github.com/cuongpham2107/tms-asgt

Overview

- This repository uses GitHub Issues as the canonical issue tracker.
- The `gh` CLI is recommended for automation: https://cli.github.com/

How skills will interact

- Creating issues: `gh issue create --title "..." --body "..." --label "..."`
- Listing/searching: `gh issue list --label "needs-triage"`
- Skills that create or update issues will use the repository remote to resolve owner/repo.

Fallback

- If you prefer local markdown issues, use the `.scratch/` convention. To switch later, re-run the setup skill.
