"""Pure extraction logic: HTML in, clean data out. No network, easy to test."""

from __future__ import annotations

import hashlib
import re
from typing import Optional
from urllib.parse import urljoin, urlparse

import html2text
import trafilatura
from htmldate import find_date
from lxml import html as lxml_html

# Anchors that are almost never news items.
NAV_WORDS = {
    "home", "about", "about us", "contact", "contact us", "login", "log in", "sign in", "sign up", "register",
    "privacy policy", "terms", "terms of use", "careers", "more", "read more", "next", "previous", "older posts",
    "newer posts", "facebook", "twitter", "x", "instagram", "youtube", "linkedin", "whatsapp", "subscribe",
}


def article(html: str, url: str) -> dict:
    """Main text, title, publish date and lead image of an article page."""
    text = trafilatura.extract(html, url=url, include_comments=False, include_tables=False, favor_precision=True)
    metadata = trafilatura.extract_metadata(html, default_url=url)

    published = metadata.date if metadata and metadata.date else None
    if not published:
        published = find_date(html, url=url, original_date=True, outputformat="%Y-%m-%d")

    return {
        "url": url,
        "title": metadata.title if metadata else None,
        "text": text,
        "markdown": None,
        "published_at": published,
        "image": metadata.image if metadata else None,
        "links": [],
        "content_hash": content_hash(text or ""),
    }


def listing(html: str, url: str) -> dict:
    """A news/announcements listing page as markdown (for the AI) plus its candidate links."""
    tree = lxml_html.fromstring(html)
    for bad in tree.xpath("//script|//style|//noscript|//svg|//iframe|//form"):
        bad.drop_tree()

    title = (tree.findtext(".//title") or "").strip() or None
    cleaned = lxml_html.tostring(tree, encoding="unicode")

    converter = html2text.HTML2Text(baseurl=url)
    converter.ignore_images = True
    converter.ignore_emphasis = True
    converter.body_width = 0
    markdown = converter.handle(cleaned)
    markdown = re.sub(r"\n{3,}", "\n\n", markdown).strip()

    links = candidate_links(tree, url)

    return {
        "url": url,
        "title": title,
        "text": None,
        "markdown": markdown[:60000],
        "published_at": None,
        "image": None,
        "links": links,
        # Hash only the candidate links so ads, dates-of-today and counters don't look like changes.
        "content_hash": content_hash("\n".join(link["url"] for link in links)),
    }


def candidate_links(tree, base_url: str) -> list:
    """Same-site links with headline-like text, in page order, without duplicates."""
    base_host = urlparse(base_url).netloc.lower().removeprefix("www.")
    seen = set()
    links = []

    for anchor in tree.xpath("//a[@href]"):
        href = anchor.get("href", "").strip()
        if not href or href.startswith(("#", "javascript:", "mailto:", "tel:")):
            continue

        absolute = urljoin(base_url, href).split("#")[0]
        parsed = urlparse(absolute)
        if parsed.scheme not in ("http", "https"):
            continue
        if parsed.netloc.lower().removeprefix("www.") != base_host:
            continue

        text = " ".join(anchor.text_content().split())
        if len(text) < 15 or text.lower() in NAV_WORDS:
            continue
        if absolute.rstrip("/") == base_url.rstrip("/") or absolute in seen:
            continue

        seen.add(absolute)
        links.append({"url": absolute, "text": text[:300]})

    return links[:200]


def content_hash(value: str) -> Optional[str]:
    return hashlib.sha256(value.encode("utf-8")).hexdigest() if value else None
