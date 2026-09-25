-- Haushaltsbuch: Grundschema
SET NAMES utf8mb4;

CREATE TABLE households (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id    INT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','member','child') NOT NULL DEFAULT 'member',
    active          TINYINT(1) NOT NULL DEFAULT 1,
    failed_logins   INT NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT fk_users_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id     INT UNSIGNED NOT NULL,
    name             VARCHAR(100) NOT NULL,
    type             ENUM('giro','savings','cash','credit_card','other') NOT NULL DEFAULT 'giro',
    iban             VARCHAR(34) NULL,
    bank             VARCHAR(100) NULL,
    opening_balance  DECIMAL(12,2) NOT NULL DEFAULT 0,
    opening_date     DATE NOT NULL,
    color            VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
    include_in_forecast TINYINT(1) NOT NULL DEFAULT 1,
    archived         TINYINT(1) NOT NULL DEFAULT 0,
    sort_order       INT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE account_permissions (
    account_id  INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    can_view    TINYINT(1) NOT NULL DEFAULT 1,
    can_book    TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id, user_id),
    CONSTRAINT fk_perm_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_perm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    parent_id     INT UNSIGNED NULL,
    name          VARCHAR(100) NOT NULL,
    type          ENUM('expense','income') NOT NULL DEFAULT 'expense',
    icon          VARCHAR(50) NOT NULL DEFAULT 'tag',
    color         VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    sort_order    INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_cat_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_transactions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id     INT UNSIGNED NOT NULL,
    account_id       INT UNSIGNED NOT NULL,
    to_account_id    INT UNSIGNED NULL,
    category_id      INT UNSIGNED NULL,
    amount           DECIMAL(12,2) NOT NULL,
    payee            VARCHAR(190) NULL,
    purpose          VARCHAR(255) NULL,
    `interval`       ENUM('monthly','quarterly','halfyearly','yearly') NOT NULL DEFAULT 'monthly',
    day_of_month     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    start_date       DATE NOT NULL,
    end_date         DATE NULL,
    last_booked_date DATE NULL,
    auto_book        TINYINT(1) NOT NULL DEFAULT 1,
    active           TINYINT(1) NOT NULL DEFAULT 1,
    loan_id          INT UNSIGNED NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rec_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_rec_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_rec_to_account FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_rec_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_batches (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    account_id    INT UNSIGNED NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    rows_total    INT NOT NULL DEFAULT 0,
    rows_imported INT NOT NULL DEFAULT 0,
    rows_skipped  INT NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ib_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_ib_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id       INT UNSIGNED NOT NULL,
    account_id         INT UNSIGNED NOT NULL,
    booking_date       DATE NOT NULL,
    amount             DECIMAL(12,2) NOT NULL,
    payee              VARCHAR(190) NULL,
    purpose            TEXT NULL,
    category_id        INT UNSIGNED NULL,
    note               VARCHAR(255) NULL,
    source             ENUM('manual','csv','recurring','purchase') NOT NULL DEFAULT 'manual',
    import_hash        CHAR(40) NULL,
    import_batch_id    INT UNSIGNED NULL,
    recurring_id       INT UNSIGNED NULL,
    transfer_group     CHAR(32) NULL,
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tx_account_date (account_id, booking_date),
    KEY idx_tx_household_date (household_id, booking_date),
    KEY idx_tx_hash (account_id, import_hash),
    KEY idx_tx_transfer (transfer_group),
    CONSTRAINT fk_tx_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE csv_profiles (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id   INT UNSIGNED NULL,
    name           VARCHAR(100) NOT NULL,
    delimiter      VARCHAR(5) NOT NULL DEFAULT ';',
    encoding       VARCHAR(20) NOT NULL DEFAULT 'auto',
    skip_rows      INT NOT NULL DEFAULT 0,
    date_format    VARCHAR(20) NOT NULL DEFAULT 'd.m.Y',
    decimal_sep    VARCHAR(1) NOT NULL DEFAULT ',',
    mapping        JSON NOT NULL,
    header_signature VARCHAR(255) NULL,
    CONSTRAINT fk_csvp_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_rules (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    target        ENUM('transaction','item') NOT NULL DEFAULT 'transaction',
    field         ENUM('payee','purpose','any','name') NOT NULL DEFAULT 'any',
    operator      ENUM('contains','equals','starts','regex') NOT NULL DEFAULT 'contains',
    value         VARCHAR(190) NOT NULL,
    category_id   INT UNSIGNED NOT NULL,
    priority      INT NOT NULL DEFAULT 100,
    learned       TINYINT(1) NOT NULL DEFAULT 0,
    hits          INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rule_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_rule_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id         INT UNSIGNED NOT NULL,
    name                 VARCHAR(190) NOT NULL,
    normalized           VARCHAR(190) NOT NULL,
    default_category_id  INT UNSIGNED NULL,
    UNIQUE KEY uq_product (household_id, normalized),
    CONSTRAINT fk_prod_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_prod_category FOREIGN KEY (default_category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchases (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id    INT UNSIGNED NOT NULL,
    purchase_date   DATE NOT NULL,
    store           VARCHAR(150) NULL,
    account_id      INT UNSIGNED NULL,
    transaction_id  INT UNSIGNED NULL,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0,
    source          ENUM('manual','pdf','photo') NOT NULL DEFAULT 'manual',
    file_path       VARCHAR(255) NULL,
    raw_text        MEDIUMTEXT NULL,
    note            VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_purch_date (household_id, purchase_date),
    CONSTRAINT fk_purch_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_purch_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_purch_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_purch_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id   INT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED NULL,
    name          VARCHAR(190) NOT NULL,
    quantity      DECIMAL(10,3) NOT NULL DEFAULT 1,
    unit          VARCHAR(10) NULL,
    unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
    category_id   INT UNSIGNED NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    KEY idx_item_product (product_id),
    CONSTRAINT fk_item_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_item_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loans (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id       INT UNSIGNED NOT NULL,
    name               VARCHAR(120) NOT NULL,
    lender             VARCHAR(120) NULL,
    contract_number    VARCHAR(60) NULL,
    principal          DECIMAL(12,2) NOT NULL,
    payout_date        DATE NOT NULL,
    interest_rate      DECIMAL(6,3) NOT NULL,
    loan_type          ENUM('annuity','fixed_principal') NOT NULL DEFAULT 'annuity',
    monthly_payment    DECIMAL(12,2) NULL,
    initial_repayment_rate DECIMAL(6,3) NULL,
    first_payment_date DATE NOT NULL,
    fixed_until        DATE NULL,
    account_id         INT UNSIGNED NULL,
    note               TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_loan_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_loan_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_special_payments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id       INT UNSIGNED NOT NULL,
    payment_date  DATE NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    note          VARCHAR(255) NULL,
    CONSTRAINT fk_lsp_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE loan_rate_changes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id          INT UNSIGNED NOT NULL,
    valid_from       DATE NOT NULL,
    interest_rate    DECIMAL(6,3) NULL,
    monthly_payment  DECIMAL(12,2) NULL,
    note             VARCHAR(255) NULL,
    CONSTRAINT fk_lrc_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recurring_transactions
    ADD CONSTRAINT fk_rec_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE;

CREATE TABLE settings (
    household_id  INT UNSIGNED NOT NULL,
    `key`         VARCHAR(60) NOT NULL,
    value         TEXT NULL,
    PRIMARY KEY (household_id, `key`),
    CONSTRAINT fk_settings_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
