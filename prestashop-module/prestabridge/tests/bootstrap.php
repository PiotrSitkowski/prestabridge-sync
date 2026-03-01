<?php

declare(strict_types=1);

// ============================================================
// PrestaShop Constants (for tests)
// ============================================================
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}
if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', '/var/www/prestashop');
}
if (!defined('_PS_IMG_DIR_')) {
    define('_PS_IMG_DIR_', '/var/www/prestashop/img/');
}

// ============================================================
// pSQL() mock — strips single quotes to prevent SQL injection
// ============================================================
if (!function_exists('pSQL')) {
    function pSQL(string|null $string, bool $htmlOk = false): string
    {
        if ($string === null) {
            return '';
        }
        return str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $string);
    }
}

// ============================================================
// Tools mock
// ============================================================
if (!class_exists('Tools')) {
    class Tools
    {
        public static function getValue(string $key, mixed $default = false): mixed
        {
            return $default;
        }
    }
}

// ============================================================
// Configuration mock
// ============================================================
if (!class_exists('Configuration')) {
    class Configuration
    {
        private static array $data = [];

        public static function get(string $key): mixed
        {
            return self::$data[$key] ?? false;
        }

        public static function updateValue(string $key, mixed $value): bool
        {
            self::$data[$key] = $value;
            return true;
        }

        public static function deleteByName(string $key): bool
        {
            unset(self::$data[$key]);
            return true;
        }

        public static function hasKey(string $key): bool
        {
            return isset(self::$data[$key]);
        }

        /** Reset all configuration (use in tests) */
        public static function reset(): void
        {
            self::$data = [];
        }

        /** Seed configuration values (use in tests) */
        public static function seed(array $data): void
        {
            self::$data = array_merge(self::$data, $data);
        }
    }
}

// ============================================================
// Db mock — singleton with spy capability
// ============================================================
if (!class_exists('Db')) {
    class Db
    {
        private static ?Db $instance = null;

        public array $insertCalls = [];
        public array $updateCalls = [];
        public array $executeCalls = [];
        public ?string $lastQuery = null;
        public mixed $returnValue = false;
        public array $returnRows = [];

        public static function getInstance(): Db
        {
            if (self::$instance === null) {
                self::$instance = new Db();
            }
            return self::$instance;
        }

        public function insert(string $table, array $data): bool
        {
            $this->insertCalls[] = ['table' => $table, 'data' => $data];
            return true;
        }

        public function update(string $table, array $data, string|bool $where = false): bool
        {
            $this->updateCalls[] = ['table' => $table, 'data' => $data, 'where' => $where];
            return true;
        }

        public function execute(string $sql): bool
        {
            $this->executeCalls[] = $sql;
            $this->lastQuery = $sql;
            return true;
        }

        public function executeS(string $sql): array|false
        {
            $this->lastQuery = $sql;
            return $this->returnRows;
        }

        public function getValue(string $sql): mixed
        {
            $this->lastQuery = $sql;
            return $this->returnValue;
        }

        /** Reset all spy state (use in setUp) */
        public static function reset(): void
        {
            self::$instance = null;
        }

        public function setReturnValue(mixed $value): void
        {
            $this->returnValue = $value;
        }

        public function setReturnRows(array $rows): void
        {
            $this->returnRows = $rows;
        }
    }
}

// ============================================================
// Product mock
// ============================================================
if (!class_exists('Product')) {
    class Product
    {
        public int|null $id = null;
        public array $name = [];
        public float $price = 0.0;
        public string $reference = '';
        public array $description = [];
        public array $description_short = [];
        public int $quantity = 0;
        public string $ean13 = '';
        public float $weight = 0.0;
        public bool $active = false;
        public array $meta_title = [];
        public array $meta_description = [];
        public int $id_category_default = 0;
        public int $id_shop_default = 0;

        private static bool $addResult = true;
        private static bool $updateResult = true;
        private static bool $existsResult = true;
        private static array $updatedCategories = [];

        public function __construct(int $id = 0)
        {
            if ($id > 0) {
                $this->id = $id;
            }
        }

        public function add(): bool
        {
            if (self::$addResult) {
                $this->id = $this->id ?? 42;
            }
            return self::$addResult;
        }

        public function update(): bool
        {
            return self::$updateResult;
        }

        public function updateCategories(array $categories): void
        {
            self::$updatedCategories = $categories;
        }

        public function save(): bool
        {
            return self::$addResult;
        }

        public static function existsInDatabase(int $id, string $table): bool
        {
            return self::$existsResult;
        }

        /** Test helpers */
        public static function setAddResult(bool $result): void
        {
            self::$addResult = $result;
        }

        public static function setUpdateResult(bool $result): void
        {
            self::$updateResult = $result;
        }

        public static function setExistsResult(bool $result): void
        {
            self::$existsResult = $result;
        }

        public static function getUpdatedCategories(): array
        {
            return self::$updatedCategories;
        }

        public static function reset(): void
        {
            self::$addResult = true;
            self::$updateResult = true;
            self::$existsResult = true;
            self::$updatedCategories = [];
        }
    }
}

// ============================================================
// StockAvailable mock
// ============================================================
if (!class_exists('StockAvailable')) {
    class StockAvailable
    {
        private static array $calls = [];

        public static function setQuantity(int $productId, int $attributeId, int $quantity): void
        {
            self::$calls[] = [$productId, $attributeId, $quantity];
        }

        public static function getCalls(): array
        {
            return self::$calls;
        }

        public static function reset(): void
        {
            self::$calls = [];
        }
    }
}

// ============================================================
// Image mock
// ============================================================
if (!class_exists('Image')) {
    class Image
    {
        public int $id = 0;
        public int $id_product = 0;
        public int $position = 0;
        public int $cover = 0;

        private static bool $addResult = true;
        private static ?string $pathForCreation = '/tmp/ps_img_test';
        private static array $deleteCoverCalls = [];

        public function add(): bool
        {
            if (self::$addResult) {
                $this->id = 99;
            }
            return self::$addResult;
        }

        public function delete(): bool
        {
            return true;
        }

        public function getPathForCreation(): string
        {
            return self::$pathForCreation ?? '/tmp/ps_img_test';
        }

        public static function deleteCover(int $productId): void
        {
            self::$deleteCoverCalls[] = $productId;
        }

        /** Test helpers */
        public static function setAddResult(bool $result): void
        {
            self::$addResult = $result;
        }

        public static function setPathForCreation(string $path): void
        {
            self::$pathForCreation = $path;
        }

        public static function getDeleteCoverCalls(): array
        {
            return self::$deleteCoverCalls;
        }

        public static function reset(): void
        {
            self::$addResult = true;
            self::$pathForCreation = '/tmp/ps_img_test';
            self::$deleteCoverCalls = [];
        }
    }
}

// ============================================================
// ImageType mock
// ============================================================
if (!class_exists('ImageType')) {
    class ImageType
    {
        private static array $types = [];

        public static function getImagesTypes(string $entity): array
        {
            return self::$types;
        }

        public static function setTypes(array $types): void
        {
            self::$types = $types;
        }

        public static function reset(): void
        {
            self::$types = [];
        }
    }
}

// ============================================================
// ImageManager mock
// ============================================================
if (!class_exists('ImageManager')) {
    class ImageManager
    {
        private static array $resizeCalls = [];

        public static function resize(
            string $src,
            string $dst,
            int $width,
            int $height,
            string $type = 'jpg'
        ): bool {
            self::$resizeCalls[] = compact('src', 'dst', 'width', 'height', 'type');
            return true;
        }

        public static function getResizeCalls(): array
        {
            return self::$resizeCalls;
        }

        public static function reset(): void
        {
            self::$resizeCalls = [];
        }
    }
}

// ============================================================
// Context mock
// ============================================================
if (!class_exists('Context')) {
    class Context
    {
        public object $shop;
        public object $language;

        private static ?Context $instance = null;

        public function __construct()
        {
            $this->shop = new stdClass();
            $this->shop->id = 1;
            $this->language = new stdClass();
            $this->language->id = 1;
        }

        public static function getContext(): Context
        {
            if (self::$instance === null) {
                self::$instance = new Context();
            }
            return self::$instance;
        }

        public static function reset(): void
        {
            self::$instance = null;
        }
    }
}

// ============================================================
// Module mock (base class)
// ============================================================
if (!class_exists('Module')) {
    abstract class Module
    {
        public string $name = '';
        public string $tab = '';
        public string $version = '';
        public string $author = '';
        public int $need_instance = 0;
        public bool $bootstrap = false;
        public string $displayName = '';
        public string $description = '';

        public function __construct()
        {
            // base stub
        }

        public function install(): bool
        {
            return true;
        }

        public function uninstall(): bool
        {
            return true;
        }

        public function l(string $string, string $specific = '', ?string $locale = null): string
        {
            return $string;
        }
    }
}

// ============================================================
// Validate mock
// ============================================================
if (!class_exists('Validate')) {
    class Validate
    {
        public static function isLoadedObject(mixed $object): bool
        {
            return $object !== null && $object->id > 0;
        }
    }
}

// ============================================================
// Autoloader (PSR-4 for PrestaBridge namespace)
// ============================================================
spl_autoload_register(function (string $class): void {
    $prefix = 'PrestaBridge\\';
    $baseDir = __DIR__ . '/../classes/';

    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
