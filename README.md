# EzyGitSync

Small single-user PHP app to pull data from Git (supports GitHub, GitLab, custom Git servers).

Features
- Uses `.env` for config (create `.env` using the setup page: `./?action=setup` or copy `.example.env`)
- Single-user login (username + password stored as hash in `.env`)
- Pull/clone repository and stream git output to browser
- Shows latest commits and recent activity log
- Logs all actions to a single log file (default `logs/app.log`)

Usage
1. Place this project in a PHP-enabled folder and open in the browser.
2. If no `.env` exists, visit the setup page at `./?action=setup` and enter details (git URL, credentials if any, app username/password, repo path).
3. Login at `./?action=login` and use the dashboard to pull/update.

Security notes
- The app is intentionally simple for local use. Avoid exposing it to the public internet without extra security.
- Credentials may be embedded into clone URL for private repos; store `.env` securely.

Requirements
- PHP 8.3+
- `git` available on the server path

Note: The app checks for the `git` binary at runtime. If Git is not installed or not present in the PATH, the app will attempt an HTTP download fallback (archive download) from common providers like GitHub/GitLab when you press Pull. Installing Git is still recommended for full functionality (commit history, pulls, etc.), but the fallback allows retrieving repository contents without Git installed.

Clear-before-deploy option: The dashboard includes a checkbox to "Clear existing files before deploy" — if selected, the app will attempt a safe removal of the target `REPO_PATH` before cloning or extracting the downloaded archive. Safety checks are in place:
- The app refuses to remove system roots (e.g., `/` or drive roots) and directories outside the project folder.
- Use `PROTECT_PATHS` (see below) to prevent specific files or folders from being removed.

Protected paths: Use the `PROTECT_PATHS` environment variable (comma-separated) to list files or directories that must never be removed by the clear operation. Relative paths are resolved against the project root. Example: `PROTECT_PATHS=logs,.env`.

Warning about `REPO_PATH` placement ⚠️
- **Do not** point `REPO_PATH` at a parent web root or the folder containing the application (for example: `/var/www/domain.com/` while the app is deployed at `/var/www/domain.com/public/ezygitsync/`). Doing so risks deleting or overwriting your live site when Clear is used or when the download fallback extracts content.
- Recommended patterns:
  - Deploy into a dedicated folder: `/var/www/domain.com/deploy/` and use a symlink swap for release deployments.
  - Or use a subdirectory under the app (e.g., `repos/site/`) and serve the site using webserver configuration that points to the deployment target.
- If you must target a parent directory, add it to `PROTECT_PATHS` to avoid accidental removal and ensure the PHP process has appropriate permissions. Prefer backups or a manual review before performing a Clear.

Improvements since initial version
- Added HTTP archive download fallback when Git is not available (tries GitHub/GitLab and generic archive URLs, extracts ZIP archives).
- Streaming git output to the browser and logging of all actions to a single log file (`logs/app.log`).
- Added Clear-before-deploy option and `safe_rmdir()` with safety checks.
- Configurable `PROTECT_PATHS` to prevent destructive actions on important files/directories.
- Added `.download_info` recording when the archive fallback is used (records source URL and timestamp).

Suggested next hardening steps (optional)
- Add an automatic backup (move existing contents to a timestamped backup folder) before clearing. This is safer than immediate removal.
- Add a confirmation modal UI when Clear is selected.
- Add stronger private-repo support (tokens / API-based downloads) and SSH-key based cloning.


