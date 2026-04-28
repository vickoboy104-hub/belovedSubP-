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

## 4. Pull safely from GitHub to the server

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

## 5. If the server has modified files

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

## 6. Check whether the server is now updated

After pulling, confirm:

```powershell
git status -sb
git log --oneline --decorate -3
```

## 7. Push changes from your machine or server to GitHub

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

## 8. Quick safest workflow

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

## 9. Useful emergency checks

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
