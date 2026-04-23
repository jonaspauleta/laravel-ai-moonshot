# Changelog

All notable changes to `prism-moonshot` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-04-23

### Added

- Initial Moonshot AI (Kimi K2) provider for Prism PHP.
- Bridge to the Laravel AI SDK via `MoonshotGateway` (extends `PrismGateway`).
- Text, streaming, structured/JSON-mode, and tool-calling support.
- `reasoning_content` deltas surfaced as Prism `ThinkingEvent`s.
- Image input via OpenAI-compatible `image_url` content parts.
- Pest 4 + PHPStan level max + Pint + Rector quality pipeline.
- GitHub Actions workflow.

[Unreleased]: https://github.com/jonaspauleta/prism-moonshot/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/jonaspauleta/prism-moonshot/releases/tag/v0.1.0
