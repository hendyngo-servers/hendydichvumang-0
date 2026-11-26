# HENDY Admin — Source Code Control

The Admin tab **Quản lý toàn bộ mã nguồn** is a restricted GitHub source editor. It is not a production file manager and never writes into the deployed VPS container.

## Configure the connection

For Ubuntu VPS, set these values in the private file deploy/ubuntu/hendy.env:

- HENDY_GITHUB_TOKEN: a fine-grained GitHub personal access token.
- HENDY_SOURCE_CONTROL_REPOSITORY: hendyngo-servers/hendydichvumang-0.
- HENDY_SOURCE_CONTROL_BASE_BRANCH: main.

The token is read only by the PHP backend. It is not returned by any API, stored in PostgreSQL, shown in the browser or included in Admin audit details. Never add its value to Git, .env.example, chat messages or the Admin editor.

### Minimum GitHub token permissions

Restrict the token to this one repository and grant only:

- Metadata: read.
- Contents: read and write.

The current source editor does not create Pull Requests. It creates a Git commit and advances the configured base branch using a fast-forward-only ref update. Do not grant more permissions than required. GitHub Actions workflow files are read-only in the editor.

Use a short expiration and rotate/revoke the token if an Admin account or VPS is compromised.

## Editor behaviour

1. The editor fetches the repository tree and UTF-8 source files from the configured base branch.
2. Changes stay in the current browser session until staged. Multiple files can be staged together; new text files can be created and existing files can be marked for deletion.
3. The server verifies the base commit SHA and each file blob SHA before creating any Git objects. If the branch/file changed after it was opened, the request is rejected and the editor must reload the file.
4. The backend scans for common private-key, GitHub token, Telegram bot token, AWS access-key and Slack token patterns.
5. The backend creates a commit and advances the configured base branch only if the update is a fast-forward. It never force-pushes.

The editor limits a file to 512 KB, a batch to 20 files and 2 MB total. It supports common UTF-8 source/config/document formats, not binary assets or large generated files.

## Protected files and deployment safety

- .env files, credential files, private keys, certificates and generated dependency/cache directories are hidden.
- GitHub Actions workflow/action files are visible only as read-only entries. This prevents the Admin editor from changing CI steps that may receive repository permissions/secrets.
- SQL/migration files, Dockerfile, deployment configuration and dependency lockfiles require an explicit confirmation in the editor.
- No code is executed in the Admin process. There is no shell/terminal endpoint, automatic database migration, Docker command, service restart or VPS deployment action.
- A successful GitHub write is not a VPS deployment. The Ubuntu server changes only after an operator-approved update using deploy/ubuntu/update.sh.

## Troubleshooting

- **Not connected:** confirm HENDY_GITHUB_TOKEN is present in the private VPS environment file and restart the HENDY service so PHP reads the new environment.
- **401/403:** verify token expiry and Contents permission.
- **404:** verify repository owner/name and base branch; the token may not have access.
- **Stale file/base commit:** reload the repository tree and reopen the file. The editor intentionally rejects a stale update instead of overwriting a newer commit.
- **CI fails:** inspect GitHub Actions and fix failing tests before using the source editor for further changes.
- **VPS still runs old code:** after reviewing the Git change and CI, run deploy/ubuntu/update.sh from the Ubuntu VPS. That script fetches main, fast-forwards, rebuilds the image, waits for health and reverts the source commit if the new build/health check fails.

Always review SQL/migrations and deployment changes before confirming. A code rollback does not automatically roll back PostgreSQL schema/data.
