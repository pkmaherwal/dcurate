# Public API (for your website)

Read-only access to a workspace's **approved** items. Everything else (pending, rejected, other workspaces) is invisible to the API.

## Authentication

Create a token in **Workspace settings → API tokens** (shown once). Send it on every request:

```http
Authorization: Bearer dc_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

RSS readers that cannot send headers may use `?token=` instead. Limit: 120 requests per minute per token. Responses are cached for 5 minutes and refreshed immediately when an item is approved, edited, featured or unpublished.

## `GET /api/v1/campaigns`

Lists the workspace's campaigns with their categories and extracted fields.

## `GET /api/v1/campaigns/{slug}/updates`

| Parameter | Description |
|---|---|
| `since` | ISO 8601 date/time. Only items **approved** at or after it (use it for incremental syncs). |
| `category` | One of the campaign's categories, e.g. `Result`. |
| `featured` | `1` for featured items only, `0` to exclude them. |
| `per_page` | 1–100, default 20. |
| `page` | Page number. |

Order: featured first, then newest published first.

```json
{
  "data": [
    {
      "id": 812,
      "title": "Kerala names 22-member Santosh Trophy squad",
      "summary": "The Kerala Football Association named its squad for the group stage, which starts on 10 March in Kochi.",
      "category": "Announcement",
      "fields": { "tournament": "Santosh Trophy", "start_date": "2026-03-10", "city": "Kochi" },
      "url": "https://kfa.example.org/news/santosh-trophy-squad",
      "image": "https://kfa.example.org/img/squad.jpg",
      "embed": null,
      "featured": false,
      "published_at": "2026-09-28T09:30:00+05:30",
      "approved_at": "2026-09-28T11:02:14+05:30",
      "source": { "name": "Kerala Football Association", "type": "rss", "url": "https://kfa.example.org" }
    }
  ],
  "campaign": { "name": "Football tournaments in India", "slug": "football-india" },
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "per_page": 20, "total": 57, "last_page": 3 }
}
```

`fields` holds whatever the campaign extracts (configured in the campaign's settings), with empty values left out.

### Displaying items

- Always show the **source name with a link** (`source.url` or `url`); summaries are short on purpose, so link to the original for the full story.
- `embed.type = "youtube"`: embed `https://www.youtube.com/embed/{embed.id}`.
- `embed.type = "instagram"`: use Instagram's official embed for `embed.url` (`image` is `null` for Instagram posts).
- Google News items link through `news.google.com`, which redirects to the publisher.

## `GET /api/v1/campaigns/{slug}/feed.rss`

The latest 50 approved items as RSS 2.0 (same filters as above). Example: `https://curation.example.com/api/v1/campaigns/football-india/feed.rss?token=dc_…`

## Errors

`401` missing or invalid token · `404` unknown campaign (or another workspace's) · `422` invalid parameter · `429` rate limit exceeded.
