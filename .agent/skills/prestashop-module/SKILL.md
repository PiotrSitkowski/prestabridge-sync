---
name: prestashop-module
description: Użyj tego skilla gdy zadanie dotyczy plików w /prestashop-module/prestabridge/ — zawiera wzorce ObjectModel, HelperForm, SQL security, PHPUnit oraz zakazy bezpośrednich zapytań SQL do produktów.
---

# SKILL: prestashop-module

### Kiedy aktywować
Zadanie dotyczy plików w `/prestashop-module/prestabridge/`.

### Kontekst technologiczny
- PrestaShop **8.1+** na PHP **8.1+**
- Natywne klasy PS: Product, Image, Category, Configuration, Db, Context, StockAvailable, ImageType, ImageManager, Validate, Tools
- Namespace: `PrestaBridge\{Subdir}`
- Autoload: PSR-4 via composer.json
- Standard kodowania: **PSR-12**
- Type hints: **pełne** (return types, param types, property types)

### Wzorce obowiązkowe

#### Klasa modułu:
```php
class PrestaBridge extends Module
{
    public function __construct()
    {
        $this->name = 'prestabridge';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'PrestaBridge Team';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('PrestaBridge');
        $this->description = $this->l('Product synchronization bridge');
    }
}
```

#### Front controller (API):
```php
class PrestaBridgeApiModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->ajax = true;
        header('Content-Type: application/json');
        // ... logika
        die(json_encode($response));
    }
}
```

#### SQL bezpieczeństwo — ZAWSZE:
```php
// String:
'WHERE reference = \'' . pSQL($sku) . '\''
// Integer:
'WHERE id_product = ' . (int) $id
// NIGDY:
'WHERE reference = \'' . $sku . '\''  // SQL INJECTION!
```

#### Product ObjectModel:
```php
$product = new Product();
$id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$product->name[$id_lang] = 'Nazwa';
$product->price = 29.99;  // cena NETTO
$product->reference = 'SKU-001';
$product->id_category_default = $categoryId;
$product->add();
$product->updateCategories([$categoryId]);
StockAvailable::setQuantity((int) $product->id, 0, $quantity);
```

#### Image ObjectModel:
```php
$image = new Image();
$image->id_product = $productId;
$image->position = $position;
$image->cover = $isCover ? 1 : 0;
$image->add();
$newPath = $image->getPathForCreation();
// kopiuj plik + ImageManager::resize() dla thumbnails
```

### Zakazy bezwzględne
- NIE używaj Webservice API do importu (używamy ObjectModel)
- NIE używaj raw SQL INSERT do produktów (używamy Product::add())
- NIE pomijaj pSQL() i (int) casting w zapytaniach
- NIE hardcoduj ścieżek (/var/www/...) — używaj _PS_ROOT_DIR_, _PS_IMG_DIR_
- NIE modyfikuj natywnych tabel PS
- NIE używaj die() poza controllerami
- NIE pomijaj try/catch przy operacjach na Product/Image

### Testy
- Framework: **PHPUnit 10**
- Bootstrap: `tests/bootstrap.php` z mockami klas PS
- Mocki konieczne: Configuration, Db, Product, Image, StockAvailable, Context, ImageType, ImageManager
- Fixtures: loaduj z `/shared/fixtures/` via `file_get_contents` + `json_decode`
