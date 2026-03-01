---
name: repomix
description: Użyj tego skilla gdy zadanie dotyczy tworzenia repomix całego projektu — zawiera dokładną komendę z wykluczeniami zbędnych plików, format markdown, schemat nazwy z datą oraz czyszczenie poprzednich wersji.
---

# SKILL: repomix

### Kiedy aktywować
Padło hasło "update repomix", "stwórz repomix", "odśwież repomix" lub podobne.

### Format nazwy pliku
```
repomix_MM-DD-HH-mm.md
```
Przykład: `repomix_03-29-14-52.md`

- **Bez roku**, **bez sekund**
- Separator: myślnik (`-`)
- Zawsze w **głównym katalogu** projektu (`/`)

### Procedura — krok po kroku

#### 1. Usuń poprzednie pliki repomix
```powershell
# Windows (PowerShell):
Remove-Item "c:\NAS\Projekt-MeriPrestaBridge\repomix_*.md" -Force -ErrorAction SilentlyContinue
```

#### 2. Wygeneruj nazwę pliku z aktualną datą i godziną
```powershell
# PowerShell — generuje nazwę zgodną z formatem:
$timestamp = Get-Date -Format "MM-dd-HH-mm"
$outputFile = "repomix_$timestamp.md"
Write-Host "Output: $outputFile"
```

#### 3. Uruchom repomix
```powershell
npx repomix `
  --style markdown `
  --output $outputFile `
  --ignore "node_modules/**,vendor/**,.wrangler/**,.git/**,*.log,.env,.env.local,.dev.vars,.phpunit.cache/**,coverage/**,repomix_*.md,.idea/**,.vscode/**,*.lock,package-lock.json,composer.lock" `
  --quiet
```

### Pełna komenda jednolinijkowa (PowerShell)
```powershell
Remove-Item "repomix_*.md" -Force -ErrorAction SilentlyContinue; $ts = Get-Date -Format "MM-dd-HH-mm"; npx repomix --style markdown --output "repomix_$ts.md" --ignore "node_modules/**,vendor/**,.wrangler/**,.git/**,*.log,.env,.env.local,.dev.vars,.phpunit.cache/**,coverage/**,repomix_*.md,.idea/**,.vscode/**,*.lock,package-lock.json,composer.lock" --quiet; Write-Host "Created: repomix_$ts.md"
```

### Pliki zawsze wykluczane z repomix

| Wzorzec | Powód |
|---------|-------|
| `node_modules/**` | Dependencies — zbędne |
| `vendor/**` | PHP dependencies — zbędne |
| `.wrangler/**` | CF Workers build cache |
| `.git/**` | Historia git — zbędna |
| `*.log` | Logi — zbędne |
| `.env`, `.env.local`, `.dev.vars` | Sekrety — nigdy nie eksportujemy |
| `.phpunit.cache/**` | Cache testów |
| `coverage/**` | Raporty pokrycia testów |
| `repomix_*.md` | Poprzednie wersje repomix |
| `.idea/**`, `.vscode/**` | Ustawienia IDE |
| `*.lock`, `package-lock.json`, `composer.lock` | Lockfiles — zbędne dla AI |

### Zakazy
- NIE używaj domyślnej nazwy `repomix-output.xml` — zawsze używaj formatu z datą
- NIE pomijaj kroku usuwania poprzednich wersji
- NIE używaj formatu XML ani JSON — tylko `--style markdown`
- NIE commituj pliku repomix do repozytorium
