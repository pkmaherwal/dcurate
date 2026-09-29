# Playwright's image ships Chromium and its system libraries for Crawl4AI's headless rendering.
FROM mcr.microsoft.com/playwright/python:v1.63.0-noble

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
    && python -m playwright install chromium

COPY app.py extract.py ./

ENV PYTHONUNBUFFERED=1
EXPOSE 8100

HEALTHCHECK --interval=30s --timeout=5s CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8100/health')"

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8100", "--workers", "2"]
