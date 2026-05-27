<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527200931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts, transactions, and audit_log tables for secure fund transfers';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            ! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id CHAR(36) NOT NULL,
                owner_name VARCHAR(255) NOT NULL,
                balance DECIMAL(19, 4) NOT NULL,
                currency CHAR(3) NOT NULL,
                version INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_accounts_uuid_before_insert
            BEFORE INSERT ON accounts
            FOR EACH ROW
            BEGIN
                IF NEW.id IS NULL OR NEW.id = '' THEN
                    SET NEW.id = uuid_generate_v4();
                END IF;
            END
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE transactions (
                id CHAR(36) NOT NULL,
                from_account_id CHAR(36) NOT NULL,
                to_account_id CHAR(36) NOT NULL,
                amount DECIMAL(19, 4) NOT NULL,
                currency CHAR(3) NOT NULL,
                status ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                idempotency_key VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE INDEX UNIQ_TRANSACTIONS_IDEMPOTENCY_KEY (idempotency_key),
                INDEX IDX_TRANSACTIONS_FROM_ACCOUNT_ID (from_account_id),
                INDEX IDX_TRANSACTIONS_TO_ACCOUNT_ID (to_account_id),
                INDEX IDX_TRANSACTIONS_STATUS (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_transactions_uuid_before_insert
            BEFORE INSERT ON transactions
            FOR EACH ROW
            BEGIN
                IF NEW.id IS NULL OR NEW.id = '' THEN
                    SET NEW.id = uuid_generate_v4();
                END IF;
            END
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                transaction_id CHAR(36) NOT NULL,
                event VARCHAR(255) NOT NULL,
                payload JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX IDX_AUDIT_LOG_TRANSACTION_ID (transaction_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_TRANSACTIONS_FROM_ACCOUNT FOREIGN KEY (from_account_id) REFERENCES accounts (id)');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_TRANSACTIONS_TO_ACCOUNT FOREIGN KEY (to_account_id) REFERENCES accounts (id)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_AUDIT_LOG_TRANSACTION FOREIGN KEY (transaction_id) REFERENCES transactions (id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            ! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform,
            'Migration can only be executed safely on MySQL.'
        );

        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_AUDIT_LOG_TRANSACTION');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_TRANSACTIONS_FROM_ACCOUNT');
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_TRANSACTIONS_TO_ACCOUNT');
        $this->addSql('DROP TRIGGER IF EXISTS trg_transactions_uuid_before_insert');
        $this->addSql('DROP TRIGGER IF EXISTS trg_accounts_uuid_before_insert');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE accounts');
    }
}
