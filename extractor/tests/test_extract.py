from pathlib import Path

from extract import article, listing

FIXTURES = Path(__file__).parent / "fixtures"


def read(name: str) -> str:
    return (FIXTURES / name).read_text()


def test_listing_keeps_same_site_headlines_only():
    result = listing(read("listing.html"), "https://www.example-ff.org/news")

    urls = [link["url"] for link in result["links"]]
    assert urls == [
        "https://www.example-ff.org/news/santosh-trophy-2026-schedule-announced",
        "https://www.example-ff.org/news/u17-national-camp-probables",
    ]
    assert result["title"] == "News | Example Football Federation"
    assert "Santosh Trophy 2026 schedule announced" in result["markdown"]
    assert "var x" not in result["markdown"]
    assert result["content_hash"]


def test_listing_hash_ignores_changes_outside_the_links():
    html = read("listing.html")
    base = listing(html, "https://www.example-ff.org/news")["content_hash"]
    changed = listing(html.replace("27 Sep 2026", "Today"), "https://www.example-ff.org/news")["content_hash"]

    assert base == changed


def test_article_text_date_and_image():
    result = article(read("article.html"), "https://www.example-ff.org/news/santosh-trophy-2026-schedule-announced")

    assert "36 state teams" in result["text"]
    assert "Copyright" not in result["text"]
    assert result["published_at"] == "2026-09-27"
    assert result["image"] == "https://www.example-ff.org/img/santosh.jpg"
    assert result["title"] == "Santosh Trophy 2026 schedule announced"
