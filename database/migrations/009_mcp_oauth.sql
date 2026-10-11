-- MCP-Server für Claude (Einkaufsliste, Vorrat, Rezepte, Bargeld-Buchung) mit OAuth 2.1 für die Claude-App

-- Per dynamischer Registrierung angelegte Clients (z. B. claude.ai); Secret nur gehasht, bei öffentlichen Clients leer
CREATE TABLE oauth_clients (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id      VARCHAR(64) NOT NULL,
    secret_hash    CHAR(64) NULL,
    name           VARCHAR(190) NOT NULL,
    redirect_uris  TEXT NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_oauth_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kurzlebige Autorisierungscodes (PKCE S256)
CREATE TABLE oauth_codes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_hash       CHAR(64) NOT NULL,
    client_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    redirect_uri    TEXT NOT NULL,
    code_challenge  VARCHAR(128) NOT NULL,
    scope           VARCHAR(190) NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_oauth_code (code_hash),
    CONSTRAINT fk_oac_client FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_oac_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eine Zeile je Verbindung (Freigabe): Access- und Refresh-Token nur gehasht, beim Erneuern ausgetauscht
CREATE TABLE oauth_tokens (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id           INT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    household_id        INT UNSIGNED NOT NULL,
    scope               VARCHAR(190) NOT NULL,
    access_hash         CHAR(64) NOT NULL,
    access_expires_at   DATETIME NOT NULL,
    refresh_hash        CHAR(64) NOT NULL,
    refresh_expires_at  DATETIME NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at        DATETIME NULL,
    UNIQUE KEY uq_oauth_access (access_hash),
    UNIQUE KEY uq_oauth_refresh (refresh_hash),
    KEY idx_oauth_user (user_id),
    CONSTRAINT fk_oat_client FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_oat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_oat_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protokoll aller Änderungen über den MCP-Server
CREATE TABLE mcp_log (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id  INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NULL,
    client_name   VARCHAR(190) NULL,
    tool          VARCHAR(60) NOT NULL,
    summary       VARCHAR(255) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mcp_log (household_id, created_at),
    CONSTRAINT fk_mcl_household FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    CONSTRAINT fk_mcl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
