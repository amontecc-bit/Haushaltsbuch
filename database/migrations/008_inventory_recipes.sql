-- Vorrat (Inventar) je virtuellem Posten, Rezepte mit Stückliste und Klassifikation, automatische Einkaufsliste

-- Referenzmenge (z. B. 1 l Milch), Standard-Verbrauchsdauer, Mindestbestand, Grundvorrat, automatisch nachkaufen,
-- seit wann der Posten fehlt (damit ein abgehakter Eintrag der Auto-Liste nicht sofort wieder auftaucht)
ALTER TABLE shopping_items
    ADD COLUMN ref_quantity DECIMAL(10,3) NOT NULL DEFAULT 1,
    ADD COLUMN ref_unit VARCHAR(10) NULL,
    ADD COLUMN shelf_days INT UNSIGNED NULL,
    ADD COLUMN min_stock DECIMAL(10,3) NOT NULL DEFAULT 0,
    ADD COLUMN staple TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN auto_restock TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN depleted_at DATETIME NULL;

-- Ausgleichsfaktor zur Referenzmenge (0,5-l-Packung = 0,5) und Verbrauchsdauer je echtem Produkt
ALTER TABLE shopping_item_products
    ADD COLUMN factor DECIMAL(10,4) NOT NULL DEFAULT 1,
    ADD COLUMN shelf_days INT UNSIGNED NULL;

-- Die automatisch gepflegte Einkaufsliste
ALTER TABLE shopping_lists ADD COLUMN is_auto TINYINT(1) NOT NULL DEFAULT 0;

-- Chargen: jeder Kauf (oder jede Korrektur nach oben) ergibt eine Charge mit Restmenge in Referenzeinheiten
CREATE TABLE inventory_lots (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id      INT UNSIGNED NOT NULL,
    shopping_item_id  INT UNSIGNED NOT NULL,
    product_id        INT UNSIGNED NULL,
    purchase_id       INT UNSIGNED NULL,
    quantity          DECIMAL(10,3) NOT NULL,
    remaining         DECIMAL(10,3) NOT NULL,
    acquired_on       DATE NOT NULL,
    expires_on        DATE NULL,
    source            ENUM('purchase','manual') NOT NULL DEFAULT 'purchase',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lot_item (household_id, shopping_item_id),
    KEY idx_lot_purchase (purchase_id),
    CONSTRAINT fk_lot_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_lot_item FOREIGN KEY (shopping_item_id) REFERENCES shopping_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_lot_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_lot_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    name          VARCHAR(190) NOT NULL,
    servings      SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    instructions  MEDIUMTEXT NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_recipe_household (household_id, name),
    CONSTRAINT fk_recipe_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipe_ingredients (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_id         INT UNSIGNED NOT NULL,
    shopping_item_id  INT UNSIGNED NOT NULL,
    quantity          DECIMAL(10,3) NOT NULL DEFAULT 1,
    unit              VARCHAR(10) NULL,
    note              VARCHAR(190) NULL,
    sort_order        INT NOT NULL DEFAULT 0,
    KEY idx_ingr_item (shopping_item_id),
    CONSTRAINT fk_ingr_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ingr_item FOREIGN KEY (shopping_item_id) REFERENCES shopping_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Klassifikation: Gruppen (z. B. „Mahlzeit“) mit Werten (morgens/mittags/abends), je Rezept mehrere Werte
CREATE TABLE recipe_class_groups (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    name          VARCHAR(80) NOT NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_rcg_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipe_class_values (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id    INT UNSIGNED NOT NULL,
    name        VARCHAR(80) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_rcv_group FOREIGN KEY (group_id) REFERENCES recipe_class_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipe_class_links (
    recipe_id  INT UNSIGNED NOT NULL,
    value_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (recipe_id, value_id),
    KEY idx_rcl_value (value_id),
    CONSTRAINT fk_rcl_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
    CONSTRAINT fk_rcl_value FOREIGN KEY (value_id) REFERENCES recipe_class_values(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
