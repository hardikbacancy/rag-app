# Document Intelligence RAG Platform

Ask questions about PDF reports and get answers with page-level citations.
Built with Laravel 12, React 19, PostgreSQL + pgvector and Google Gemini.

All three sample reports go through the same pipeline. Only the chunking strategy changes:

| Report | Strategy | What it adds |
|---|---|---|
| GPO 2018 Annual Report (82 p.) | `baseline` | Fixed-size overlapping chunks, one page per chunk |
| World Bank Global Economic Prospects, June 2026 (200 p.) | `hierarchical` | Detects headings from font sizes and stores a `CHAPTER 3: … > Introduction` section path on each chunk. Retrieval boosts sections the question names |
| Apple iMac Product Environmental Report (12 p.) | `multimodal` | Everything above, plus Gemini vision on visual pages: tables as Markdown, chart and infographic values, footnotes, and OCR for pages without a text layer |

```
PDF ─► PdfExtractor (poppler) ─► ChunkingStrategy ─► Gemini embeddings ─► chunks table (pgvector + tsvector)
                                  baseline │ hierarchical │ multimodal

Question ─► embed ─► Retriever: vector search + full-text search (+ section search) ─► RRF fusion ─► top-k
        ─► AnswerService: numbered sources [S1..Sk] ─► Gemini ─► answer with [S#] ─► citations checked against the sources
```

## Requirements

- PHP 8.2+ with `pdo_pgsql` (and `intl`, recommended)
- PostgreSQL 14+ with the **pgvector** extension (`apt install postgresql-16-pgvector`)
- **poppler-utils** (`pdfinfo`, `pdftotext`, `pdftohtml`, `pdfimages`, `pdftoppm`): `apt install poppler-utils`
- Node 20+
- A Google Gemini API key (https://aistudio.google.com/apikey)

You don't need Tesseract or Python. Gemini vision handles OCR, and only for pages that need it.

## Setup

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# edit .env: DB_* (pgsql) and GEMINI_API_KEY
createdb rag-app                       # or create it in psql
php artisan migrate                    # runs CREATE EXTENSION vector (needs a privileged DB user once)
npm run build
```

Run it (three processes):

```bash
composer serve     # web server on :8000 with upload limits raised to 100 MB
composer worker    # queue worker that ingests uploads (timeout 3600 s)
npm run dev        # optional: Vite hot reload while editing the UI
```

Or run everything at once with `composer dev`. Open http://127.0.0.1:8000.

> **Upload size:** PHP's default `upload_max_filesize` is 2 MB and all three sample PDFs are bigger (4–7 MB).
> `composer serve` / `composer dev` raise the limit. If you use `php artisan serve` or another web server, raise
> `upload_max_filesize` and `post_max_size` in php.ini. Or ingest from the CLI (below), which has no limit.

### Ingest the sample reports from the CLI

```bash
php artisan rag:ingest /path/to/GPO-Anual-report.pdf --strategy=baseline     --title="GPO Annual Report 2018"
php artisan rag:ingest /path/to/GEP-Jun-2026.pdf     --strategy=hierarchical --title="World Bank Global Economic Prospects June 2026"
php artisan rag:ingest /path/to/iMac_PER_Oct2024.pdf --strategy=multimodal   --title="Apple iMac Environmental Report"

php artisan rag:ask "What is the growth outlook for South Asia?" --document="World Bank Global Economic Prospects June 2026"
php artisan rag:evaluate        # runs evaluation/dataset.json, writes evaluation/results/latest.{md,json}
```

`rag:ingest` runs synchronously and re-ingests if a document with the same title already exists.
The local copies used during development are in `storage/app/samples/` (not web-accessible).

## Using the UI

- **Upload PDF**: pick a file, an optional title and a strategy. Ingestion runs on the queue. The document card shows the status (`pending → processing → ready / failed`), a progress bar and the current step. The page polls every 3 s while anything is processing.
- **Select documents**: tick the documents to search. If none are ticked, all ready documents are searched.
- **Ask**: the answer contains clickable `[S#]` chips. Each source card shows the document, **page**, content type (text/table/figure/footnote), section path and the retrieved text. *View page* shows a rendered image of that page. *Open PDF at page* opens the original PDF at that page.
- **Re-ingest / Delete**: on each document card.

## Configuration

Set everything in `.env`. Defaults are in `config/rag.php`.

| Variable | Default | Meaning |
|---|---|---|
| `GEMINI_API_KEY` | – | Required |
| `GEMINI_CHAT_MODEL` | `gemini-3.5-flash-lite` | Answer generation |
| `GEMINI_VISION_MODEL` | = chat model | Table, chart and footnote extraction (multimodal) |
| `GEMINI_EMBEDDING_MODEL` | `gemini-embedding-001` | Embeddings (`RETRIEVAL_DOCUMENT` / `RETRIEVAL_QUERY` task types) |
| `GEMINI_TIMEOUT` / `GEMINI_RETRIES` | 120 / 8 | Retries 429 and 5xx responses, waiting as long as Gemini's `retryDelay` asks |
| `GEMINI_EMBEDDING_BATCH_SIZE` | 50 | Inputs per `batchEmbedContents` call. Lower it on the free tier |
| `RAG_EMBEDDING_DIMENSIONS` | 768 | Vector size. **Set before the first migration** (HNSW max 2000). Vectors are L2-normalised |
| `RAG_CHUNK_SIZE` / `RAG_CHUNK_OVERLAP` | 1200 / 200 | Characters |
| `RAG_TOP_K` / `RAG_CANDIDATES` | 8 / 30 | Sources sent to the LLM / candidates per search before fusion |
| `RAG_VISION_MODE` | `auto` | `auto`: pages with images, number-heavy text, little text, or any page of a short document. `all` / `off` |
| `RAG_VISION_ALL_PAGES_BELOW` | 30 | In `auto` mode, documents with this many pages or fewer get vision on every page |
| `RAG_VISION_MAX_PAGES` / `RAG_VISION_DPI` | 120 / 110 | Cost guard and render resolution |
| `RAG_UPLOAD_MAX_KB` | 102400 | Upload validation limit |

**Free-tier quota.** Gemini's free tier allows **1000 embedding requests per day per model**, and every chunk counts as one request even inside a batch. At the default chunk size, the three sample reports produce ~1100 chunks. This machine's `.env` therefore uses `gemini-embedding-2` and `RAG_CHUNK_SIZE=2000` (~690 chunks). When Gemini asks the client to wait more than 2 minutes (a daily quota), the client stops retrying and the document fails with a clear "Gemini quota exhausted" message. Re-ingesting reuses stored vectors for chunks whose text didn't change (`chunks.content_hash` + `embedding_model`), so a retry only pays for new chunks. Retrieval only compares vectors made by the currently configured embedding model.

To switch provider, replace `app/Services/Rag/GeminiClient.php` (it has two methods: `embed()` and `generate()`).
If you change the embedding model or dimensions, re-run migrations and re-ingest.

## Code map

```
app/Services/Rag/
  PdfExtractor.php          poppler wrapper: page text, font-sized lines, image counts, page rendering (no shell; array args)
  TextSplitter.php          paragraph/sentence-aware splitting with overlap
  Strategies/
    ChunkingStrategy.php    interface: chunk(Document, pages, progress) -> chunks
    BaselineStrategy.php
    HierarchicalStrategy.php  heading detection (font size, running-header removal, "CHAPTER 3" + title merge), section stack
    MultimodalStrategy.php    extends hierarchical; vision page selection, JSON extraction, grounding checks
  IngestionService.php      shared pipeline: extract → chunk → embed → store; status/progress updates
  Retriever.php             hybrid search (pgvector cosine + Postgres full-text + named-section search), RRF fusion
  AnswerService.php         grounded prompt, [S#] citation validation
  GeminiClient.php          REST client (embeddings + generateContent, retries)
app/Jobs/IngestDocument.php
app/Http/Controllers/Api/{DocumentController,QueryController}.php
app/Console/Commands/{RagIngest,RagAsk,RagEvaluate}.php
resources/js/app.jsx + components/   React UI
evaluation/dataset.json             sample questions, reference answers, key facts, expected pages
evaluation/results/latest.{md,json} last evaluation run
```

### Database

- `documents`: title, strategy, status, progress, status_message, error, page_count, chunk_count
- `chunks`: document_id, `page`, position, `type` (text|table|figure|footnote), `section`, content, metadata (jsonb), `embedding vector(768)` (HNSW, cosine), `embedding_model`, `content_hash` (embedding cache), `search tsvector` (generated, GIN)

### How citations stay honest

- A chunk never spans two pages, so each citation points to exactly one PDF page.
- The LLM only sees numbered sources and must cite `[S#]`. Any `[S#]` that doesn't match a supplied source is removed from the answer before it reaches the user. The returned `citations` are the real chunks behind the remaining markers.
- If the sources don't contain the answer, the model is told to reply "I couldn't find this in the selected documents."
- Vision extraction gets the page's text layer and is told to copy numbers from it. Footnotes the model returns are kept only if ≥70% of their words occur in that page's text layer. This filter was added after the model invented footnote text for superscript markers on Apple page 1. Table and chart chunks record `numbers_in_text_layer`, the share of their numbers that can be checked against the text layer.
- Page numbers are **PDF page indexes** (1 = first page of the file). They can differ from the number printed on the page (for example, GPO PDF page 22 is printed as "20").

## Security notes

- Uploads are validated (PDF extension and MIME type, size limit), stored under a random name on the private `local` disk, and never served directly. The storage path is hidden from the API.
- All poppler commands use argument arrays (`Process::run([...])`), so there is no shell interpolation of file names.
- `/api/ask` and upload/re-ingest are rate-limited (`throttle`). Provider errors are logged, not echoed to the browser.
- There is no authentication, as the brief asked. Don't expose this app publicly as-is: anyone with access can upload, delete and spend API quota.

## Tests

```bash
createdb rag_app_test && psql -d rag_app_test -c 'CREATE EXTENSION vector'
php artisan test
```

`phpunit.xml` uses PostgreSQL database `rag_app_test`, because pgvector can't run on SQLite. Gemini is faked with
`Http::fake`, and poppler must be installed. Coverage: text splitting, heading detection, visual-page and grounding
heuristics, upload → ingest → pgvector, hierarchical section paths, ask with citation filtering, validation, page
preview, PDF download, delete cascade, and failed-ingestion status.

## Evaluation

`php artisan rag:evaluate` asks each sample question against its own document and scores it automatically.

- The **questions** come from `RAG-Hands-On-Use-Cases.docx`. That file has no answers, so the **reference answers, key facts and expected pages** in `evaluation/dataset.json` were written by reading each PDF's text layer (`pdftotext`). They are not model output.
- **Retrieval hit@k**: at least one of the top-k retrieved chunks comes from an expected page.
- **MRR**: mean of 1/rank of the first chunk from an expected page.
- **Cited correct page**: the answer cites at least one expected page.
- **Answer pass**: every key fact (with allowed alternatives such as "375.7 million" / "375 million") appears in the answer. This is a strict string check: it can't reward correct paraphrases of numbers, and it doesn't catch extra wrong claims. Read the full answers in `evaluation/results/latest.md`.
- **Section precision** (GEP only): share of retrieved chunks whose section path contains the expected section.

Results of the last run are below. The full per-question answers are in [evaluation/results/latest.md](evaluation/results/latest.md).

Run: 2026-10-16T09:31:44+00:00 · chat `gemini-3.5-flash-lite` · embeddings `gemini-embedding-2` (768 d) · chunk 2000/200 · top_k 8

| Document | Questions | Retrieval hit@k | MRR | Cited correct page | Answer pass | Key-fact recall |
|---|---|---|---|---|---|---|
| GPO Annual Report 2018 | 3 | 1 | 0.67 | 1 | 1 | 1 |
| World Bank Global Economic Prospects June 2026 | 3 | 1 | 1 | 1 | 0.67 | 0.78 |
| Apple iMac Environmental Report | 3 | 1 | 1 | 1 | 1 | 1 |
| ALL | 9 | 1 | 0.89 | 1 | 0.89 | 0.89 |

| Question | Strategy | First relevant rank | Cited pages | Key facts | Pass |
|---|---|---|---|---|---|
| gpo-1 | baseline | 2 | 21, 5 | 1/1 | yes |
| gpo-2 | baseline | 1 | 22, 39, 10, 8 | 2/2 | yes |
| gpo-3 | baseline | 2 | 17, 5 | 1/1 | yes |
| gep-1 | hierarchical | 1 | 96 | 2/2 | yes |
| gep-2 | hierarchical | 1 | 109, 110, 128, 112 | 3/3 | yes |
| gep-3 | hierarchical | 1 | 19 | 2/4 | **no** |
| apple-1 | multimodal | 1 | 7, 12 | 1/1 | yes |
| apple-2 | multimodal | 1 | 10 | 3/3 | yes |
| apple-3 | multimodal | 1 | 4, 1, 2 | 2/2 | yes |

**Failure, gep-3 (executive summary risks):** the answer correctly lists escalation of hostilities and commodity disruptions, trade policy uncertainty, geopolitical strains and weather shocks, all cited to page 19. It leaves out that risks are "skewed to the downside" and the severe scenario in which global growth falls to 1.3 percent. Both facts are in the retrieved page-19 chunk, so this is a generation gap, not a retrieval gap. The prompt was not tuned to make this test pass.

Caveats: 9 questions is a smoke test, not a benchmark. LLM output varies between runs, so re-run `php artisan rag:evaluate` to reproduce. Earlier development runs used `gemini-embedding-001` with 1200-character chunks. The table above is from the configuration shown on its first line.

