# PrestaBridge

**Google Sheets to PrestaShop product synchronization via Cloudflare Workers.**

## What does it do?

1. You select products in Google Sheets ☑️
2. Click "Send" in the custom menu
3. Products are securely pushed through Cloudflare directly to your PrestaShop store
4. Images are downloaded automatically in the background

## Architecture

```text
Google Sheets → CF Worker (Router) → CF Queue → CF Worker (Consumer) → PrestaShop Module
                                                                              ↓
                                                                    CRON → Downloads images
```

## Tech Stack

| Layer | Technology |
|---------|-------------|
| **Data Source** | Google Sheets + Apps Script |
| **Middleware** | Cloudflare Workers (Free tier) + Queue |
| **Target** | PrestaShop 8.1+ (PHP module) |
| **Authentication**| HMAC-SHA256 |
| **Testing** | Vitest (CF Workers) + PHPUnit (PS) |

## Project Structure

```text
prestabridge/
├── CLAUDE.md              ← Technical specification (for AI agents)
├── RULES.md               ← Coding rules (for AI agents)
├── DECISIONS.md           ← Architectural decision records (ADR)
├── TESTING-STRATEGY.md    ← Test scenarios with assertions
├── DEPENDENCY-MAP.md      ← Dependency diagrams
├── DEPLOYMENT.md          ← Deployment guide
├── shared/                ← JSON schemas + test fixtures
├── apps-script/           ← Google Apps Script
├── workers/router/        ← CF Worker Router
├── workers/consumer/      ← CF Worker Consumer
└── prestashop-module/     ← PrestaShop Module
```

## Quick Start

Detailed deployment instructions can be found here: [DEPLOYMENT.md](DEPLOYMENT.md)

## AI Agent Documentation

This project is deeply optimized and fully documented for AI agent-assisted development (Claude, Cursor, Windsurf, Antigravity):

- **CLAUDE.md** — complete technical specification with pseudocode for every class.
- **RULES.md** — strict rules the agent must not break.
- **TESTING-STRATEGY.md** — specific assertions for each test scenario.
- **DECISIONS.md** — architectural reasoning to prevent the agent from "improving" or hallucinating changes to the architecture.
- **shared/fixtures/** — ready-to-use test data.

## License

This project is licensed under the [MIT License](LICENSE).