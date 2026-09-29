"""Extractor sidecar for the curation app.

POST /extract {"url": ..., "mode": "article" | "listing", "render_js": false, "include_html": false}
GET  /health

Runs on 127.0.0.1 only. Set EXTRACTOR_TOKEN to require "Authorization: Bearer <token>".
"""

from __future__ import annotations

import os
from typing import Literal, Optional

import httpx
from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, HttpUrl

from extract import article, listing

USER_AGENT = os.getenv("EXTRACTOR_USER_AGENT", "Mozilla/5.0 (compatible; CurationBot/1.0)")
TOKEN = os.getenv("EXTRACTOR_TOKEN", "")
TIMEOUT = float(os.getenv("EXTRACTOR_TIMEOUT", "30"))

app = FastAPI(title="Curation extractor", version="1.0")


class ExtractRequest(BaseModel):
    url: HttpUrl
    mode: Literal["article", "listing"] = "article"
    render_js: bool = False
    include_html: bool = False


def authorize(authorization: Optional[str] = Header(default=None)) -> None:
    if TOKEN and authorization != f"Bearer {TOKEN}":
        raise HTTPException(status_code=401, detail="Invalid extractor token")


@app.get("/health")
def health() -> dict:
    return {"ok": True}


@app.post("/extract", dependencies=[Depends(authorize)])
async def extract(request: ExtractRequest) -> dict:
    url = str(request.url)

    try:
        html, final_url = await (render(url) if request.render_js else download(url))
    except httpx.HTTPStatusError as e:
        raise HTTPException(status_code=422, detail=f"The page returned HTTP {e.response.status_code}")
    except (httpx.HTTPError, RuntimeError) as e:
        raise HTTPException(status_code=422, detail=f"Could not load the page: {e}")

    result = listing(html, final_url) if request.mode == "listing" else article(html, final_url)

    if request.include_html:
        result["html"] = html

    return result


async def download(url: str) -> tuple:
    async with httpx.AsyncClient(
        follow_redirects=True,
        timeout=TIMEOUT,
        headers={"User-Agent": USER_AGENT, "Accept-Language": "en-IN,en;q=0.9"},
    ) as client:
        response = await client.get(url)
        response.raise_for_status()
        return response.text, str(response.url)


async def render(url: str) -> tuple:
    """Render with a headless browser (Crawl4AI + Playwright) for pages that need JavaScript."""
    from crawl4ai import AsyncWebCrawler, BrowserConfig, CacheMode, CrawlerRunConfig

    browser = BrowserConfig(headless=True, user_agent=USER_AGENT)
    # News sites keep loading ads and analytics, so "networkidle" may never come; wait for the DOM, then settle briefly.
    run = CrawlerRunConfig(
        cache_mode=CacheMode.BYPASS,
        page_timeout=int(TIMEOUT * 1000),
        wait_until="domcontentloaded",
        delay_before_return_html=2.5,
    )

    async with AsyncWebCrawler(config=browser) as crawler:
        result = await crawler.arun(url=url, config=run)

    if not result.success:
        raise RuntimeError(result.error_message or "rendering failed")

    return result.html, result.redirected_url or url
