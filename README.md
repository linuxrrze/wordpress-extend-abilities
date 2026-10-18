# WordPress Extended Abilities

Notes on the AI/MCP setup of **blog.linux-ng.de** and the design of a small plugin that adds
**media upload from a local file** to the MCP toolset.

- [How the pieces fit together](#how-the-pieces-fit-together)
- [The plugins in detail](#the-plugins-in-detail)
- [Inconsistencies and cleanup suggestions](#inconsistencies-and-cleanup-suggestions)
- [Other standard plugins](#other-standard-plugins)
- [Local media upload: what is missing](#local-media-upload-what-is-missing)
- Files in this repo:
  - [`blog-article.html`](blog-article.html): blog article draft (Gutenberg markup, also saved as a draft on the blog)
  - [`docs/design-local-media-upload.md`](docs/design-local-media-upload.md): plugin design
  - [`extended-abilities/`](extended-abilities/README.md): the plugin (0.4.0: local uploads via upload slots, chunked and inline; GPS removal; replacing media files; media metadata; scheduling, pages, revisions; exposing other plugins' abilities)
  - [`client/`](client/README.md): `exab-upload`, a local MCP server for clients without a shell
  - [`images/`](images/): diagram for the blog article (SVG source + PNG)

State as of 2026-10-18, read from the live site through the MCP connection:

| Component | Version |
|---|---|
| WordPress | 7.1.3 (PHP 8.3, MariaDB 12.3) |
| MCP Adapter (`mcp-adapter`) | 0.7.1 |
| Enable Abilities for MCP (`enable-abilities-for-mcp`) | 2.13.7 |
| AI (`ai`) | 1.4.0 |
| AI Provider for OpenAI (`ai-provider-for-openai`) | 1.2.0 |
| AI ChatBot & Site Search (`lng-ai-chatbot`) | 0.2.6 |
| Client side: `@automattic/mcp-wordpress-remote` | `@latest` via `npx` |

---

## How the pieces fit together

WordPress has split "AI" into separate building blocks. The three plugins you asked about sit
on different layers, and only two of them are part of the MCP path.

```
 Claude Code (your machine)
   │  stdio
   ▼
 @automattic/mcp-wordpress-remote   ← local npx proxy: stdio ⇄ HTTP, adds Basic auth (app password)
   │  HTTPS  POST /wp-json/mcp/mcp-adapter-default-server
   ▼
┌──────────────────────────────── WordPress 7.1 ─────────────────────────────────┐
│                                                                                │
│  MCP Adapter (plugin)            speaks MCP, offers 3 meta-tools:              │
│    discover-abilities / get-ability-info / execute-ability                     │
│        │  only abilities with meta.mcp.public = true                           │
│        ▼                                                                       │
│  Abilities API (core since 6.9)  registry: name, JSON schemas, permission,     │
│        ▲            ▲            execute callback                              │
│        │            │                                                          │
│  core/* (3)    Enable Abilities for MCP: ewpa/* (~70 here, 112 in total)       │
│                                                                                │
│  ───────────────────────────── not on the MCP path ─────────────────────────── │
│                                                                                │
│  AI plugin (experiments in the block editor)                                   │
│        │ uses                                                                  │
│        ▼                                                                       │
│  AI Client + Connectors (core since 7.0) ──► AI Provider for OpenAI ──► OpenAI │
└────────────────────────────────────────────────────────────────────────────────┘
```

There are two directions:

1. **Outside AI → WordPress (MCP):** an external agent (Claude Code, Claude Desktop, Cursor, …)
   operates the site. The path is MCP Adapter + Abilities API + whoever registers abilities
   (here: core and *Enable Abilities for MCP*). WordPress does not call any LLM here.
2. **WordPress → AI provider (AI Client):** WordPress calls an LLM itself, for example to
   generate alt text, titles or excerpts in the editor. The path is the AI plugin + core AI
   Client + a connector plugin (here: OpenAI). MCP is not involved.

The shared piece is the **Abilities API**. The AI plugin registers abilities too, and the
MCP Adapter could expose them if they were marked `mcp.public`. On your site none of the
`ai/*` abilities show up over MCP (checked with `discover-abilities`), so today the two
directions are fully separate.

## The plugins in detail

### 1. MCP Adapter: [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter)

The official bridge between the Abilities API and the Model Context Protocol, part of the
"AI Building Blocks for WordPress" project. Still a separate plugin; it is not in core.

- Registers the **default MCP server** at `/wp-json/mcp/mcp-adapter-default-server`
  (HTTP, streamable). STDIO is also possible through WP-CLI (`wp mcp-adapter serve …`).
- The default server does not list each ability as its own tool. It offers three
  **meta-tools**: `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info` and
  `mcp-adapter-execute-ability`. That's why Claude Code shows only three `mcp__wordpress__*`
  tools.
- Only abilities with `meta.mcp.public = true` are visible. Nothing is exposed by default.
- Plugins can register **additional servers** (hook `mcp_adapter_init`, `create_server()`)
  that expose a chosen set of abilities as normal MCP tools, resources or prompts.
- Authentication is plain WordPress REST auth: application passwords, cookies, or whatever
  an auth plugin adds (OAuth, JWT, bearer tokens). After that, each ability runs its own
  `permission_callback`.

**Required** for anything MCP-related.

### 2. Enable Abilities for MCP: [mcp.fabiomontenegro.com](https://mcp.fabiomontenegro.com/), slug `enable-abilities-for-mcp`

A third-party plugin by Fabio Montenegro, free on wordpress.org. It provides **content**: it
registers a large set of ready-made abilities in the `ewpa/` namespace, marks them
`mcp.public`, and adds a settings screen at **Settings → WP Abilities** where each ability can
be switched on or off.

- 112 abilities in total, in groups: posts/pages/CPTs (CRUD), categories/tags/terms, comments,
  menus, users, media (read, **upload from URL**, alt-text audit), post/term meta, SEO (Rank
  Math, Yoast, SEOPress), multilanguage (Polylang, WPML, Linguator), WooCommerce, LMS, Elementor,
  JetEngine, Code Snippets, cache purge, llms.txt, FSE templates, …
- Needs the MCP Adapter. Since 2.11.1 it no longer bundles its own copy, so the separate
  `mcp-adapter` plugin is the right setup.
- Adds **extra auth options** for the MCP routes: claude.ai OAuth 2.1 connector, ChatGPT OAuth
  connector (beta), and a single admin **bearer token**. 2.13.7 fixed the bearer token so it
  only applies to the MCP routes.
- Can also switch **third-party abilities** on and off (for example abilities registered by
  Fluent Forms or Elementor).

**Required** in practice. Without it the MCP Adapter only exposes the three `core/*` info
abilities.

**Relevant gap:** media upload exists only as `ewpa/upload-image`. That ability downloads an
image from a **public URL** on the server side (sideload) and can set it as featured image.
It cannot take a file from your machine. There is also no ability for editing media metadata
(alt text, caption) apart from the generic `ewpa/update-post-meta`.

### 3. AI: [WordPress/ai](https://github.com/WordPress/ai), slug `ai`

The official **AI experiments** plugin, a reference implementation built on the AI Client
and the Abilities API. It adds AI features **inside wp-admin and the block editor**:
alt-text generation, title/excerpt/slug/meta-description generation, summaries,
translation, image generation and editing, comment moderation, type-ahead, an
**Abilities Explorer**, AI request logging, and more. Each feature is an opt-in "experiment".

- Brings no LLM of its own. It uses the provider configured under **Settings → Connectors**
  (core since WP 7.0). On your site that provider is **AI Provider for OpenAI**.
- Requires the block editor (no Classic Editor support) and WP ≥ 7.0.3.
- Its *Custom Abilities* experiment registers general-purpose abilities (`core/content-query`,
  `core/users-query`, `core/settings-get`, `ai/get-post-terms`, …). The plugin page lists MCP
  integration as "coming soon". On your site none of them appear over MCP.

**Not needed for MCP.** Keep it if you use the editor features. Its OpenAI connector costs
money per call, independent of your Claude usage.

### Not asked about, but part of the picture

- **AI Provider for OpenAI**: connector plugin for the core AI Client. Only the AI plugin
  (and possibly `lng-ai-chatbot`) uses it.
- **AI ChatBot & Site Search (`lng-ai-chatbot`)**: from the slug, probably your own plugin.
  If it calls an LLM, it belongs on the "WordPress → AI" side as well.
- **Enable Media Replace**: useful companion. It replaces a file while keeping its
  attachment ID and URL. It has no abilities, so it can't be used over MCP.
- **`@automattic/mcp-wordpress-remote`** (client side): a **stdio ⇄ HTTP proxy** with no tools
  of its own. It forwards MCP messages to `WP_API_URL` and adds auth (application password,
  JWT or OAuth 2.1). It is only needed for clients that can't speak HTTP MCP themselves.

## Inconsistencies and cleanup suggestions

Roughly in order of importance:

1. **Plaintext admin credentials, copied 6+ times.** `~/.claude.json` has the same
   `WP_API_USERNAME=marcel` + application password at user scope **and** again in the
   project scopes `w580`, `general`, `vogelhaus`, `cardreader` and `chipcard`. The user-scope
   entry already covers every project, so the project copies are redundant and mean more places
   to rotate. Remove them (`claude mcp remove wordpress -s local` in each project).
2. **The agent runs as administrator.** The application password belongs to your admin
   account, so every ability (including `ewpa/delete-post`, `ewpa/update-post-meta` on
   protected keys, and the Code Snippets abilities) runs with full rights. Create a dedicated
   user (for example `claude-agent`) with role **Editor** (or **Author** if it only needs
   drafts and uploads) and give it its own application password. EWPA checks normal WP
   capabilities, so the role limits the agent.
3. **`npx -y …@latest` with admin credentials in the environment.** Every new session may run
   a new, unreviewed version of the proxy, and that process can see your password. Pin a version
   (`@automattic/mcp-wordpress-remote@<x.y.z>`), or drop the proxy (next point).
4. **The proxy is probably unnecessary.** Claude Code speaks HTTP MCP natively. The proxy's own
   README says to connect directly when the client supports HTTP and can authenticate:
   ```bash
   claude mcp add --scope user --transport http wordpress \
     https://blog.linux-ng.de/wp-json/mcp/mcp-adapter-default-server \
     --header "Authorization: Basic $(printf 'claude-agent:APP PASS WORD' | base64 -w0)"
   ```
   One fewer process and no `npx` download. I haven't tested this against your site yet, so
   try it first. Since you run **SSO Login OIDC**, EWPA's **OAuth connector** is worth a
   look too: the WP login it redirects to could go through your Keycloak, and you'd get rid of
   static passwords.
5. **About 35 abilities for plugins you don't run.** Rank Math, Yoast, SEOPress, Polylang/WPML,
   JetEngine and Code Snippets are not active, but their `ewpa/*` abilities are still enabled and
   show up in `discover-abilities`. That's noise for the model and extra attack surface
   (`create-code-snippet`). Switch them off under **Settings → WP Abilities**.
6. **The AI plugin is mistaken for part of the MCP stack.** It isn't (see above). If you don't
   use the editor experiments, you can deactivate the **AI** plugin and **AI Provider for
   OpenAI**. MCP keeps working. If you do use them, check that only the experiments you want are
   enabled, because each one may call OpenAI.
7. **Two "ability explorers".** The AI plugin has an Abilities Explorer experiment, and EWPA has
   its own ability dashboard. Keep one.
8. *(Aside, not WordPress)* The `general` project uses a different draw.io MCP server
   (`drawio-mcp-server --editor`) than your user scope (`@drawio/mcp` with your own
   `DRAWIO_BASE_URL`). Probably a leftover.

**Minimal stack for MCP:** WordPress ≥ 6.9 + **MCP Adapter** + an ability provider (**EWPA**,
and later your own plugin). Everything AI-related on top is optional.

## Other standard plugins

What WordPress itself recommends (the "AI Building Blocks"):

| Building block | Where it lives now | Role |
|---|---|---|
| Abilities API | **core** since 6.9 | registry of machine-callable functions |
| AI Client + Connectors screen | **core** since 7.0 | WordPress → LLM, credentials managed centrally |
| AI Provider for OpenAI / Anthropic / Google | plugins | connectors for the AI Client |
| MCP Adapter | plugin (not in core yet) | abilities → MCP |
| AI | plugin | official experiments built on all of the above |

Notes:

- **Automattic `wordpress-mcp`** (the older plugin with its own MCP server) is **archived** and
  replaced by the MCP Adapter. Don't install it. The proxy has also dropped its old protocol
  variant.
- There is no "official" ability pack yet. Abilities come from core (three info abilities),
  the AI plugin, and **plugins that register their own** (WooCommerce, Elementor, Fluent Forms
  and others are adding this). EWPA fills the gap for core content today.
- Third-party alternatives that bring **their own** MCP server instead of using the adapter,
  for example **AI Engine** (Meow Apps), overlap with EWPA. Running both duplicates tools and
  auth paths, so I'd stick with the standard adapter route.
- For sites on WordPress.com there is a hosted MCP offering. Not relevant for a self-hosted
  blog.

## Local media upload: what is missing

The MCP server runs **on the WordPress host**. It can't read files on your machine, and MCP
has no file-transfer primitive. A local file can reach WordPress in three ways:

| Approach | Verdict |
|---|---|
| **Base64 in the tool arguments** | The model would have to *type out* the base64 as output tokens. A 1 MB photo is about 1.4 M characters, far beyond any output limit. Only usable for tiny files (icons, small SVGs). |
| **Client uploads directly to `/wp/v2/media`** with `curl` | Works today without a plugin, but the shell command and the transcript then contain the application password, and alt text and featured image need extra calls. |
| **Upload slot**: an ability issues a one-time upload URL and token, the client `PUT`s the bytes, the server validates and imports | **Recommended.** MCP stays the control channel, the bytes go out of band, no long-lived credential reaches the shell, and the server enforces type, size and checksum. |

Workaround that works today (from Claude Code, with a dedicated user):

```bash
curl -sS -u "claude-agent:$WP_APP_PASSWORD" \
  -F "file=@./diagram.png" -F "alt_text=Architecture diagram" -F "title=Architecture" \
  https://blog.linux-ng.de/wp-json/wp/v2/media | jq '{id, source_url}'
```

The full plugin design (abilities, REST route, security, client side) is in
[`docs/design-local-media-upload.md`](docs/design-local-media-upload.md).

---

Sources:
[MCP Adapter](https://github.com/WordPress/mcp-adapter) ·
[WordPress AI plugin](https://github.com/WordPress/ai) /
[wordpress.org/plugins/ai](https://wordpress.org/plugins/ai/) ·
[Enable Abilities for MCP](https://mcp.fabiomontenegro.com/) /
[wordpress.org/plugins/enable-abilities-for-mcp](https://wordpress.org/plugins/enable-abilities-for-mcp/) ·
[mcp-wordpress-remote](https://github.com/Automattic/mcp-wordpress-remote) ·
[WordPress 7.0 abilities & MCP for plugin developers](https://jorijn.com/en/blog/wordpress-7-0-abilities-mcp-plugin-developers-learn-first/) ·
[WordPress 7.0 AI foundation](https://www.krishaweb.com/blog/wordpress-7-0-ai-foundation.md)
