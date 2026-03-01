/**
 * Product schema constants for CF Worker Router.
 * Derived from shared/schemas/product-payload.json.
 * Manual constants — NO JSON Schema library (CF Workers Free Tier restriction).
 */

export const PRODUCT_SCHEMA = {
    SKU_MAX_LENGTH: 64,
    NAME_MAX_LENGTH: 128,
    DESCRIPTION_MAX_LENGTH: 65535,
    DESCRIPTION_SHORT_MAX_LENGTH: 800,
    META_TITLE_MAX_LENGTH: 128,
    META_DESCRIPTION_MAX_LENGTH: 512,
    EAN13_MAX_LENGTH: 13,
    PRICE_MIN: 0, // exclusive (price > 0)
    QUANTITY_MIN: 0, // inclusive (quantity >= 0)
    WEIGHT_MIN: 0,  // inclusive (weight >= 0)
};

export const REQUEST_SCHEMA = {
    PRODUCTS_MAX: 1000,
    BATCH_SIZE_MIN: 1,
    BATCH_SIZE_MAX: 50,
    BATCH_SIZE_DEFAULT: 5,
};
