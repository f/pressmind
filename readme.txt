=== Pressmind ===
Contributors: f
Tags: block-editor, ai, gutenberg, openai, blocks
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prompt-powered Gutenberg composition for WordPress. Generate real blocks from natural language and post context.

== Description ==

Pressmind is an experimental block editor plugin that turns natural language prompts into real Gutenberg blocks.

Add the Pressmind block with `/pressmind`, describe the section or block you want, and Pressmind sends the prompt plus bounded post context to your configured AI provider. The response is parsed as serialized Gutenberg block markup and inserted back into the editor.

Pressmind can generate:

* Summaries, tables, callouts, lists, and structured sections.
* Custom HTML and static SVG diagrams.
* Org charts and visual explanations.
* Sandboxed interactive widgets that need JavaScript or scoped CSS.
* Optional generated images imported into the WordPress Media Library.

Pressmind also supports editing selected generated HTML or sandbox blocks with AI. The current block code is sent as context so the model can return a complete replacement block.

= External services =

Pressmind connects to external AI provider APIs when a user clicks a generation button in the editor. Nothing is sent automatically.

By default, Pressmind is configured for OpenAI-compatible chat completions. Site administrators may configure:

* API endpoint, for example `https://api.openai.com/v1/chat/completions`
* API key
* Text model
* Optional image generation through `https://api.openai.com/v1/images/generations`

Data sent to the configured provider may include:

* The user's prompt.
* Bounded post/editor context, such as title, excerpt, current content, and selected block markup.
* Existing HTML, CSS, or JavaScript when editing a generated sandbox block.
* Image prompts when image generation is enabled.

Provider responses are parsed, sanitized, and converted into blocks before being returned to the editor. Generated images are imported into the WordPress Media Library before insertion.

OpenAI terms and policies:

* Terms of use: https://openai.com/policies/terms-of-use/
* Privacy policy: https://openai.com/policies/privacy-policy/

On WordPress versions that include the Connectors API, Pressmind can use a selected AI connector's API key instead of its own custom API key field.

== Installation ==

1. Upload the `pressmind` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from the release page.
2. Activate Pressmind through the Plugins screen in WordPress.
3. Go to Settings > Pressmind.
4. Choose a credentials source:
   * Custom API key.
   * WordPress Connector, if the Connectors API is available.
5. Configure the endpoint, model, and optional image generation settings.
6. Open the block editor and add the Pressmind block with `/pressmind`.

== Frequently Asked Questions ==

= Does Pressmind generate blocks automatically? =

No. Pressmind only calls the configured AI provider after a user clicks a generation or edit button.

= Does Pressmind expose my API key in JavaScript? =

No. API keys are used only server-side in WordPress REST API handlers.

= Can Pressmind use WordPress Connectors API? =

Yes. On WordPress versions where the Connectors API is available, Pressmind can use an API-key AI connector as its credential source. Pressmind still lets you choose the text model name.

= What happens to generated JavaScript? =

Generated content that requires scripts, style tags, scoped CSS, or event handlers is isolated in a sandboxed iframe block.

= Can Pressmind generate images? =

Yes, if image generation is enabled in Settings > Pressmind. Generated images are imported into the WordPress Media Library before being inserted as image blocks.

= Is this production ready? =

Pressmind is experimental. Review generated content and code before publishing.

== Screenshots ==

1. Pressmind prompt block in the WordPress block editor.
2. Prefilled demo prompts imported by the Playground blueprint.
3. Sandboxed interactive block output.
4. Settings screen with custom credentials and Connector support.

== Changelog ==

= 0.0.6 =
* Respect `DISALLOW_UNFILTERED_HTML` by disabling AI-generated sandbox iframe output when the site policy is enabled.
* Reject generated sandbox blocks and scripted/style-tagged HTML before insertion when sandbox generation is disabled.
* Show a non-executing warning for existing sandbox blocks while sandbox generation is disabled.

= 0.0.5 =
* Added a longform mental-health interactive news example post that mimics Pressmind output (`examples/mental-health-interactive-news.html`).
* Added a dedicated Playground blueprint (`blueprint-mental-health.json`) that imports the example as a Gutenberg post.
* Added an animated example output GIF to the README and a one-click Playground link below it.

= 0.0.4 =
* Improved multi-provider compatibility for AI text generation.
* Improved reliability of image generation across providers.
* Internal refactors in the AI provider, REST controller, and settings layers.

= 0.0.3 =
* Added explicit credential source selection for custom API keys or WordPress Connectors API.
* Kept model selection available when using a connector.
* Added stable and nightly Playground blueprints.
* Improved settings UI behavior.
* Added WordPress.org plugin directory readme metadata.

= 0.0.2 =
* Added demo post import for WordPress Playground.
* Added text-to-Pressmind block transforms.
* Improved sandbox iframe auto-height behavior.
* Improved generated image import into the Media Library.

= 0.0.1 =
* Initial experimental release.
* Added prompt-to-block generation.
* Added streaming editor feedback.
* Added sandboxed interactive blocks.
* Added optional image generation.

== Upgrade Notice ==

= 0.0.6 =
Disables AI-generated sandbox iframe output when `DISALLOW_UNFILTERED_HTML` is enabled for the site.

= 0.0.5 =
Adds a longform interactive example post, a Playground blueprint that imports it, and an example output GIF in the README.

= 0.0.4 =
Improves multi-provider compatibility and image generation reliability.

= 0.0.3 =
Adds explicit credential source selection and improves support for WordPress Connectors API.
