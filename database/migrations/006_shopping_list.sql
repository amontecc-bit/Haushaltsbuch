-- Einkaufsliste: virtuelle Posten (z. B. „Milch“), denen mehrere echte Produkte aus den Einkäufen zugeordnet sind

CREATE TABLE shopping_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    name          VARCHAR(190) NOT NULL,
    normalized    VARCHAR(190) NOT NULL,
    category_id   INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shopping_item (household_id, normalized),
    CONSTRAINT fk_shi_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_shi_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shopping_item_products (
    shopping_item_id  INT UNSIGNED NOT NULL,
    product_id        INT UNSIGNED NOT NULL,
    PRIMARY KEY (shopping_item_id, product_id),
    KEY idx_sip_product (product_id),
    CONSTRAINT fk_sip_item FOREIGN KEY (shopping_item_id) REFERENCES shopping_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_sip_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shopping_lists (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_shl_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_shl_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shopping_list_entries (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id           INT UNSIGNED NOT NULL,
    shopping_item_id  INT UNSIGNED NOT NULL,
    quantity          DECIMAL(10,3) NOT NULL DEFAULT 1,
    unit              VARCHAR(10) NULL,
    note              VARCHAR(190) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    done_at           DATETIME NULL,
    purchase_id       INT UNSIGNED NULL,
    UNIQUE KEY uq_list_item (list_id, shopping_item_id),
    CONSTRAINT fk_sle_list FOREIGN KEY (list_id) REFERENCES shopping_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_sle_item FOREIGN KEY (shopping_item_id) REFERENCES shopping_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_sle_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
