-- PrestaBridge module SQL uninstall
-- Prefix PREFIX_ jest zamieniany dynamicznie przez moduł na wartość _DB_PREFIX_

DROP TABLE IF EXISTS `PREFIX_prestabridge_image_queue`;
DROP TABLE IF EXISTS `PREFIX_prestabridge_log`;
DROP TABLE IF EXISTS `PREFIX_prestabridge_import_tracking`;
