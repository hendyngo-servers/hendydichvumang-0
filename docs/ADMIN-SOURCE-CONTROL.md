# HENDY Admin — Source Code Control

The Admin tab **Quản lý toàn bộ mã nguồn** is a browser-based editor for the
configured GitHub repository. It is not a production file manager and never
writes directly into the deployed container or the default branch.

## Configure the connection

Set these variables on the Render service **hendydichvumang-telegram-bot**:

- `HENDY_GITHUB_TOKEN`: a fine-grained GitHub personal access token.
- `HENDY_SOURCE_CONTROL_REPOSITORY`: `hendyngo-servers/hendydichvumang`.
- `HENDY_SOURCE_CONTROL_BASE_BRANCH`: `main`.

The token is read only by the PHP backend. It is not returned by any API,
stored in PostgreSQL, shown in the browser, or included in Admin audit details.
Never add its value to Git, .env.example, chat messages or the Admin editor.

### Minimum GitHub token permissions

Restrict the token to the single HENDY repository and grant only:

- Metadata: read.
- Contents: read and write (browse files and create Git blobs/trees/commits).
- Pull requests: not required.

Do not grant Actions, Administration, Secrets, Environments, Variables,
Deployments or workflow write permissions. Keep organization-wide access off.
Use a short expiration and rotate/revoke the token when an Admin account or
Render service is compromised.

## Editor behaviour

1. The editor fetches the repository tree and UTF-8 source files from the
   configured base branch.
2. Changes stay in the current browser session until staged. Multiple files
   can be staged together; new text files can be created and existing files
   can be marked for deletion.
3. The server verifies the base commit SHA and each file blob SHA before
   creating any Git objects. If the branch/file changed after it was opened,
   the request is rejected and the editor must reload the file.
4. The backend scans for common private-key, GitHub token, Telegram bot token,
   AWS access-key and Slack token patterns, then creates a commit on a unique
   branch and opens a **draft** Pull Request.
5. GitHub Actions runs the repository checks after the direct commit. Render
   may deploy automatically if the repository's deploy workflow is configured.

The editor limits a file to 512 KB, a batch to 20 files and 2 MB total. It
supports common UTF-8 source/config/document formats, not binary assets or
large generated files.

## Protected files and deployment safety

- .env files, credential files, private keys, certificates and generated
  dependency/cache directories are hidden.
- GitHub Actions workflow/action files are visible only as read-only entries.
  This prevents the Admin editor from changing CI steps that may receive
  repository secrets.
- SQL/migration files, Render deployment configuration, Dockerfile and
  dependency lockfiles require an explicit confirmation in the editor.
- No code is executed in the Admin process. There is no shell/terminal endpoint,
  automatic merge, direct push to the base branch, Render deploy trigger or
  database migration action.
- commit trực tiếp vào mains still require normal GitHub review and branch-protection rules.
  A PR is not a production deployment; the deployed Render service changes
  only after the repository's normal merge/deploy path completes.

## Troubleshooting

- **Not connected:** confirm HENDY_GITHUB_TOKEN is present on the Bot Render
  service and redeploy the service so PHP reads the new environment.
- **401/403:** verify token expiry, repository selection and the three minimum
  repository permission groups above.
- **404:** verify repository owner/name and base branch; the token may not have
  access to a private repository.
- **Stale file/base commit:** reload the repository tree and reopen the file.
  The editor intentionally rejects a stale update instead of overwriting a
  newer commit.
- **CI fails:** inspect the direct main commit checks and fix the failed tests before
  merging. The editor does not force-push. The GitHub ref update must be a fast-forward; configured CI/CD may run automatically after the commit.


## Direct write to main

The Admin editor now creates one Git commit on the configured base branch. It verifies the base commit SHA and every changed file SHA, scans for secrets, then advances the branch ref with `force=false`. If main has moved, GitHub rejects the non-fast-forward update; reload and reapply changes instead of overwriting another commit. A successful write may automatically trigger repository CI/CD and Render deployment depending on the existing repository configuration. Review SQL/migrations and deployment changes carefully before confirming.
