-- PIMS schema. Requires MySQL 8.0.16+ (CHECK constraints are enforced from that version).

CREATE DATABASE IF NOT EXISTS pims CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pims;

CREATE TABLE categories (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(255) NOT NULL,
  description    TEXT NULL,
  woocommerce_id BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE products (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku                 VARCHAR(100) NOT NULL,
  name                VARCHAR(255) NOT NULL,
  description         TEXT NULL,
  category_id         INT UNSIGNED NOT NULL,
  brand               VARCHAR(255) NULL,
  price               DECIMAL(10,2) NOT NULL,
  stock               INT NOT NULL DEFAULT 0,
  status              ENUM('draft','active','inactive','archived') NOT NULL DEFAULT 'draft',
  version             INT UNSIGNED NOT NULL DEFAULT 1,
  woocommerce_id      BIGINT UNSIGNED NULL,
  sync_status         ENUM('NOT_SYNCED','SYNCING','SYNCED','FAILED','CONFLICT') NOT NULL DEFAULT 'NOT_SYNCED',
  last_synced_at      TIMESTAMP NULL,
  last_sync_direction VARCHAR(20) NULL,
  last_sync_error     TEXT NULL,
  wc_snapshot         JSON NULL,
  conflict_fields     JSON NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  UNIQUE KEY uq_products_woocommerce_id (woocommerce_id),
  KEY idx_products_category_status (category_id, status),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
  CONSTRAINT chk_products_price CHECK (price >= 0),
  CONSTRAINT chk_products_stock CHECK (stock >= 0)
) ENGINE=InnoDB;

CREATE TABLE attributes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(255) NOT NULL,
  data_type      ENUM('text','number','boolean','select','unit') NOT NULL,
  unit           VARCHAR(50) NULL,
  woocommerce_id BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attributes_name (name)
) ENGINE=InnoDB;

CREATE TABLE attribute_options (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attribute_id        INT UNSIGNED NOT NULL,
  value               VARCHAR(255) NOT NULL,
  woocommerce_term_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_option_per_attribute (attribute_id, value),
  CONSTRAINT fk_options_attribute FOREIGN KEY (attribute_id) REFERENCES attributes (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE category_attributes (
  category_id  INT UNSIGNED NOT NULL,
  attribute_id INT UNSIGNED NOT NULL,
  required     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (category_id, attribute_id),
  CONSTRAINT fk_catattr_category  FOREIGN KEY (category_id)  REFERENCES categories (id) ON DELETE CASCADE,
  CONSTRAINT fk_catattr_attribute FOREIGN KEY (attribute_id) REFERENCES attributes (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE product_attributes (
  product_id    INT UNSIGNED NOT NULL,
  attribute_id  INT UNSIGNED NOT NULL,
  value_text    TEXT NULL,
  value_number  DECIMAL(18,4) NULL,
  value_boolean TINYINT(1) NULL,
  option_id     INT UNSIGNED NULL,
  PRIMARY KEY (product_id, attribute_id),
  CONSTRAINT fk_prodattr_product   FOREIGN KEY (product_id)   REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_prodattr_attribute FOREIGN KEY (attribute_id) REFERENCES attributes (id) ON DELETE RESTRICT,
  CONSTRAINT fk_prodattr_option    FOREIGN KEY (option_id)    REFERENCES attribute_options (id) ON DELETE RESTRICT,
  -- exactly one value column is populated
  CONSTRAINT chk_prodattr_one_value CHECK (
    (value_text    IS NOT NULL) +
    (value_number  IS NOT NULL) +
    (value_boolean IS NOT NULL) +
    (option_id     IS NOT NULL) = 1
  )
) ENGINE=InnoDB;

CREATE TABLE sync_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NULL,
  direction   VARCHAR(20) NOT NULL,
  outcome     VARCHAR(20) NOT NULL,
  error       TEXT NULL,
  http_status SMALLINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sync_logs_product (product_id),
  CONSTRAINT fk_synclogs_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;
