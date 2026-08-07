<?php

namespace Repositories;

use PDO;

/**
 * Base Repository
 * Provides common database functionality for all repositories
 */
abstract class BaseRepository
{
    protected $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check if a table exists in the database
     */
    protected function tableExists(string $tableName): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute([$tableName]);
            return $stmt->fetch() !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Begin a database transaction
     */
    protected function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    /**
     * Commit a database transaction
     */
    protected function commit(): bool
    {
        return $this->db->commit();
    }

    /**
     * Rollback a database transaction
     */
    protected function rollBack(): bool
    {
        return $this->db->rollBack();
    }

    /**
     * Get the last inserted ID
     */
    protected function lastInsertId(): string
    {
        return $this->db->lastInsertId();
    }

    /**
     * Execute a raw query
     */
    protected function query(string $sql)
    {
        return $this->db->query($sql);
    }

    /**
     * Prepare a statement
     */
    public function prepare(string $sql)
    {
        return $this->db->prepare($sql);
    }

    /**
     * Execute a raw SQL statement
     */
    protected function exec(string $sql)
    {
        return $this->db->exec($sql);
    }
}
