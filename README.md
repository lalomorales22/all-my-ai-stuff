# all-my-ai-stuff

### All My AI Stuff — a personal vault for everything you've made with the machines.

One `index.php` file that reads your **exported data from Claude, ChatGPT, Gemini, and Grok** and turns it into a single, beautiful place to browse every conversation, image, video, and idea — plus a built-in AI assistant (Claude Opus 4.8 / Sonnet 5) that helps you mine it, build personas, create content, and design custom datasets.

No build step. No dependencies. No cloud. Drop it next to your export folders and run it.

```bash
php -S localhost:8080
```

<p align="center"><em>PHP 8+ · SQLite · zero external libraries · everything stays on your machine</em></p>

---

## Why

If you use more than one AI, your history is scattered across four different export formats — giant JSON blobs, thousands of opaque `.dat` files, HTML dumps, folders of UUID-named videos. It's *your* data, but it's unreadable.

**All My AI Stuff** indexes all of it into one local SQLite database and gives it a home: a dark, instrument-panel UI where each provider is a "station," a unified media gallery, a real conversation reader, and an assistant that can actually see your corpus.

---

## Features

- **📡 Overview** — a live constellation of your whole archive, four provider "station" cards with counts, and a strip of your most recent image/video generations.
- **🗂️ Four provider pages** — a split-pane conversation reader (Markdown, code blocks, inline images), plus per-provider media and personas tabs, category filters, and one-click export of any thread to Markdown.
- **🖼️ Unified gallery** — a masonry grid across **all** sources with image / video / audio filtering, provider and category chips, prompt search, and a lightbox with the original prompt + download.
- **🤖 AI assistant** — pick **Claude Opus 4.8** or **Claude Sonnet 5** from a dropdown, then work in one of five modes:
  - **Chat** — ask anything about your vault
  - **Build a Persona** — turn your history into reusable AI personas + system prompts
  - **Content Creation** — spin past conversations into posts, threads, scripts, copy
  - **Custom Dataset** — distill your history into structured, reusable data
  - **Analyze My Data** — surface patterns, themes, and recurring interests

  Attach any of your real conversations and imported personas as context with one click. Responses stream token-by-token; save the good ones to your library.
- **📥 Import & Settings** — auto-detects the four common export layouts, lets you point at your own folders, builds the index per-source, and exports the whole index as JSON.
- **🔒 Private by design** — your data is read from local disk and served only to your browser. The *only* outbound request is to the Anthropic API, and only when you use the assistant with a key you provide.

---

## Supported exports

All My AI Stuff understands the real, current export formats from each provider. Grab yours here, unzip, and drop the folder next to `index.php`.

| Provider | Where to export | What it reads | Default folder |
|---|---|---|---|
| **Claude** (Anthropic) | claude.ai → Settings → Privacy → *Export data* | `conversations.json` (streamed), `projects/` → personas, `memories.json` | `Anthropic/` |
| **ChatGPT** (OpenAI) | ChatGPT → Settings → Data controls → *Export data* | `conversations-*.json` message trees + `*.dat` images/voice/video | `OpenAI/` |
| **Gemini + NotebookLM + Flow** (Google) | [takeout.google.com](https://takeout.google.com) → Gemini / NotebookLM / Flow | Gems HTML → personas, NotebookLM audio, Flow videos | `Google-Gemini/` |
| **Grok** (xAI) | grok.com → Settings → *Export your data* | `prod-grok-backend.json` conversations + generated images | `SpaceXAI/` |

> Folder names are just defaults — if yours are named differently, set the path on the **Import & Settings** screen and rebuild. Nested layouts are auto-discovered.

**What gets indexed on real exports** (for reference):

| | Conversations | Media | Personas |
|---|---:|---:|---:|
| Claude | 1,373 | — | 48 |
| ChatGPT | 7,316 | 4,629 | — |
| Gemini | 196 | 630 | 40 |
| Grok | 996 | 335 | 5 |

---

## Getting your data in

Your exports download as **`.zip` files**. Here's the whole flow:

1. **Export** your data from each AI (links in the table above). Each arrives as a `.zip`.
2. **Unzip** each one.
3. **Keep each export in its own folder.** Give them clear names if you like — e.g. `Anthropic`, `OpenAI`, `Google-Gemini`, `SpaceXAI` (or `Claude`, `ChatGPT`, `Gemini`, `Grok`). One AI per folder.
4. **Point the app at each folder** — two ways, pick whichever is easier:
   - **Drop-in:** move the four folders next to `index.php`. The app auto-detects them on the Import & Settings screen.
   - **Browse:** on the **Import & Settings** screen, click **Browse…** next to a source and navigate to that AI's unzipped folder, then **Use this folder**. The path is saved for you. (The folder picker runs on your own machine and is limited to your home directory.)
5. **Build the index.** Click **Build index** on each source (or **Build / rebuild all indexes**). Done — browse away.

> You can keep your unzipped folders anywhere in your home directory (Desktop, Downloads, an external-drive folder synced locally, etc.) and just Browse to them — they don't have to sit next to `index.php`. If you re-export later, replace the folder's contents and hit **Build index** again.

```
Export (.zip)  →  Unzip  →  One folder per AI  →  Browse / drop-in  →  Build index
```

---

## Quick start

**Requirements:** PHP 8.0+ with the `pdo_sqlite`, `gd`, `curl`, and `fileinfo` extensions (all standard). Check with `php -m`.

```bash
# 1. Put index.php in a folder alongside your export folders:
#
#    all-my-ai-stuff/
#    ├── index.php
#    ├── Anthropic/
#    ├── OpenAI/
#    ├── Google-Gemini/
#    └── SpaceXAI/
#
# 2. Start the server from that folder
php -S localhost:8080

# 3. Open it
open http://localhost:8080
```

Then go to **Import & Settings**. If your folders sit next to `index.php` they're auto-detected; otherwise click **Browse…** next to each source and point it at the unzipped folder. Hit **Build / rebuild all indexes** — the first run scans your exports into `.aivault/index.sqlite`; after that the app is instant.

> **Faster gallery:** the built-in PHP server is single-threaded, so the first load of a large gallery generates thumbnails one at a time. Run with workers for parallelism:
> ```bash
> PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080
> ```
> Thumbnails are cached after first generation, so it's only slow once.

---

## Using the AI assistant

The assistant calls the [Anthropic API](https://platform.claude.com) directly (streaming). To enable it:

1. Get an API key from the [Anthropic Console](https://console.anthropic.com).
2. Either set an environment variable before starting the server:
   ```bash
   ANTHROPIC_API_KEY=sk-ant-... php -S localhost:8080
   ```
   …or paste the key on the **Import & Settings** screen (stored locally in `.aivault/`, never committed).
3. Open **Assistant**, pick a model and a mode, attach context, and go.

Models available in the dropdown: **`claude-opus-4-8`** (Opus 4.8) and **`claude-sonnet-5`** (Sonnet 5).

---

## How it works

A single PHP file that acts as both the API and the app:

- **Indexers** parse each provider's format into a shared SQLite schema (`conversations`, `messages`, `media`, `personas`, `notes`). The 200 MB Claude export is parsed with a **streaming JSON scanner** so it never loads whole into memory.
- **Media route** (`?media=<id>`) serves files *by database id*, validating the resolved path against the allowed export roots (`realpath` prefix check) to prevent directory traversal. Supports HTTP range requests so video seeks work.
- **Thumbnails** (`?thumb=<id>`) are generated on demand with GD and cached to `.aivault/thumbs/`.
- **Assistant** (`?api=chat`) is a thin streaming proxy: it adds your API key server-side and passes the Anthropic SSE stream straight through to the browser.
- **Frontend** is dependency-free vanilla JS with hash routing; all conversation text is HTML-escaped before rendering.

```
index.php            ← the entire app (backend + frontend)
.aivault/            ← generated: index.sqlite + cached thumbnails (gitignored)
Anthropic/ OpenAI/ Google-Gemini/ SpaceXAI/   ← your exports (gitignored)
```

---

## 🔐 Privacy

**Your exported data and the generated index are personal and are excluded by `.gitignore`. Do not commit them.**

The `.aivault/index.sqlite` file contains a full copy of your conversation text and your saved API key. The export folders contain your private history. This repo is intended to hold **only the code** (`index.php`) — verify with `git status` before your first push that no `Anthropic/`, `OpenAI/`, `Google-Gemini/`, `SpaceXAI/`, or `.aivault/` paths are staged.

Nothing is uploaded anywhere. The single exception is the assistant, which sends your messages (and any context you explicitly attach) to the Anthropic API when you use it.

---

## Roadmap ideas

- Full-text search across all messages (SQLite FTS5)
- Semantic search / embeddings over the corpus
- More providers (Perplexity, DeepSeek, Copilot)
- Export a persona straight into a downloadable system-prompt file
- One-click "dataset" export to JSONL

---

## License

MIT — do what you want with it.

Built with [Claude Code](https://claude.com/claude-code).
