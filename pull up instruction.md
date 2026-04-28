# Pull Up Instruction

This guide helps you safely check your server git state, pull updates from GitHub, and push changes back to GitHub when needed.

## 1. Open the project on the server

```powershell
cd /path/to/your/project
```

Replace `/path/to/your/project` with the real server project folder.

## 2. Check the current git state on the server

Run these commands first before any pull:

```powershell
git status -sb
git branch --show-current
git remote -v
git fetch origin
git log --oneline --decorate -5
```

What these do:

- `git status -sb` shows whether the server has modified files.
- `git branch --show-current` shows the active branch.
- `git remote -v` shows the GitHub remote URL.
- `git fetch origin` updates the server's knowledge of GitHub without changing files.
- `git log --oneline --decorate -5` shows the latest commits on the server.

## 3. Know whether the server is behind GitHub

If the server uses `main`, run:

```powershell
git rev-list --left-right --count HEAD...origin/main
git log --oneline HEAD..origin/main
```

If the server uses `codex/flutterwave-wallet-nin-fixes`, run:

```powershell
git rev-list --left-right --count HEAD...origin/codex/flutterwave-wallet-nin-fixes
git log --oneline HEAD..origin/codex/flutterwave-wallet-nin-fixes
```

How to read the result from `git rev-list --left-right --count`:

- `0 0` means the server is already up to date.
- `0 1` means the server is behind GitHub by 1 commit.
- Any number on the left means the server has local commits not yet on GitHub.

## 4. Check how you previously pulled or connected the server repo

Git may not always remember the exact terminal command you typed before, but you can usually discover the setup and most recent pull behavior with these commands:

```powershell
git branch --show-current
git branch -vv
git remote -v
git remote show origin
git config --get branch.$(git branch --show-current).remote
git config --get branch.$(git branch --show-current).merge
git reflog --date=local -20
git reflog --date=local | Select-String "pull|merge|checkout|clone"
git log --oneline --decorate --graph -10
```

What these help you discover:

- `git branch --show-current` shows the branch currently running on the server.
- `git branch -vv` shows which remote branch the current branch is tracking.
- `git remote -v` shows the GitHub remote URL that the server is connected to.
- `git remote show origin` shows the default branch and tracked branches.
- `git config --get branch.$(git branch --show-current).remote` shows the remote name, usually `origin`.
- `git config --get branch.$(git branch --show-current).merge` shows the exact remote branch linked to the current branch.
- `git reflog --date=local -20` shows recent git actions done in that repo.
- `git reflog --date=local | Select-String "pull|merge|checkout|clone"` filters reflog lines for likely deploy-related actions.
- `git log --oneline --decorate --graph -10` shows recent commit movement and merge history.

How to interpret it:

- If you see `branch 'main' set up to track 'origin/main'`, the server was likely pulled from `origin main`.
- If you see `codex/flutterwave-wallet-nin-fixes` tracking `origin/codex/flutterwave-wallet-nin-fixes`, then that feature branch was likely what you pulled before.
- If `git reflog` shows entries like `pull --ff-only`, `pull origin main`, or `checkout main`, that gives you the closest record of what was done.
- If the reflog is short or missing old entries, git may no longer have the full history of older pull actions.

## 5. Check terminal history for old pull commands

If your server uses PowerShell, you may also find the old command in PowerShell history:

```powershell
Get-History
(Get-PSReadLineOption).HistorySavePath
Get-Content (Get-PSReadLineOption).HistorySavePath | Select-String "git pull|git fetch|git checkout|git clone"
```

What this means:

- `Get-History` shows commands from the current PowerShell session only.
- `HistorySavePath` points to the saved PowerShell command history file.
- Searching that history file may show the exact command you used previously on the server.

## 6. Pull safely from GitHub to the server

Only pull after confirming the server working tree is clean.

If the server should use `main`:

```powershell
git checkout main
git pull --ff-only origin main
php artisan optimize:clear
```

If the server should use `codex/flutterwave-wallet-nin-fixes`:

```powershell
git checkout codex/flutterwave-wallet-nin-fixes
git pull --ff-only origin codex/flutterwave-wallet-nin-fixes
php artisan optimize:clear
```

Why `--ff-only` matters:

- It updates safely only when the server can move forward without creating a merge commit.
- If git refuses, stop and inspect the server state before forcing anything.

## 7. If the server has modified files

If `git status -sb` shows modified files, do not pull immediately.

Run:

```powershell
git status -sb
git diff --name-only
```

Then decide:

- If the files are intentional server-only changes, back them up first.
- If the files should not exist, commit or remove them carefully before pulling.
- If you are unsure, stop and inspect before continuing.

## 8. Check whether the server is now updated

After pulling, confirm:

```powershell
git status -sb
git log --oneline --decorate -3
```

## 9. Push changes from your machine or server to GitHub

If you made changes and want to send them to GitHub:

```powershell
git status -sb
git add path/to/file
git commit -m "your commit message"
git push origin HEAD
```

If you want to push all tracked changes that belong to the same task:

```powershell
git status -sb
git add -A
git commit -m "your commit message"
git push origin HEAD
```

Always check `git status -sb` before pushing so you do not send unrelated changes.

## 10. Quick safest workflow

Use this when you want the shortest safe routine:

```powershell
cd /path/to/your/project
git status -sb
git branch --show-current
git fetch origin
git pull --ff-only origin main
php artisan optimize:clear
```

Replace `main` with your real branch if the server is not deploying from `main`.

## 11. Useful emergency checks

If something seems wrong, run:

```powershell
git branch --show-current
git status -sb
git fetch origin
git log --oneline --decorate -5
git rev-list --left-right --count HEAD...origin/main
Get-ChildItem public/images/nin
```

Those commands help confirm:

- the active branch
- whether the working tree is clean
- whether the server is behind GitHub
- whether the NIN image files exist physically on the server
