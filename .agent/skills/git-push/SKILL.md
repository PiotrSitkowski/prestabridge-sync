---
name: git-push
description: Użyj tego skilla gdy zadanie dotyczy commitowania zmian i wypychania do GitHub — zawiera zasady generowania opisów commitów w języku angielskim, conventional commits format oraz bezpieczne pushowanie.
---

# SKILL: git-push

### WAŻNE: nie używaj && w poleceniach git. Używaj ; zamiast &&.

### Kiedy aktywować
Zadanie dotyczy commitowania zmian, pushowania do GitHub lub opisywania historii zmian.

### Conventional Commits — format obowiązkowy

```
<type>(<scope>): <short description in English>

[optional body: what changed and why, in English]

[optional footer: Breaking changes, issue refs]
```

#### Typy commitów:
| Typ | Kiedy |
|-----|-------|
| `feat` | Nowa funkcjonalność |
| `fix` | Naprawa błędu |
| `refactor` | Refaktoryzacja bez zmiany zachowania |
| `test` | Dodanie lub modyfikacja testów |
| `docs` | Zmiany tylko w dokumentacji |
| `chore` | Konfiguracja, build, narzędzia |
| `perf` | Optymalizacja wydajności |

#### Scope — komponent systemu:
| Scope | Kiedy |
|-------|-------|
| `router` | CF Worker Router (`workers/router/`) |
| `consumer` | CF Worker Consumer (`workers/consumer/`) |
| `ps-module` | PrestaShop module (`prestashop-module/`) |
| `apps-script` | Google Apps Script (`apps-script/`) |
| `shared` | Współdzielone zasoby (`shared/`) |
| `config` | Konfiguracja projektu (wrangler.toml, package.json) |
| `docs` | Dokumentacja (CLAUDE.md, DECISIONS.md, itp.) |
| `ci` | CI/CD, GitHub Actions |

### Procedura commitowania i pushowania

#### 1. Przegląd zmian przed commitem
```powershell
git status
git diff --stat
```

#### 2. Staging — stage ONLY relevant files
```powershell
# Pojedyncze pliki:
git add path/to/file.js path/to/another.php

# Katalog:
git add workers/router/src/

# NIGDY: git add . (bez przeglądu co jest w staging)
```

#### 3. Generowanie opisu commita — zasady
- **Język**: ZAWSZE angielski
- **Czas**: imperatyw ("Add feature", nie "Added feature")
- **Długość tytułu**: max 72 znaki
- **Treść body**: opisz CO i DLACZEGO (nie JAK)
- **Nie opisuj** oczywistości ("update file", "fix bug")

#### Przykłady dobrych commitów:
```
feat(router): add HMAC-SHA256 authentication middleware

Implements constant-time signature verification using Web Crypto API.
Rejects requests older than 300 seconds to prevent replay attacks.
```

```
fix(ps-module): prevent race condition in image assignment

Check product existence before assigning images. Products deleted
between import and CRON execution no longer cause fatal errors.
```

```
refactor(consumer): extract backoff logic to separate utility

Moves retry delay calculation out of queue handler for reusability
and testability. Delays: 10s, 30s, 60s for attempts 1, 2, 3+.
```

```
test(router): add edge case coverage for batch service

Tests empty array input, single product, and max batch size (50).
Uses fixtures from /shared/fixtures/edge-cases.json.
```

#### 4. Commit
```powershell
git commit -m "feat(router): add HMAC authentication middleware

Implements constant-time signature verification using Web Crypto API.
Rejects requests older than 300 seconds to prevent replay attacks."
```

#### 5. Push
```powershell
# Standardowy push:
git push origin main

# Pierwszy push nowej gałęzi:
git push -u origin <branch-name>
```

### PRO TIP DLA AGENTA (Omijanie zawieszania konsoli na Windows/PowerShell):
Gdy uruchamiasz w narzędziu komendy `git commit` a zwłaszcza `git push`, procesy potrafią zablokować się podtrzymując wyjścia. Dlatego **ZAWSZE** określaj bardzo niski `WaitDurationSeconds` (np. 5 lub 10 sekund) w narzędziu `command_status`. Jeśli komenda nie odpowie (timeout), zignoruj to pod warunkiem, że weryfikacja (np. ponowy `git log` lub `git status`) pokaże że się powiodło. Nigdy nie czekaj domyślnych 300s na `git push`!

### Zakazy bezwzględne
- NIE używaj `git add .` bez wcześniejszego `git status`
- NIE commituj: `node_modules/`, `.wrangler/`, `vendor/`, `.env`
- NIE commituj plików z sekretami (sprawdź `.gitignore`)
- NIE używaj `--force` push na `main`
- NIE pisz opisów po polsku
- NIE używaj vague messages ("fix", "update", "changes", "wip")

### .gitignore — pliki nigdy nie commitowane
```
node_modules/
.wrangler/
vendor/
.env
*.log
.DS_Store
```

### Automatyczne generowanie opisu commita

Gdy agent generuje opis commita autonomicznie, musi:
1. Uruchomić `git diff --cached` lub `git diff HEAD` i przeanalizować zmiany
2. Zidentyfikować typ zmiany (feat/fix/refactor/test/docs/chore)
3. Zidentyfikować scope na podstawie zmienionych ścieżek
4. Napisać zwięzły, imperatywny opis po angielsku
5. W body wyjaśnić kontekst jeśli zmiana jest nieoczywista

#### Mapowanie ścieżek na scope:
```
workers/router/*       → router
workers/consumer/*     → consumer
prestashop-module/*    → ps-module
apps-script/*          → apps-script
shared/*               → shared
*.toml / package.json  → config
*.md                   → docs
.github/*              → ci
.agent/*               → docs
```
