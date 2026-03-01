<?php

declare(strict_types = 1)
;

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
        /** @var array<string, mixed> */
        private static array $postData = [];

        public static function getValue(string $key, mixed $default = false): mixed
        {
            return self::$postData[$key] ?? $default;
        }

        public static function isSubmit(string $key): bool
        {
            return isset(self::$postData[$key]);
        }

        public static function getAdminTokenLite(string $className): string
        {
            return 'test_token';
        }

        /** Seed POST data for tests */
        public static function seedPost(array $data): void
        {
            self::$postData = $data;
        }

        /** Reset POST data */
        public static function reset(): void
        {
            self::$postData = [];
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

        public function executeS(string $sql): array
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
            ): bool
        {
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
// Smarty mock
// ============================================================
if (!class_exists('Smarty')) {
    class Smarty
    {
        public array $assignedVars = [];

        public function assign(array $vars): void
        {
            $this->assignedVars = array_merge($this->assignedVars, $vars);
        }

        public function reset(): void
        {
            $this->assignedVars = [];
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
        public Smarty $smarty;
        public Link $link;

        private static ?Context $instance = null;

        public function __construct()
        {
            $this->shop = new stdClass();
            $this->shop->id = 1;
            $this->language = new stdClass();
            $this->language->id = 1;
            $this->smarty = new Smarty();
            $this->link = new Link();
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
        public Context $context;

        public function __construct()
        {
            // Mirrors real PrestaShop Module::__construct() which sets $this->context
            $this->context = Context::getContext();
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

        public function display(string $file, string $template): string
        {
            return '<!-- tpl:' . basename($template) . ' -->';
        }

        public function displayConfirmation(string $msg): string
        {
            return '<div class="alert alert-success">' . htmlspecialchars($msg) . '</div>';
        }

        public function displayError(string $msg): string
        {
            return '<div class="alert alert-danger">' . htmlspecialchars($msg) . '</div>';
        }
    }
}

// ============================================================
// HelperForm mock
// ============================================================
if (!class_exists('HelperForm')) {
    class HelperForm
    {
        public mixed $module = null;
        public string $name_controller = '';
        public string $token = '';
        public string $currentIndex = '';
        public string $submit_action = '';
        public int $default_form_language = 1;
        public int $allow_employee_form_lang = 0;
        public string $title = '';
        public bool $show_toolbar = false;
        public array $fields_value = [];

        public function generateForm(array $fields_form): string
        {
            return '<!-- HelperForm:generated -->';
        }
    }
}

// ============================================================
// AdminController mock
// ============================================================
if (!class_exists('AdminController')) {
    class AdminController
    {
        public static string $currentIndex = 'index.php?controller=AdminModules';
    }
}

// ============================================================
// Category mock
// ============================================================
if (!class_exists('Category')) {
    class Category
    {
        private static array $categories = [
            ['id_category' => 2, 'name' => 'Home'],
        ];

        public static function getSimpleCategories(int $id_lang): array
        {
            return self::$categories;
        }

        public static function setCategories(array $categories): void
        {
            self::$categories = $categories;
        }

        public static function reset(): void
        {
            self::$categories = [['id_category' => 2, 'name' => 'Home']];
        }
    }
}

// ============================================================
// Link mock (for context->link->getModuleLink())
// ============================================================
if (!class_exists('Link')) {
    class Link
    {
        public function getModuleLink(string $module, string $controller, array $params = []): string
        {
            $query = http_build_query($params);
            return 'https://shop.example.com/module/' . $module . '/' . $controller . '?' . $query;
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
// PHP extension function mocks (fileinfo, exif)
// These may not be available in all PHP CLI installations
// ============================================================
if (!defined('FILEINFO_MIME_TYPE')) {
    define('FILEINFO_MIME_TYPE', 16);
}

if (!class_exists('finfo')) {
    class finfo
    {
        private int $flags;

        public function __construct(int $flags = 0)
        {
            $this->flags = $flags;
        }

        public function file(string $filename): string
        {
            return mime_content_type($filename);
        }
    }
}
if (!function_exists('mime_content_type')) {
    function mime_content_type(string $filename): string
    {
        // Detect by magic bytes
        $handle = fopen($filename, 'rb');
        if (!$handle) {
            return 'application/octet-stream';
        }
        $header = fread($handle, 4);
        fclose($handle);

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($header, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($header, "GIF8")) {
            return 'image/gif';
        }
        if (str_starts_with($header, "RIFF")) {
            return 'image/webp';
        }
        return 'application/octet-stream';
    }
}

if (!function_exists('exif_imagetype')) {
    function exif_imagetype(string $filename): int|false
    {
        $handle = fopen($filename, 'rb');
        if (!$handle) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return IMAGETYPE_JPEG;
        }
        if (str_starts_with($header, "\x89PNG")) {
            return IMAGETYPE_PNG;
        }
        if (str_starts_with($header, "GIF8")) {
            return IMAGETYPE_GIF;
        }
        if (str_starts_with($header, "RIFF")) {
            return IMAGETYPE_WEBP;
        }
        return false;
    }
}

if (!function_exists('image_type_to_extension')) {
    function image_type_to_extension(int $type, bool $dot = true): string|false
    {
        $prefix = $dot ? '.' : '';
        return match ($type) {
                IMAGETYPE_JPEG => $prefix . 'jpg',
                IMAGETYPE_PNG => $prefix . 'png',
                IMAGETYPE_GIF => $prefix . 'gif',
                IMAGETYPE_WEBP => $prefix . 'webp',
                default => false,
            };
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
