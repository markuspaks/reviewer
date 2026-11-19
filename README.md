# Reviewer

Pull request review tool for Bitbucket Cloud, with Jira assignees and an MCP server for AI agents.

## Setup

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Set the database (`DB_*`) and mail (`MAIL_*`) settings in `.env`, then add the Bitbucket and Jira credentials below.

## Bitbucket API token (app account)

The sync (`php artisan reviewer:sync`) and MCP agents use one shared Atlassian account. It must be a member of every
workspace whose pull requests you want to see.

1. Sign in to Atlassian as that account and open
   [API tokens](https://id.atlassian.com/manage-profile/security/api-tokens).
2. Choose **Create API token with scopes**, give it a name and an expiry date.
3. Pick the **Bitbucket** app and these scopes:
   - `read:user:bitbucket`
   - `read:workspace:bitbucket`
   - `read:repository:bitbucket`
   - `read:pullrequest:bitbucket`
   - `read:pipeline:bitbucket`
4. Copy the token (it's shown only once) into `.env`:

   ```dotenv
   BITBUCKET_EMAIL=bot@example.com      # the Atlassian account's email
   BITBUCKET_API_TOKEN=ATATT...
   ```

## Jira API token

Used to find each pull request's assignee from the Jira issue in its title or branch (e.g. `ABC-123`).
Without it, assignees come from Bitbucket reviewers.

1. Sign in as an Atlassian account that can browse the Jira projects and open
   [API tokens](https://id.atlassian.com/manage-profile/security/api-tokens).
2. Choose **Create API token** (the classic one, without scopes — the app calls the Jira site URL directly, which
   scoped tokens don't support).
3. Copy the token into `.env`:

   ```dotenv
   JIRA_URL=https://your-site.atlassian.net
   JIRA_EMAIL=bot@example.com
   JIRA_API_TOKEN=ATATT...
   ```

If a workspace uses another Jira site, set it under **Jira site per workspace** on the Configuration page. The same
account must be able to open every site.

## First sync

Users are linked to Bitbucket workspace members, so sync before creating any:

```bash
php artisan reviewer:sync --full
```

## Users

There's no sign-up page; create logins on the command line. The email is what they log in with; `--member` is their
Bitbucket nickname or display name (as synced).

```bash
# Ask for the password interactively (or pick the member from a list when --member is left out)
php artisan reviewer:user jane@example.com --member=jane

# Generate a password and print it
php artisan reviewer:user jane@example.com --member=jane --generate
```

Running it again for an existing email sets a new password.

After logging in, each user adds **their own** Bitbucket API token on the Configuration page, so comments and approvals
appear under their name. They create it the same way as above (**Create API token with scopes**, app **Bitbucket**)
with the scopes `read:user:bitbucket`, `read:repository:bitbucket`, `read:pullrequest:bitbucket` and
`write:pullrequest:bitbucket`. The token is checked against Bitbucket and stored encrypted with `APP_KEY`.

For AI agents, each user creates a personal MCP token on the Configuration page, which also shows the commands to
connect Claude Code, Codex and others.

## Scheduler

Add the Laravel scheduler to cron. It runs `reviewer:sync` every five minutes and `send-emails` at 08:00 on weekdays.

```cron
* * * * * cd /path/to/reviewer && php artisan schedule:run >> /dev/null 2>&1
```
