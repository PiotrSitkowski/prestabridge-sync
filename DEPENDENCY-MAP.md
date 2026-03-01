# DEPENDENCY-MAP.md — Mapa zależności komponentów PrestaBridge

## Diagram przepływu danych (Mermaid)

```mermaid
graph TD
    subgraph "Google Workspace"
        GS[Google Sheets<br/>Dane produktowe]
        AS[Apps Script<br/>Code.gs]
    end

    subgraph "CloudFlare Edge"
        WR[Worker Router<br/>prestabridge-router]
        Q[Queue<br/>prestabridge-product-queue]
        DLQ[Dead Letter Queue<br/>prestabridge-dlq]
        WC[Worker Consumer<br/>prestabridge-consumer]
    end

    subgraph "VPS / PrestaShop"
        API[PS Controller<br/>/module/prestabridge/api]
        CRON[PS Controller<br/>/module/prestabridge/cron]
        IMP[ProductImporter]
        VAL[ProductValidator]
        MAP[ProductMapper]
        DUP[DuplicateChecker]
        IQM[ImageQueueManager]
        IDL[ImageDownloader]
        IAS[ImageAssigner]
        ILM[ImageLockManager]
        LOG[BridgeLogger]
        CFG[ModuleConfig]
        TRK[ImportTracker]
        DB[(MySQL<br/>ps_product<br/>ps_prestabridge_*)]
        CRONTAB[crontab<br/>*/2 * * * *]
    end

    GS -->|checkboxy + dane| AS
    AS -->|POST JSON + HMAC| WR
    WR -->|walidacja + batching| Q
    Q -->|batch consume| WC
    Q -->|po 3 retries| DLQ
    WC -->|POST JSON + HMAC| API

    API --> VAL
    API --> DUP
    API --> IMP
    API --> IQM
    API --> TRK
    API --> LOG

    IMP --> MAP
    IMP --> DUP
    IMP --> DB

    CRONTAB -->|GET + token| CRON
    CRON --> IQM
    CRON --> IDL
    CRON --> IAS
    CRON --> TRK
    CRON --> LOG

    IQM --> DB
    IDL -->|HTTP GET| EXT[Zewnętrzne serwery zdjęć]
    IAS --> DB
    LOG --> DB
    TRK --> DB
    CFG --> DB

    style WR fill:#f96,stroke:#333
    style WC fill:#f96,stroke:#333
    style Q fill:#ff9,stroke:#333
    style DLQ fill:#fcc,stroke:#333
    style API fill:#9cf,stroke:#333
    style CRON fill:#9cf,stroke:#333
```

## Kolejność implementacji (zależności wyznaczają ścieżkę krytyczną)

```mermaid
graph LR
    E1[Etap 1<br/>Shared Schemas<br/>+ Fixtures] --> E2[Etap 2<br/>CF Router]
    E1 --> E4[Etap 4<br/>PS Module Core]
    E2 --> E3[Etap 3<br/>CF Consumer]
    E4 --> E5[Etap 5<br/>PS Images]
    E4 --> E6[Etap 6<br/>PS Controllers]
    E5 --> E6
    E6 --> E7[Etap 7<br/>PS Admin UI]
    E3 --> E9[Etap 9<br/>Integration + Deploy]
    E7 --> E9
    E1 --> E8[Etap 8<br/>Apps Script]
    E8 --> E9
    E9 --> E10[Etap 10<br/>Docs finalne]

    style E1 fill:#dfd
    style E9 fill:#ffd
    style E10 fill:#fdd
```

## Ścieżka krytyczna

```
Etap 1 → Etap 4 → Etap 5 → Etap 6 → Etap 7 → Etap 9 → Etap 10
```

Elementy równoległe:
- Etap 2 (CF Router) i Etap 4 (PS Core) mogą być robione równolegle po Etapie 1
- Etap 8 (Apps Script) może być robiony równolegle z Etapami 4-7
- Etap 3 (CF Consumer) może być robiony zaraz po Etapie 2

## Macierz zależności klas PS

| Klasa | Zależy od | Jest używana przez |
|-------|-----------|-------------------|
| ModuleConfig | Configuration (PS) | Wszystkie klasy |
| BridgeLogger | Db (PS), ModuleConfig | Wszystkie klasy |
| HmacAuthenticator | — (standalone) | api.php controller |
| ProductValidator | — (standalone) | api.php, ProductImporter |
| DuplicateChecker | Db (PS) | api.php, ProductImporter |
| ProductMapper | Configuration (PS), Context (PS), ModuleConfig | ProductImporter |
| ProductImporter | ProductMapper, DuplicateChecker, BridgeLogger, Product (PS), StockAvailable (PS) | api.php controller |
| ImageQueueManager | Db (PS) | api.php, cron.php |
| ImageDownloader | ModuleConfig | cron.php |
| ImageAssigner | Product (PS), Image (PS), ImageType (PS), ImageManager (PS), BridgeLogger | cron.php |
| ImportTracker | Db (PS) | api.php, cron.php |

## Macierz zależności Worker modules

| Moduł | Zależy od | Jest używany przez |
|-------|-----------|-------------------|
| hmac.js | Web Crypto API | authMiddleware, authSigner |
| response.js | — | index.js, importHandler |
| logger.js | — | Wszystkie moduły |
| authMiddleware.js | hmac.js | index.js (Router) |
| authSigner.js | hmac.js | prestashopClient.js |
| validationService.js | — (standalone) | importHandler.js |
| batchService.js | — (standalone) | importHandler.js |
| queueService.js | — | importHandler.js |
| importHandler.js | validationService, batchService, queueService, logger, response | index.js (Router) |
| prestashopClient.js | authSigner, logger | queueHandler.js |
| queueHandler.js | prestashopClient, logger | index.js (Consumer) |
