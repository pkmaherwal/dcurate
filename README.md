# DC: AI curation platform

Collects the latest updates about a niche from many sources, has AI score and summarize every item, lets reviewers approve or reject them, and publishes the approved ones to your website through an API.

Built on Laravel 13, Livewire 4 and Flux, with MySQL, Redis + Horizon, the Laravel AI SDK (OpenAI `gpt-5.4-nano` by default, Gemini as fallback) and a small Python extractor sidecar (FastAPI, trafilatura, htmldate, Crawl4AI).

## How it works

```
cron → curation:dispatch-due → FetchSourceJob (queue: fetch) → source adapter → new items
     → EnrichItemsJob (queue: ai) → review queue → approve → /api/v1/... → your website
```

- **Workspaces (tenants).** Every record belongs to a workspace; a fail-closed global scope keeps them apart (`app/Tenancy`). The current workspace travels into queued jobs through Laravel's `Context`.
- **Campaigns** define a niche: an AI brief, categories, the fields to extract, keywords, region and language. Templates live in `config/campaign_templates.php`.
- **Sources** (`app/Sources/Adapters`): RSS/Atom (with feed discovery and RSSHub routes), websites without RSS (sitemap, CSS selectors or AI listing mode), YouTube channels, Google News searches, AI web search (OpenAI web search, results taken from citations), and Instagram (Graph API Business Discovery or Apify).
- **AI** (`app/Ai`, `app/Services/Ai`): each item gets a relevance score, English title and summary, category and extracted fields in one structured call. There is an optional cheap pre-filter, a daily budget per workspace, and usage tracking in `ai_usage`.
- **Review** (`/campaigns/{slug}`): approve, reject, edit then approve, bulk actions and keyboard shortcuts. Reports of the same story are grouped; reviewing the lead settles its duplicates.
- **Public API**: see [docs/API.md](docs/API.md).

## Local setup

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate   # set DB_* (MySQL) and OPENAI_API_KEY
php artisan migrate --seed                          # test@example.com / password, "Default Workspace"
php artisan db:seed --class=FootballIndiaSeeder     # optional demo campaign with real sources (network)
composer dev                                        # web, queue worker (fetch,ai,default), scheduler, logs, vite
```

Optional extractor sidecar (article text and dates, JavaScript pages):

```bash
cd extractor && docker compose up -d --build        # then EXTRACTOR_URL=http://127.0.0.1:8100
```

## Useful commands

| Command | What it does |
|---|---|
| `php artisan curation:fetch {source} [--dry]` | Fetch one source now (`--dry` previews without saving) |
| `php artisan curation:dispatch-due` | Queue all due sources (runs every minute) |
| `php artisan curation:enrich-pending [--failed]` | Queue AI for waiting items (runs every 15 minutes) |
| `php artisan workspace:create "Name" owner@example.com` | Create a workspace and its owner |

## Tests

```bash
composer test                        # Pint, Larastan, PHPUnit
cd extractor && pytest               # extractor unit tests
```

## Deployment

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) (Hostinger VPS: Nginx, PHP-FPM, MySQL, Redis, Supervisor/Horizon, cron, Docker).

## Turning on SaaS mode later

Public sign-up is off (`CURATION_OPEN_REGISTRATION=false`). Setting it to `true` enables registration; each new user gets their own workspace on the Free plan (`app/Listeners/CreatePersonalWorkspace.php`). Plan limits are in `config/plans.php`, and `ai_usage` already meters AI cost per workspace for billing.
