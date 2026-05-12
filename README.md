<p align="center">
	<picture>
		<source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg" />
		<source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg" />
		<img src="assets/logo-light.svg" alt="Pressmind" width="720" />
	</picture>
</p>

<p align="center">
	<strong>Prompt-powered Gutenberg composition for WordPress.</strong>
</p>

<p align="center">
	<a href="https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/YOUR_GITHUB_USERNAME/pressmind/main/blueprint.json">Try in WordPress Playground</a>
	 ·
	<a href="#quick-start">Quick start</a>
	 ·
	<a href="#security-model">Security model</a>
</p>

<p align="center">
	<img alt="WordPress" src="https://img.shields.io/badge/WordPress-Block%20Editor-3858E9" />
	<img alt="License" src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" />
	<img alt="Status" src="https://img.shields.io/badge/status-experimental-f59e0b" />
</p>

## What Is Pressmind?

Pressmind is an experimental WordPress plugin that turns natural language prompts into real Gutenberg blocks. It reads the current post context, streams model output in the editor, and replaces the prompt block with generated content.

It can create static layouts, rich HTML, SVG diagrams, org charts, tables, callouts, sandboxed interactive widgets, and Media Library-backed AI images.

## Highlights

- **Context-aware generation**: Sends bounded post context so generated blocks match the current article.
- **Streaming editor feedback**: Shows model output while the backend is generating.
- **Real Gutenberg output**: Returns serialized block markup and inserts parsed blocks into the editor.
- **Smart rendering mode**: Keeps simple HTML/SVG as `core/html`; moves scripts/styles into an isolated sandbox block.
- **Editable generated blocks**: Refine selected HTML or sandbox blocks with AI using the existing code as context.
- **Sandboxed interactivity**: Games, calculators, and scripted UI render in an iframe with no same-origin access.
- **Optional image generation**: Generates images with OpenAI Images, imports them into the Media Library, and inserts `core/image` blocks.

## Demo

### Local Playground

```bash
npm install
npm run playground
```

This builds the block assets and starts a local WordPress Playground instance with auto-login.

### Hosted Playground Blueprint

This repository includes [`blueprint.json`](blueprint.json) for WordPress Playground.

After publishing the repo, update the placeholder GitHub owner in [`blueprint.json`](blueprint.json), then use:

```text
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/YOUR_GITHUB_USERNAME/pressmind/main/blueprint.json
```

The blueprint enables networking, logs into wp-admin, installs Pressmind from GitHub, activates the plugin, and opens a new post.

## Quick Start

```bash
npm install
npm run build
```

Then install the plugin in WordPress and activate **Pressmind**.

Go to `Settings > Pressmind` and configure:

- API key
- Chat completions endpoint
- Text model
- Optional image generation model and size

## Example Prompts

```text
Create a comparison table from this post.
```

```text
Generate an accessible SVG org chart for the teams described here.
```

```text
Build a sandboxed tic-tac-toe game with modern styling.
```

```text
Generate a hero image for this post and insert it with a caption.
```

## How It Works

```mermaid
flowchart LR
	PromptBlock[Pressmind Block] --> RestAPI[WordPress REST API]
	RestAPI --> Model[AI Model]
	Model --> RestAPI
	RestAPI --> Parser[Block Parser]
	Parser --> Editor[Gutenberg Editor]
	RestAPI --> Media[Media Library]
```

The backend asks the model for strict JSON with:

- `summary`: A short editor-facing description.
- `serializedBlocks`: Valid serialized Gutenberg block markup.
- `assets`: Optional generated media requests.
- `warnings`: Safe fallbacks or limitations.

## Security Model

- API keys are stored in WordPress options and used only server-side.
- REST endpoints require the current user to be able to edit the target post.
- Returned block markup is parsed, allowlisted, and sanitized before insertion.
- Static HTML and SVG use a conservative allowlist.
- Scripted or style-tagged content is isolated in `pressmind/sandbox`.
- Sandbox iframes use `sandbox="allow-scripts"` without same-origin access.
- Generated images are imported into the WordPress Media Library before insertion.

## Development

```bash
npm run start
npm run lint:js
npm run format
npm run build
```

Main files:

- [`pressmind.php`](pressmind.php): Plugin bootstrap and dynamic sandbox block rendering.
- [`includes/class-settings.php`](includes/class-settings.php): Admin settings.
- [`includes/class-ai-provider.php`](includes/class-ai-provider.php): AI and image provider calls.
- [`includes/class-rest-controller.php`](includes/class-rest-controller.php): REST generation and streaming.
- [`src/ai-prompt-block/`](src/ai-prompt-block): Block editor UI.

## Status

Pressmind is experimental and intended for exploration, demos, and early feedback. Review generated code and content before publishing.

## License

GPL-2.0-or-later
